<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetLessonTypes($conn) {
  return dbAll(
    $conn,
    "SELECT
        idTimeType AS id,
        TimeTypeName AS name,
        TimeTypeShortName AS short_name
     FROM TB_TimeType
     ORDER BY TimeTypeName"
  );
}
