<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetRooms($conn) {
  $sql = "
    SELECT
      idRoom AS id,
      (RTRIM(Building) + '-' + RTRIM(RoomNumber)) AS name,
      Capacity AS capacity
    FROM TB_Room
    WHERE IsDeleted = 0
    ORDER BY Building, RoomNumber
  ";

  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) $data[] = convertToUtf8($row);
  return $data;
}

