<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetSubjects($conn) {
  $sql = "
    SELECT
      idDiscipl AS id,
      DisciplName AS name,
      DisciplShortName AS short_name
    FROM TB_Discipl
    WHERE DisciplDeleted = 0
    ORDER BY DisciplName
  ";
  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) $data[] = convertToUtf8($row);
  return $data;
}
