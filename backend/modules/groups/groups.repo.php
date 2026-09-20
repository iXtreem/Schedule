<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetGroups($conn) {
  $sql = "
    SELECT
      idGroup AS id,
      GroupShortName AS short_name,
      GroupName AS name,
      GroupYear AS year,
      GroupMaxContrBook AS size
    FROM TB_Group
    WHERE GroupDeleted = 0
    ORDER BY GroupShortName
  ";

  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) {
    $data[] = convertToUtf8($row);
  }
  return $data;
}
