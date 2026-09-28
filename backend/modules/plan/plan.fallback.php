<?php
/*
 * Запасные списки для модалки занятия (режим «без учебного плана»).
 * ---------------------------------------------------------------------------
 * Проблема, которую это решает: список преподавателей и типов занятий в окне
 * добавления занятия брался ТОЛЬКО из учебного плана (таблица plan_hours).
 * Пользователь заполняет справочники (преподаватели, дисциплины, типы), но
 * план на семестр не заполнен -> списки «— выбери преподавателя —» и
 * «— выбери тип —» остаются пустыми, хотя «Аудитория» работает, потому что
 * всегда грузится из справочника room.
 *
 * Правило: если запрос к учебному плану вернул пусто, подставляем данные из
 * справочников, чтобы поля модалки никогда не были пустыми.
 */

require_once __DIR__ . '/../../lib/db.php';

// Дисциплины из справочника discipline
function repoAllDisciplines(mysqli $conn): array {
  return dbAll(
    $conn,
    "SELECT id, name FROM discipline WHERE is_deleted = 0 ORDER BY name"
  );
}

// Типы занятий из справочника lesson_type
function repoAllLessonTypes(mysqli $conn): array {
  return dbAll(
    $conn,
    "SELECT id, name FROM lesson_type WHERE is_deleted = 0 ORDER BY name"
  );
}

// План пуст? (нет ни одной живой строки plan_hours)
function repoPlanIsEmpty(mysqli $conn): bool {
  $n = dbScalar(
    $conn,
    "SELECT COUNT(*) FROM plan_hours",
    [],
    0
  );
  return (int)$n === 0;
}

// Фильтрация строк вида [{id, name, ...}] по числовому полю без SQL-инъекций.
// Используется, когда нужно сузить запасной список из справочника
// (например, показать только те типы занятий, которые уже встречаются в плане).
function filterRowsByIds(array $rows, array $ids): array {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  if (!$ids) return [];

  $in = implode(',', array_fill(0, count($ids), '?'));
  $out = [];
  foreach ($rows as $row) {
    if (in_array((int)($row['id'] ?? 0), $ids, true)) $out[] = $row;
  }
  return $out;
}