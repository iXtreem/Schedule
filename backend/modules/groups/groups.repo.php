<?php
require_once __DIR__ . '/../../lib/db.php';

function repoGetGroups($conn) {
  return dbAll(
    $conn,
    "SELECT
        idGroup AS id,
        GroupShortName AS short_name,
        GroupName AS name,
        GroupYear AS year,
        GroupMaxContrBook AS size
     FROM TB_Group
     WHERE GroupDeleted = 0
     ORDER BY GroupShortName"
  );
}
