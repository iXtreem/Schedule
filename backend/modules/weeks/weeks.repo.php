
<?php
require_once __DIR__ . '/../../lib/db.php';

// Учебные недели (таблица week — новая схема, бывшая TB_Weeks)
function repoGetWeeks($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        name,
        DATE_FORMAT(start_date, '%Y-%m-%d') AS start_date,
        DATE_FORMAT(end_date,   '%Y-%m-%d') AS end_date
     FROM week
     WHERE is_deleted = 0
     ORDER BY start_date DESC"
  );
}

// Добавление учебной недели
function repoAddWeek($conn, $name, $start, $end) {
  return dbInsert(
    $conn,
    "INSERT INTO week (name, start_date, end_date, is_deleted) VALUES (?, ?, ?, 0)",
    [$name, $start, $end]
  );
}
