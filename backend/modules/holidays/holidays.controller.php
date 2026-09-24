
<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/holidays.repo.php';

// Формат даты строгий: YYYY-MM-DD (без strtotime — он «сглаживает» мусорные значения).
function isValidDateStr($date) {
  if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
  [$y, $m, $d] = array_map('intval', explode('-', $date));
  return checkdate($m, $d, $y);
}

// POST ?entity=holiday { date: "YYYY-MM-DD", kind: "off"|"reduced" }
//   kind=off     — красный день (полный выходной),
//   kind=reduced — жёлтый день (сокращённые пары).
// DELETE ?entity=holiday&date=YYYY-MM-DD
function holidayController($conn, $method) {
  if ($method === 'POST') {
    $body = getJsonBody();
    $date = $body['date'] ?? null;
    if (!$date) errorJson('date required', 400);

    // проверка формата; воскресенье разрешён — в календаре «Выходные дни»
    // можно отмечать любые дни, включая воскресенья
    if (!isValidDateStr($date)) errorJson('invalid date', 400);

    // вид отметки: только два допустимых значения, всё остальное — «off»
    $kind = ($body['kind'] ?? 'off') === 'reduced' ? 'reduced' : 'off';

    repoAddHoliday($conn, $date, $kind);
    sendJson(['success' => true]);
  }

  if ($method === 'DELETE') {
    $date = getQuery('date', null);
    if (!$date) errorJson('date required', 400);

    // тоже проверим дату (снимать отметку можно с любого дня, включая воскресенье)
    if (!isValidDateStr($date)) errorJson('invalid date', 400);

    repoRemoveHoliday($conn, $date);
    sendJson(['success' => true]);
  }

  errorJson('Method not allowed', 405);
}

// GET ?entity=holidays_range&start=YYYY-MM-DD&end=YYYY-MM-DD
function holidaysRangeController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);

  $start = getQuery('start', null);
  $end = getQuery('end', null);
  if (!$start || !$end) errorJson('start and end required', 400);

  sendJson(repoGetHolidaysRange($conn, $start, $end));
}
