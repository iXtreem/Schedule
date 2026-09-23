<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetSubjects($conn) {
  return dbAll(
    $conn,
    "SELECT
        idDiscipl AS id,
        DisciplName AS name,
        DisciplShortName AS short_name
     FROM TB_Discipl
     WHERE DisciplDeleted = 0
     ORDER BY DisciplName"
  );
}
