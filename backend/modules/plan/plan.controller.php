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

      // Есть ли дисциплина в базе «Учебный план» хотя бы для одной группы
      // (по любому семестру). Нужно, чтобы отличать «данных учебного плана
      // нет вообще» (тогда не красим) от «для этой группы дисциплины в
      // плане нет» — во втором случае все типы показываем красными.
      $currHasDiscipline = false;
      try {
        @require_once __DIR__ . '/../curriculum/curriculum.repo.php';
        if (function_exists('repoCurrDisciplineInPlan')) {
          $currHasDiscipline = repoCurrDisciplineInPlan(planDB($conn), $subjectId);
        }
      } catch (Throwable $e) {
        error_log('currHasDiscipline check skipped: ' . $e->getMessage());
      }

      $rows = repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId);
      if (!$rows) {
        // Плана по этой связке нет -> показываем типы из справочника БЕЗ
        // счётчика часов (planned_hours/done_hours = null), чтобы в списке не
        // появлялось «Лекция (0/0)» и тип можно было выбрать.
        $rows = planFallbackLessonTypes($conn);
      }

      // Дополняем/заменяем часы отдельной базой «Учебный план»
      // (uchetlfpstu_plan): там часы распределены по видам учебной
      // деятельности для группы в семестре — именно они являются главным
      // ограничением при ручном редактировании. Ошибки недоступной базы
      // плана не блокируют обычную работу программы.
      try {
        @require_once __DIR__ . '/../curriculum/curriculum.repo.php';
        if (function_exists('repoCurrPlannedDoneByTypes')) {
          $planConn = planDB($conn);
          $currMap = repoCurrPlannedDoneByTypes($planConn, $conn, $groupId, $term, $subjectId);
          if ($currMap) {
            $byId = [];
            foreach ($rows as $r) $byId[(int)$r['id']] = $r;

            // типы, которых нет в текущем списке (например, fallback-справочник
            // сузился до типов plan_hours), но которые есть в учебном плане
            foreach ($currMap as $tid => $_) {
              if (!isset($byId[$tid])) {
                $byId[$tid] = [
                  'id' => $tid,
                  'name' => dbScalar($conn, "SELECT name FROM lesson_type WHERE id = ?", [$tid], "Тип #$tid"),
                ];
              }
            }

            foreach ($byId as $tid => &$r) {
              if (isset($currMap[$tid])) {
                $r['planned_hours'] = $currMap[$tid]['planned'];
                $r['done_hours']    = $currMap[$tid]['done'];
                // дисциплина с этим видом деятельности есть в плане именно
                // для ЭТОЙ группы (учтён курс группы) -> зелёный в модалке;
                // false -> красный («не предусмотрено учебным планом»)
                $r['in_group_plan'] = !empty($currMap[$tid]['inGroupPlan']);
              } else {
                // типа нет в учебном плане -> обнуляем счётчик старого плана,
                // чтобы не путать пользователя устаревшими цифрами
                $r['planned_hours'] = null;
                $r['done_hours']    = null;
                $r['in_group_plan'] = false;
              }
            }
            unset($r);
            $rows = array_values($byId);
          }
        }
      } catch (Throwable $e) {
        error_log('curriculum lesson types merge skipped: ' . $e->getMessage());
      }

      /* Финальная раскраска типов по учебному плану (фронт читает
       * in_group_plan):
       *  - дисциплина вообще не заведена в «Учебном плане» -> null
       *    (не красим ни зелёным, ни красным — плана нет);
       *  - дисциплина есть в плане, но не для этой группы/курса или типа
       *    нет среди видов деятельности -> false (красный);
       *  - тип предусмотрен планом этой группы -> true (зелёный). */
      if ($currHasDiscipline) {
        foreach ($rows as &$r) {
          if (!array_key_exists('in_group_plan', $r)) {
            $r['in_group_plan'] = false;
          }
        }
        unset($r);
      } else {
        foreach ($rows as &$r) {
          $r['in_group_plan'] = null;
        }
        unset($r);
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