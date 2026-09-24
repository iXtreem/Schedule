
<?php
require_once __DIR__ . '/../../lib/db.php';

// Праздничные дни (таблица holiday — новая схема, бывшая TB_Holidays)

// Добавить праздник (если даты ещё нет)
function repoAddHoliday($conn, $date) {
  dbExec(
    $conn,
    "INSERT INTO holiday (holiday_date)
     SELECT ? FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM holiday WHERE holiday_date = ?)",
    [$date, $date]
  );
}

// Удалить праздник по дате
function repoRemoveHoliday($conn, $date) {
  dbExec($conn, "DELETE FROM holiday WHERE holiday_date = ?", [$date]);
}

// Список праздников в диапазоне дат (для подсветки коротких пар)
function repoGetHolidaysRange($conn, $start, $end) {
  $rows = dbAll(
    $conn,
    "SELECT DATE_FORMAT(holiday_date, '%Y-%m-%d') AS date
     FROM holiday
     WHERE holiday_date >= ? AND holiday_date <= ?
     ORDER BY holiday_date",
    [$start, $end]
  );

  $data = [];
  foreach ($rows as $row) $data[] = $row['date'];
  return $data;
}
