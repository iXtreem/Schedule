<?php

function repoAddHoliday($conn, $date) {
  //insert if not exists
  $sql = "
    IF NOT EXISTS (SELECT 1 FROM TB_Holidays WHERE HolidayDate = ?)
    INSERT INTO TB_Holidays (HolidayDate) VALUES (?)
  ";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$date, $date])) throw new Exception(odbc_errormsg($conn));
}

function repoRemoveHoliday($conn, $date) {
  $sql = "DELETE FROM TB_Holidays WHERE HolidayDate = ?";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$date])) throw new Exception(odbc_errormsg($conn));
}

function repoGetHolidaysRange($conn, $start, $end) {
  $sql = "
    SELECT CONVERT(varchar(10), HolidayDate, 23) AS date
    FROM TB_Holidays
    WHERE HolidayDate >= ? AND HolidayDate <= ?
    ORDER BY HolidayDate
  ";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$start, $end])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = $row['date'];
  return $data;
}
