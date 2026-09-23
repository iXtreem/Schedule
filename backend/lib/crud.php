<?php
/*
 * Универсальный CRUD для справочников (XAMPP / MySQLi, без ODBC).
 * Позволяет добавлять, редактировать и удалять группы,
 * преподавателей, дисциплины, аудитории, типы занятий и учебные
 * недели прямо из интерфейса — без phpMyAdmin.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/request.php';
require_once __DIR__ . '/response.php';

if (!function_exists('crudConfig')) {

  // Таблица последовательностей для id (ненулл-колонки не принимают NULL)
  function crudEnsureSeqTable(mysqli $conn) {
    static $ok = false;
    if ($ok) return;
    $conn->query(
      "CREATE TABLE IF NOT EXISTS TB_Sequence (
         SeqName VARCHAR(60)  NOT NULL PRIMARY KEY,
         LastVal INT          NOT NULL DEFAULT 0
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    if ($conn->error) throw new Exception('Не удалось создать таблицу счётчиков: ' . $conn->error);
    $ok = true;
  }

  function crudNextId(mysqli $conn, string $table, string $pk): int {
    crudEnsureSeqTable($conn);
    $max = (int)dbScalar($conn, "SELECT COALESCE(MAX(`$pk`), 0) FROM `$table`", [], 0);
    $cur = (int)dbScalar($conn, "SELECT LastVal FROM TB_Sequence WHERE SeqName = ?", [$table], 0);
    $next = max($max, $cur) + 1;
    dbExec($conn,
      "INSERT INTO TB_Sequence (SeqName, LastVal) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE LastVal = GREATEST(LastVal, VALUES(LastVal))",
      [$table, $next]);
    return $next;
  }

  function crudConfig() {
    return [
      'groups' => [
        'table'    => 'TB_Group',
        'pk'       => 'idGroup',
        'fields'   => ['name' => 'GroupName', 'short_name' => 'GroupShortName', 'year' => 'GroupYear', 'size' => 'GroupMaxContrBook'],
        'deleted'  => 'GroupDeleted',
        'required' => ['name'],
        'unique'   => ['GroupName'],
        'order'    => 'GroupShortName',
        'label'    => 'группа',
      ],
      'teachers' => [
        'table'    => 'TB_Teacher',
        'pk'       => 'idTeacher',
        'fields'   => ['surname' => 'TeacherSurname', 'first_name' => 'TeacherFirstName', 'last_name' => 'TeacherLastName'],
        'deleted'  => null,
        'required' => ['surname'],
        'unique'   => [],
        'order'    => 'TeacherSurname, TeacherFirstName',
        'label'    => 'преподаватель',
      ],
      'subjects' => [
        'table'    => 'TB_Discipl',
        'pk'       => 'idDiscipl',
        'fields'   => ['name' => 'DisciplName', 'short_name' => 'DisciplShortName'],
        'deleted'  => 'DisciplDeleted',
        'required' => ['name'],
        'unique'   => ['DisciplName'],
        'order'    => 'DisciplName',
        'label'    => 'дисциплина',
      ],
      'rooms' => [
        'table'    => 'TB_Room',
        'pk'       => 'idRoom',
        'fields'   => ['building' => 'Building', 'room_number' => 'RoomNumber', 'capacity' => 'Capacity'],
        'deleted'  => 'IsDeleted',
        'required' => ['building', 'room_number'],
        'unique'   => ['RoomNumber'],
        'order'    => 'Building, RoomNumber',
        'label'    => 'аудитория',
      ],
      'lesson_types' => [
        'table'    => 'TB_TimeType',
        'pk'       => 'idTimeType',
        'fields'   => ['name' => 'TimeTypeName', 'short_name' => 'TimeTypeShortName'],
        'deleted'  => null,
        'required' => ['name'],
        'unique'   => ['TimeTypeName'],
        'order'    => 'TimeTypeName',
        'label'    => 'тип занятия',
      ],
      'weeks' => [
        'table'    => 'TB_Weeks',
        'pk'       => 'idWeek',
        'fields'   => ['name' => 'WeekName', 'start_date' => 'StartDate', 'end_date' => 'EndDate'],
        'deleted'  => 'IsDeleted',
        'required' => ['name', 'start_date', 'end_date'],
        'unique'   => [],
        'order'    => 'StartDate DESC',
        'label'    => 'учебная неделя',
      ],
    ];
  }

  function crudValidateDate($value) {
    $text = trim((string)$value);
    $ts = strtotime($text);
    if ($ts === false) return false;
    return date('Y-m-d', $ts) === $text;
  }

  function crudCheckUnique(mysqli $conn, array $cfg, array $data, $excludeId = null) {
    foreach ($cfg['unique'] as $column) {
      $jsonKey = array_search($column, $cfg['fields'], true);
      if ($jsonKey === false || !array_key_exists($jsonKey, $data)) continue;
      $value = $data[$jsonKey];
      if ($value === null || $value === '') continue;

      $sql    = "SELECT COUNT(*) AS c FROM {$cfg['table']} WHERE `$column` = ?";
      $params = [$value];
      if ($cfg['deleted']) $sql .= " AND {$cfg['deleted']} = 0";
      if ($excludeId !== null) { $sql .= " AND {$cfg['pk']} <> ?"; $params[] = (int)$excludeId; }

      if ((int)dbScalar($conn, $sql, $params, 0) > 0) {
        errorJson('Такая запись уже есть (' . $cfg['label'] . '): ' . $value, 409);
      }
    }
  }

  // Точка входа: $entity = dict_groups | dict_teachers | dict_subjects | dict_rooms | dict_lesson_types | dict_weeks
  function crudController(mysqli $conn, $method, $entity) {
    try {
      $key = preg_replace('/^dict_/', '', (string)$entity);
      $cfg = isset(crudConfig()[$key]) ? crudConfig()[$key] : null;
      if (!$cfg) errorJson('Неизвестный справочник', 404);

      $whereDeleted = $cfg['deleted'] ? "WHERE {$cfg['deleted']} = 0" : '';

      // GET ?entity=dict_xxx -> список записей
      if ($method === 'GET') {
        $select = [];
        foreach ($cfg['fields'] as $json => $column) $select[] = "`$column` AS $json";
        sendJson(dbAll(
          $conn,
          "SELECT {$cfg['pk']} AS id, " . implode(', ', $select) . " FROM {$cfg['table']} $whereDeleted ORDER BY {$cfg['order']}"
        ));
      }

      if ($method === 'POST' || $method === 'PUT') {
        $body = getJsonBody();
        if (!is_array($body)) errorJson('Ожидается JSON-тело запроса', 400);

        $id   = (int)($body['id'] ?? getQuery('id', 0));
        $data = [];

        foreach ($cfg['fields'] as $json => $column) {
          if (!array_key_exists($json, $body)) continue;  // поле не прислали -> не трогаем
          $value = $body[$json];
          if (is_string($value)) $value = trim($value);
          if ($value === '') $value = null;

          if (in_array($json, ['year', 'size', 'capacity'], true) && $value !== null) {
            $value = ($value === 0 || $value === '0') ? null : (int)$value;
          }
          if (in_array($json, ['start_date', 'end_date'], true) && $value !== null) {
            if (!crudValidateDate($value)) errorJson('Неверный формат даты, ожидается ГГГГ-ММ-ДД', 400);
          }
          $data[$json] = $value;
        }

        $existing = null;
        if ($method === 'PUT') {
          if ($id <= 0) errorJson('Не передан id записи', 400);
          $sel = [];
          foreach ($cfg['fields'] as $json => $column) $sel[] = "`$column` AS $json";
          $existing = dbRow($conn, "SELECT {$cfg['pk']} AS id, " . implode(', ', $sel) . " FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
          if (!$existing) errorJson('Запись не найдена', 404);
        }

        foreach ($cfg['required'] as $json) {
          $val = array_key_exists($json, $data) ? $data[$json] : ($method === 'PUT' ? ($existing[$json] ?? null) : null);
          if ($val === null || $val === '') errorJson('Не заполнено обязательное поле', 400);
        }

        $startDate = $data['start_date'] ?? null;
        $endDate   = $data['end_date'] ?? null;
        if ($startDate && $endDate && strtotime($endDate) < strtotime($startDate)) {
          errorJson('Дата окончания раньше даты начала', 400);
        }

        crudCheckUnique($conn, $cfg, $data, $method === 'PUT' ? $id : null);

        if ($method === 'POST') {
          $cols   = [];
          $marks  = [];
          $params = [];
          foreach ($cfg['fields'] as $json => $column) {
            $cols[]   = "`$column`";
            $marks[]  = '?';
            $params[] = $data[$json] ?? null;
          }
          if ($cfg['deleted']) { $cols[] = $cfg['deleted']; $marks[] = '0'; }

          $newId = crudNextId($conn, $cfg['table'], $cfg['pk']);
          array_unshift($cols, $cfg['pk']);
          array_unshift($marks, '?');
          array_unshift($params, $newId);

          dbInsert($conn, "INSERT INTO {$cfg['table']} (" . implode(',', $cols) . ") VALUES (" . implode(',', $marks) . ")", $params);
          sendJson(['success' => true, 'id' => $newId], 201);
        }

        $set    = [];
        $params = [];
        foreach ($cfg['fields'] as $json => $column) {
          if (!array_key_exists($json, $data)) continue;
          $set[]    = "`$column` = ?";
          $params[] = $data[$json];
        }
        if (!$set) errorJson('Нет данных для изменения', 400);

        $params[] = $id;
        dbExec($conn, "UPDATE {$cfg['table']} SET " . implode(', ', $set) . " WHERE {$cfg['pk']} = ?", $params);
        sendJson(['success' => true, 'id' => $id]);
      }

      // DELETE ?entity=dict_xxx&id=N -> мягкое удаление (или физическое, если флага нет)
      if ($method === 'DELETE') {
        $id = (int)getQuery('id', 0);
        if ($id <= 0) errorJson('Не передан id записи', 400);

        if ($cfg['deleted']) {
          $found = dbRow($conn, "SELECT {$cfg['pk']} AS id FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
          if (!$found) errorJson('Запись не найдена', 404);
          dbExec($conn, "UPDATE {$cfg['table']} SET {$cfg['deleted']} = 1 WHERE {$cfg['pk']} = ?", [$id]);
        } else {
          $aff = dbExec($conn, "DELETE FROM {$cfg['table']} WHERE {$cfg['pk']} = ?", [$id]);
          if ($aff === 0) errorJson('Запись не найдена', 404);
        }
        sendJson(['success' => true]);
      }

      errorJson('Method not allowed', 405);
    } catch (Exception $e) {
      errorJson($e->getMessage(), 409);
    }
  }
}
