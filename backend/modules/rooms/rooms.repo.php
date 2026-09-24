
<?php
require_once __DIR__ . '/../../lib/db.php';

// Список аудиторий (таблица room — новая схема, бывшая TB_Room)
// name формируется как «корпус-номер» (например, Главный-101)
function repoGetRooms($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        CONCAT(TRIM(building), '-', TRIM(room_number)) AS name,
        capacity
     FROM room
     WHERE is_deleted = 0
     ORDER BY building, room_number"
  );
}
