
<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/weeks.repo.php';
require_once __DIR__ . '/weeks.generator.php'; // чистые функции генерации недель

// Проверка строки даты в формате YYYY-MM-DD
function isDateStr($s) {
  $d = DateTimeImmutable::createFromFormat('Y-m-d', $s);
  return $d && $d->format('Y-m-d') === $s;
}

/*
 * Автосоздание недель семестра в одну кнопку.
 * POST ?entity=weeks_generate
 *   { start_date: "YYYY-MM-DD", end_date: "YYYY-MM-DD" }
 * Генерирует учебные недели от начала до конца семестра с учётом выходных
 * (воскресенья по умолчанию + дни из таблицы holiday). Уже существующие
 * недели (те же понедельники) пропускаются — повторное нажатие безопасно.
 */
function weeksGenerateController($conn, $method) {
  if ($method !== 'POST') errorJson('Method not allowed', 405);

  $body  = getJsonBody();
  $start = trim($body['start_date'] ?? '');
  $end   = trim($body['end_date'] ?? '');

  if (!isDateStr($start) || !isDateStr($end)) errorJson('Укажите корректные даты начала и окончания семестра', 400);
  if ($end < $start) errorJson('Дата окончания раньше даты начала', 400);

  // Набор всех выходных дней (праздников) из БД
  $holidaysSet = repoGetAllHolidayDates($conn);

  // Строим список недель (чистая функция, без обращения к БД)
  $planned = buildSemesterWeeks($start, $end, $holidaysSet);

  /*
   * Синхронизируем с БД (все изменения — записываются в таблицу week):
   *  - добавляем отсутствующие недели;
   *  - обновляем границы существующих недель, если генератор посчитал их
   *    иначе (например, добавили праздник — неделя должна «оборваться»);
   *  - удаляем (is_deleted = 1) недели в диапазоне семестра, которые больше
   *    не попадают в план (стали полностью выходными или ушли за границы).
   */
  $created = 0; // сколько недель создано
  $updated = 0; // сколько границ скорректировано
  $removed = 0; // сколько «лишних» недель помечено удалёнными

  // Индекс существующих недель по дате начала.
  $existingRows = dbAll(
    $conn,
    "SELECT id, name,
            DATE_FORMAT(start_date, '%Y-%m-%d') AS start_date,
            DATE_FORMAT(end_date,   '%Y-%m-%d') AS end_date
     FROM week WHERE is_deleted = 0"
  );
  $byStart = [];
  foreach ($existingRows as $row) $byStart[$row['start_date']] = $row;

  $plannedStarts = [];
  foreach ($planned as $w) {
    $plannedStarts[$w['start_date']] = true;
    $cur = $byStart[$w['start_date']] ?? null;

    if (!$cur) {
      // Такой недели ещё нет — создаём.
      repoInsertWeekRaw($conn, $w['name'], $w['start_date'], $w['end_date']);
      $created++;
    } elseif ($cur['end_date'] !== $w['end_date']) {
      // Границы разошлись — подтягиваем неделю к актуальному плану.
      repoUpdateWeekRange($conn, (int)$cur['id'], $cur['name'], $w['start_date'], $w['end_date']);
      $updated++;
    }
  }

  // Удаляем только те недели, которые попадают в диапазон семестра и при этом
  // отсутствуют в плане. Недели вне диапазона (другие семестры) не трогаем.
  foreach ($existingRows as $row) {
    if (isset($plannedStarts[$row['start_date']])) continue; // в плане — оставляем
    if ($row['end_date'] < $start or $row['start_date'] > $end) continue; // чужой период
    dbExec($conn, "UPDATE week SET is_deleted = 1 WHERE id = ?", [(int)$row['id']]);
    $removed++;
  }

  sendJson([
    'success' => true,
    'created' => $created,
    'updated' => $updated,
    'removed' => $removed,
  ]);
}

function weeksController($conn, $method) {
  if ($method === 'GET') {
    sendJson(repoGetWeeks($conn));
  }

  if ($method === 'POST') {
    $body = getJsonBody();
    if (!$body) errorJson('Invalid JSON body', 400);

    $name = trim($body['name'] ?? '');
    $start = $body['start_date'] ?? '';
    $end = $body['end_date'] ?? '';
    if (!$name || !$start || !$end) errorJson('name/start_date/end_date required', 400);

    $id = repoAddWeek($conn, $name, $start, $end);
    sendJson(['success' => true, 'id' => $id], 201);
  }

  errorJson('Method not allowed', 405);
}
