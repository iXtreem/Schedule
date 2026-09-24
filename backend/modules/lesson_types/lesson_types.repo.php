
<?php
require_once __DIR__ . '/../../lib/db.php';

// Типы занятий (таблица lesson_type — новая схема, бывшая TB_TimeType)
function repoGetLessonTypes($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        name,
        short_name
     FROM lesson_type
     WHERE is_deleted = 0
     ORDER BY name"
  );
}
