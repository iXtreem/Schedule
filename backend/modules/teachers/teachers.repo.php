<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        idTeacher AS id,
        CONCAT_WS(' ',
          TRIM(TeacherSurname),
          TRIM(TeacherFirstName),
          TRIM(TeacherLastName)
        ) AS name
     FROM TB_Teacher
     ORDER BY TeacherSurname, TeacherFirstName"
  );
}
