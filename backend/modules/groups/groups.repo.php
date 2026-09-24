
<?php
require_once __DIR__ . '/../../lib/db.php';

// Список учебных групп (таблица student_group — новая схема, бывшая TB_Group)
function repoGetGroups($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        short_name,
        name,
        admission_year AS year,
        max_students   AS size
     FROM student_group
     WHERE is_deleted = 0
     ORDER BY short_name"
  );
}
