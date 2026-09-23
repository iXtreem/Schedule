<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetRooms($conn) {
  return dbAll(
    $conn,
    "SELECT
        idRoom AS id,
        CONCAT(TRIM(Building), '-', TRIM(RoomNumber)) AS name,
        Capacity AS capacity
     FROM TB_Room
     WHERE IsDeleted = 0
     ORDER BY Building, RoomNumber"
  );
}
