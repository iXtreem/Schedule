<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetWeeks($conn) {
  $sql = "
    SELECT
      idWeek AS id,
      WeekName AS name,
      CONVERT(varchar(10), StartDate, 23) AS start_date,
      CONVERT(varchar(10), EndDate, 23) AS end_date
    FROM TB_Weeks
    WHERE IsDeleted = 0
    ORDER BY StartDate DESC
  ";

  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) {
    $data[] = convertToUtf8($row);
  }
  return $data;
}

function repoAddWeek($conn, $name, $start, $end) {
  $sql = "INSERT INTO TB_Weeks(WeekName, StartDate, EndDate, IsDeleted) VALUES (?, ?, ?, 0)";
  $stmt = odbc_prepare($conn, $sql);
  if (!$stmt) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($stmt, [$name, $start, $end])) throw new Exception(odbc_errormsg($conn));

  // получить последний id (для SQL Server)
  $res = odbc_exec($conn, "SELECT SCOPE_IDENTITY() AS id");
  $row = odbc_fetch_array($res);
  return (int)$row['id'];
}
