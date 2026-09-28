<?php
require_once __DIR__ . '/../../lib/db.php';

// Список преподавателей (таблица teacher — новая схема, бывшая TB_Teacher)
// ФИО собирается в одно поле name через CONCAT_WS
// max_hours — максимальная недельная нагрузка (по умолчанию 36, см. schema.sql);
// используется окном «Автозаполнение» и будущим генератором расписания.
function repoGetTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        CONCAT_WS(' ',
          TRIM(surname),
          TRIM(first_name),
          TRIM(patronymic)
        ) AS name,
        COALESCE(max_hours, 36) AS max_hours
     FROM teacher
     WHERE is_deleted = 0
     ORDER BY surname, first_name"
  );
}

// Сохранение лимитов часов: массив { id, max_hours }.
// Возвращает число обновлённых записей.
function repoSaveTeacherHours($conn, array $items) {
  $updated = 0;
  foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0);
    if ($id <= 0) continue;

    $hours = $item['max_hours'] ?? null;
    $hours = ($hours === '' || $hours === null) ? 36 : (float)$hours;
    if ($hours < 0)   $hours = 0;
    if ($hours > 999) $hours = 999;

    // DECIMAL(5,1): храним с точностью до десятых
    $hours = round($hours * 10) / 10;

    $aff = dbExec(
      $conn,
      "UPDATE teacher SET max_hours = ? WHERE id = ? AND is_deleted = 0",
      [$hours, $id]
    );
    $updated += $aff;
  }
  return $updated;
}