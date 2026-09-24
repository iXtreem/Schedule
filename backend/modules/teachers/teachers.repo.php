
<?php
require_once __DIR__ . '/../../lib/db.php';

// Список преподавателей (таблица teacher — новая схема, бывшая TB_Teacher)
// ФИО собирается в одно поле name через CONCAT_WS
function repoGetTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        CONCAT_WS(' ',
          TRIM(surname),
          TRIM(first_name),
          TRIM(patronymic)
        ) AS name
     FROM teacher
     WHERE is_deleted = 0
     ORDER BY surname, first_name"
  );
}
