<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoGetTeachers($conn) {
  $sql = "
    SELECT
      idTeacher AS id,
      LTRIM(RTRIM(TeacherSurname)) + ' ' +
      LTRIM(RTRIM(TeacherFirstName)) + ' ' +
      LTRIM(RTRIM(TeacherLastName)) AS name
    FROM TB_Teacher
    ORDER BY TeacherSurname, TeacherFirstName
  ";
  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) $data[] = convertToUtf8($row);
  return $data;
}
