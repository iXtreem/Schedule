<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetLessonTypes($conn) {
  $sql = "
    SELECT
      idTimeType AS id,
      TimeTypeName AS name,
      TimeTypeShortName AS short_name
    FROM TB_TimeType
    ORDER BY TimeTypeName
  ";
  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) $data[] = convertToUtf8($row);
  return $data;
}
