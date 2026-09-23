<?php
/*
 * Настройка расписания звонков (времени пар) для XAMPP/MySQLi.
 * Значения по умолчанию берутся из js/LoadFromBD/bd.js, если в базе ничего не сохраняли.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';

if (!defined('BELL_DEFAULTS')) {
  define('BELL_DEFAULTS', [
    'workday' => [
      1 => '8:00-8:45<br>8:50-9:30',
      2 => '10:00-10:45<br>10:50-11:30',
      3 => '12:00-12:45<br>12:50-13:30',
      4 => '14:00-14:45<br>14:50-15:30',
      5 => '15:50-16:35<br>16:40-17:20',
      6 => '17:40-18:25<br>18:30-19:10',
      7 => '19:20-20:05<br>20:10-20:50',
    ],
    'sunday' => [
      1 => '8:00-8:45<br>8:50-9:30',
      2 => '9:40-10:25<br>10:30-11:10',
      3 => '11:20-12:05<br>12:10-12:50',
      4 => '13:00-13:45<br>13:50-14:30',
      5 => '14:40-15:25<br>15:30-16:15',
      6 => '16:25-17:10<br>17:15-18:00',
      7 => '18:10-18:55<br>19:00-19:45',
    ],
    'holiday' => [
      1 => '8:00-9:00',   2 => '9:10-10:10',  3 => '10:30-11:30', 4 => '11:50-12:50',
      5 => '13:00-14:00', 6 => '14:10-15:10', 7 => '15:20-16:20', 8 => '16:30-17:30',
      9 => '17:40-18:40', 10 => '18:50-19:50',
    ],
  ]);
}

if (!function_exists('bellEnsureTable')) {

  function bellEnsureTable(mysqli $conn) {
    static $ok = false;
    if ($ok) return;
    $conn->query(
      "CREATE TABLE IF NOT EXISTS TB_BellSchedule (
         idBell    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
         DayType   VARCHAR(20) NOT NULL,
         SlotNum   INT NOT NULL,
         TimeText  VARCHAR(200) NOT NULL,
         UNIQUE KEY uq_bell (DayType, SlotNum)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($conn->error) throw new Exception('Не удалось создать таблицу настроек времени пар: ' . $conn->error);
    $ok = true;
  }

  // Полный набор времен слотов: значения из БД поверх значений по умолчанию
  function bellGetAll(mysqli $conn) {
    bellEnsureTable($conn);
    $out = [];
    foreach (BELL_DEFAULTS as $type => $slots) {
      $out[$type] = [];
      foreach ($slots as $num => $text) $out[$type][$num] = $text;
    }
    foreach (dbAll($conn, "SELECT DayType AS t, SlotNum AS n, TimeText AS text FROM TB_BellSchedule") as $row) {
      $t = $row['t'];
      $n = (int)$row['n'];
      if (!isset($out[$t])) $out[$t] = [];
      $out[$t][$n] = $row['text'];
    }
    foreach ($out as $t => $slots) ksort($out[$t]);
    return $out;
  }

  function bellNormalizeText($value) {
    $text = trim((string)$value);
    // разрешаем перевод строки только в виде <br>
    $text = str_ireplace(['<br/>', '<br />', '< BR>', '\n', '\r'], '<br>', $text);
    $text = preg_replace('/<br>(\s*<br>)+/', '<br>', $text);
    return trim($text);
  }

  function bellController(mysqli $conn, $method) {
    try {
      if ($method === 'GET') {
        sendJson(bellGetAll($conn));
      }

      if ($method === 'POST' || $method === 'PUT') {
        $body = getJsonBody();
        if (!is_array($body)) errorJson('Ожидается JSON-тело запроса', 400);

        $allowedTypes = array_keys(BELL_DEFAULTS);
        $maxSlots     = ['workday' => 7, 'sunday' => 7, 'holiday' => 10];

        $incoming = [];
        foreach ($allowedTypes as $type) {
          $rows = $body[$type] ?? null;
          if (!is_array($rows)) continue;
          foreach ($rows as $slot => $text) {
            $num  = (int)(is_array($text) ? ($text['slot'] ?? 0) : $slot);
            $val  = is_array($text) ? ($text['time'] ?? '') : $text;
            $val  = bellNormalizeText($val);
            if ($num < 1 || $num > $maxSlots[$type]) errorJson("Неверный номер пары для типа дня «{$type}»", 400);
            if ($val === '') { $incoming[$type][$num] = null; continue; }
            if (mb_strlen($val) > 200) errorJson('Слишком длинное описание времени пары', 400);
            $incoming[$type][$num] = $val;
          }
        }

        bellEnsureTable($conn);
        $saved = 0;
        foreach ($incoming as $type => $slots) {
          foreach ($slots as $num => $val) {
            if ($val === null) {
              dbExec($conn, "DELETE FROM TB_BellSchedule WHERE DayType = ? AND SlotNum = ?", [$type, $num]);
            } else {
              dbExec($conn,
                "INSERT INTO TB_BellSchedule (DayType, SlotNum, TimeText) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE TimeText = VALUES(TimeText)",
                [$type, $num, $val]);
            }
            $saved++;
          }
        }

        sendJson(['success' => true, 'saved' => $saved, 'data' => bellGetAll($conn)]);
      }

      // DELETE ?entity=bell_schedule&type=workday&slot=7 — вернуть значение по умолчанию
      if ($method === 'DELETE') {
        $type = (string)getQuery('type', '');
        $slot = (int)getQuery('slot', 0);
        if (!in_array($type, array_keys(BELL_DEFAULTS), true) || $slot <= 0) errorJson('type/slot required', 400);
        dbExec($conn, "DELETE FROM TB_BellSchedule WHERE DayType = ? AND SlotNum = ?", [$type, $slot]);
        sendJson(['success' => true, 'data' => bellGetAll($conn)]);
      }

      errorJson('Method not allowed', 405);
    } catch (Exception $e) {
      errorJson($e->getMessage(), 409);
    }
  }
}
