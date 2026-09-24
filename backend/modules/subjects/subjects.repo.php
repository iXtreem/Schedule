
<?php
require_once __DIR__ . '/../../lib/db.php';

// Список дисциплин (таблица discipline — новая схема, бывшая TB_Discipl)
function repoGetSubjects($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        name,
        short_name
     FROM discipline
     WHERE is_deleted = 0
     ORDER BY name"
  );
}
