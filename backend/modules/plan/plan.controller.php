--- backend/modules/plan/plan.controller.php (原始)
<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/plan.repo.php';
require_once __DIR__ . '/plan.fallback.php';

/*
 * Контроллер учебного плана для модалки занятия.
 * ---------------------------------------------------------------------------
 * ГЛАВНОЕ ПРАВИЛО (исправление бага «список пуст»):
 *   поля «Преподаватель», «Дисциплина» и «Тип занятия» раньше брались ТОЛЬКО
 *   из таблицы plan_hours (учебный план). Если план на семестр не заполнен,
 *   списки были пустыми, хотя справочники заполнены через «Справочники».
 *   «Аудитория» работала, потому что всегда грузится из справочника room.
 *   Теперь: если запрос к плану вернул пусто -> подставляем данные из
 *   соответствующего справочника (discipline / teacher / lesson_type).
 */
function planController($conn, $method, $entity) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);

  $groupId = (int)getQuery('group_id', 0);
  $term    = (int)getQuery('term', 0);
  if ($groupId <= 0 || $term <= 0) errorJson('group_id and term required', 400);

  switch ($entity) {

    // Дисциплины группы в семестре; план пуст -> все дисциплины из справочника
    case 'plan_subjects': {
      $rows = repoPlanSubjects($conn, $groupId, $term);
      if (!$rows) $rows = repoAllDisciplines($conn);
      sendJson($rows);
      return;
    }

    // Преподаватели дисциплины; план пуст -> все преподаватели из справочника
    case 'plan_teachers': {
      $subjectId = (int)getQuery('subject_id', 0);
      if ($subjectId <= 0) errorJson('subject_id required', 400);

      $rows = repoPlanTeachers($conn, $groupId, $term, $subjectId);
      if (!$rows) $rows = repoAllTeachers($conn);
      sendJson($rows);
      return;
    }

    // Все преподаватели группы; план пуст -> весь справочник teacher
    case 'plan_teachers_base': {
      $rows = repoPlanTeachersBase($conn, $groupId, $term);
      if (!$rows) $rows = repoAllTeachers($conn);
      sendJson($rows);
      return;
    }

    // Преподаватели дисциплины (строго по плану; ровно один или пусто).
    // Пустой ответ = план не задан/неоднозначный — фронт покажет всех из teacher.
    case 'plan_subject_teachers': {
      $subjectId = (int)getQuery('subject_id', 0);
      if ($subjectId <= 0) errorJson('subject_id required', 400);
      sendJson(repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId));
      return;
    }

    // Типы занятий связки дисциплина+преподаватель;
    // план пуст -> типы занятий из справочника lesson_type
    // (сузим до тех, что вообще встречаются в плане, если план непустой).
    case 'plan_lesson_types': {
      $subjectId = (int)getQuery('subject_id', 0);
      $teacherId = (int)getQuery('teacher_id', 0);
      if ($subjectId <= 0 || $teacherId <= 0) {
        errorJson('subject_id and teacher_id required', 400);
      }

      $rows = repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId);
      if (!$rows) {
        $all = repoAllLessonTypes($conn);
        if (!repoPlanIsEmpty($conn)) {
          $all = filterRowsByIds($all, repoPlanLessonTypeIdsUsed($conn));
        }
        $rows = $all;
      }
      sendJson($rows);
      return;
    }

    // Дисциплины преподавателя; план пуст -> все дисциплины из справочника
    case 'plan_subjects_by_teacher': {
      $teacherId = (int)getQuery('teacher_id', 0);
      if ($teacherId <= 0) errorJson('teacher_id required', 400);

      $rows = repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId);
      if (!$rows) $rows = repoAllDisciplines($conn);
      sendJson($rows);
      return;
    }
  }

  errorJson('Unknown plan entity', 404);
}

+++ backend/modules/plan/plan.controller.php (修改后)
<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/plan.repo.php';
require_once __DIR__ . '/plan.fallback.php';

// Запасной вариант для модалки занятия: типы занятий из справочника lesson_type.
// Если план непустой — сужаем до типов, которые вообще встречаются в плане.
// В отличие от репозитория plan_hours здесь нет привязки к конкретной связке
// дисциплина+преподаватель, поэтому часов плана (planned_hours/done_hours)
// нет и счётчик «выполнено/план» не показывается — тип выбирается свободно,
// а нагрузка преподавателя ограничивается недельным лимитом teacher.max_hours.
function planFallbackLessonTypes(mysqli $conn): array {
  $all = repoAllLessonTypes($conn);
  if (!repoPlanIsEmpty($conn)) {
    $all = filterRowsByIds($all, repoPlanLessonTypeIdsUsed($conn));
  }
  return $all;
}

/*
 * Контроллер учебного плана для модалки занятия.
 * ---------------------------------------------------------------------------
 * ГЛАВНОЕ ПРАВИЛО (исправление бага «список пуст»):
 *   поля «Преподаватель», «Дисциплина» и «Тип занятия» раньше брались ТОЛЬКО
 *   из таблицы plan_hours (учебный план). Если план на семестр не заполнен,
 *   списки были пустыми, хотя справочники заполнены через «Справочники».
 *   «Аудитория» работала, потому что всегда грузится из справочника room.
 *   Теперь: если запрос к плану вернул пусто -> подставляем данные из
 *   соответствующего справочника (discipline / teacher / lesson_type).
 */
function planController($conn, $method, $entity) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);

  // Типы занятий по преподавателю (без привязки к дисциплине): нужны, когда
  // пользователь выбрал только преподавателя. Берём типы из его плана; если
  // их нет — запасной список из справочника.
  if ($entity === 'plan_teacher_lesson_types') {
    $teacherId = (int)getQuery('teacher_id', 0);
    if ($teacherId <= 0) errorJson('teacher_id required', 400);

    $rows = repoPlanTeacherLessonTypes(
      $conn,
      (int)getQuery('group_id', 0),
      (int)getQuery('term', 0),
      $teacherId
    );
    sendJson($rows ?: planFallbackLessonTypes($conn));
    return;
  }

  $groupId = (int)getQuery('group_id', 0);
  $term    = (int)getQuery('term', 0);
  if ($groupId <= 0 || $term <= 0) errorJson('group_id and term required', 400);

  switch ($entity) {

    // Дисциплины группы в семестре; план пуст -> все дисциплины из справочника
    case 'plan_subjects': {
      $rows = repoPlanSubjects($conn, $groupId, $term);
      if (!$rows) $rows = repoAllDisciplines($conn);
      sendJson($rows);
      return;
    }

    // Преподаватели дисциплины; план пуст -> все преподаватели из справочника
    case 'plan_teachers': {
      $subjectId = (int)getQuery('subject_id', 0);
      if ($subjectId <= 0) errorJson('subject_id required', 400);

      $rows = repoPlanTeachers($conn, $groupId, $term, $subjectId);
      if (!$rows) $rows = repoAllTeachers($conn);
      sendJson($rows);
      return;
    }

    // Все преподаватели группы; план пуст -> весь справочник teacher
    case 'plan_teachers_base': {
      $rows = repoPlanTeachersBase($conn, $groupId, $term);
      if (!$rows) $rows = repoAllTeachers($conn);
      sendJson($rows);
      return;
    }

    // Преподаватели дисциплины (строго по плану; ровно один или пусто).
    // Пустой ответ = план не задан/неоднозначный — фронт покажет всех из teacher.
    case 'plan_subject_teachers': {
      $subjectId = (int)getQuery('subject_id', 0);
      if ($subjectId <= 0) errorJson('subject_id required', 400);
      sendJson(repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId));
      return;
    }

    // Типы занятий связки дисциплина+преподаватель;
    // план пуст -> типы занятий из справочника lesson_type
    // (сузим до тех, что вообще встречаются в плане, если план непустой).
    case 'plan_lesson_types': {
      $subjectId = (int)getQuery('subject_id', 0);
      $teacherId = (int)getQuery('teacher_id', 0);
      if ($subjectId <= 0 || $teacherId <= 0) {
        errorJson('subject_id and teacher_id required', 400);
      }

      $rows = repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId);
      if (!$rows) {
        // Плана по этой связке нет -> показываем типы из справочника БЕЗ
        // счётчика часов (planned_hours/done_hours = null), чтобы в списке не
        // появлялось «Лекция (0/0)» и тип можно было выбрать.
        $rows = planFallbackLessonTypes($conn);
      }
      sendJson($rows);
      return;
    }

    // Дисциплины преподавателя; план пуст -> все дисциплины из справочника
    case 'plan_subjects_by_teacher': {
      $teacherId = (int)getQuery('teacher_id', 0);
      if ($teacherId <= 0) errorJson('teacher_id required', 400);

      $rows = repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId);
      if (!$rows) $rows = repoAllDisciplines($conn);
      sendJson($rows);
      return;
    }
  }

  errorJson('Unknown plan entity', 404);
}