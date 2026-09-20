<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/holidays.repo.php';

// POST ?entity=holiday { date: "YYYY-MM-DD" }
// DELETE ?entity=holiday&date=YYYY-MM-DD
function holidayController($conn, $method) {
  if ($method === 'POST') {
    $body = getJsonBody();
    $date = $body['date'] ?? null;
    if (!$date) errorJson('date required', 400);

    //проверка формата и запрет воскресенья
    $ts = strtotime($date);
    if ($ts === false) errorJson('invalid date', 400);
    if ((int)date('w', $ts) === 0) { // 0 = Sunday
      errorJson('Sunday cannot be a holiday', 400);
    }

    repoAddHoliday($conn, $date);
    sendJson(['success' => true]);
  }

  if ($method === 'DELETE') {
    $date = getQuery('date', null);
    if (!$date) errorJson('date required', 400);

    //тоже проверим дату
    $ts = strtotime($date);
    if ($ts === false) errorJson('invalid date', 400);
    if ((int)date('w', $ts) === 0) {
      errorJson('Sunday cannot be a holiday', 400);
    }

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
