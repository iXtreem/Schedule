<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetWeeks($conn) {
  return dbAll(
    $conn,
    "SELECT
        idWeek AS id,
        WeekName AS name,
        DATE_FORMAT(StartDate, '%Y-%m-%d') AS start_date,
        DATE_FORMAT(EndDate,   '%Y-%m-%d') AS end_date
     FROM TB_Weeks
     WHERE IsDeleted = 0
     ORDER BY StartDate DESC"
  );
}

function repoAddWeek($conn, $name, $start, $end) {
  return dbInsert(
    $conn,
    "INSERT INTO TB_Weeks (WeekName, StartDate, EndDate, IsDeleted) VALUES (?, ?, ?, 0)",
    [$name, $start, $end]
  );
}
