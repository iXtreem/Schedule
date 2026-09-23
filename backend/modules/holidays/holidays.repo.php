<?php
require_once __DIR__ . '/../../lib/db.php';

function repoAddHoliday($conn, $date) {
  // вставляем только если такой даты ещё нет
  dbExec(
    $conn,
    "INSERT INTO TB_Holidays (HolidayDate)
     SELECT ? FROM DUAL
     WHERE NOT EXISTS (SELECT 1 FROM TB_Holidays WHERE HolidayDate = ?)",
    [$date, $date]
  );
}

function repoRemoveHoliday($conn, $date) {
  dbExec($conn, "DELETE FROM TB_Holidays WHERE HolidayDate = ?", [$date]);
}

function repoGetHolidaysRange($conn, $start, $end) {
  $rows = dbAll(
    $conn,
    "SELECT DATE_FORMAT(HolidayDate, '%Y-%m-%d') AS date
     FROM TB_Holidays
     WHERE HolidayDate >= ? AND HolidayDate <= ?
     ORDER BY HolidayDate",
    [$start, $end]
  );

  $data = [];
  foreach ($rows as $row) $data[] = $row['date'];
  return $data;
}
