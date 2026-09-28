<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/plan.repo.php';

function planController($conn, $method, $entity) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);

  $groupId = (int)getQuery('group_id', 0);
  $term = (int)getQuery('term', 0);
  if ($groupId <= 0 || $term <= 0) errorJson('group_id and term required', 400);

  if ($entity === 'plan_subjects') {
    sendJson(repoPlanSubjects($conn, $groupId, $term));
  }

  if ($entity === 'plan_teachers') {
    $subjectId = (int)getQuery('subject_id', 0);
    if ($subjectId <= 0) errorJson('subject_id required', 400);

    // ИСПРАВЛЕНИЕ «пустого списка преподавателей» в окне добавления занятия:
    // раньше список брались ТОЛЬКО из учебного плана (plan_hours). Если план
    // на семестр ещё не заполнен (или дисциплину вели несколько препода-
    // вателей), поле «Преподаватель» было пустым — в отличие от «Аудитории»,
    // которая всегда грузится из справочника room.
    // Теперь: план пуст -> показываем всех преподавателей из справочника.
    $rows = repoPlanTeachers($conn, $groupId, $term, $subjectId);
    if (!$rows) $rows = repoAllTeachers($conn);
    sendJson($rows);
  }

  if ($entity === 'plan_lesson_types') {
    $subjectId = (int)getQuery('subject_id', 0);
    $teacherId = (int)getQuery('teacher_id', 0);
    if ($subjectId <= 0 || $teacherId <= 0) errorJson('subject_id and teacher_id required', 400);
    sendJson(repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId));
  }

  if ($entity === 'plan_subject_teachers') {
    $subjectId = (int)getQuery('subject_id', 0);
    if ($subjectId <= 0) errorJson('subject_id required', 400);
    sendJson(repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId));
  }

  // Модалка занятия: преподаватели дисциплины.
  // Пустой ответ = план не задан/неоднозначный — фронт покажет всех из teacher.
  if ($entity === 'plan_subject_teachers') {
    $subjectId = (int)getQuery('subject_id', 0);
    if ($subjectId <= 0) errorJson('subject_id required', 400);
    sendJson(repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId));
  }
  if ($entity === 'plan_teachers_base') {
    // Тот же запасной вариант, что и для plan_teachers: если план группы
    // пуст — показываем всех преподавателей из справочника teacher.
    $rows = repoPlanTeachersBase($conn, $groupId, $term);
    if (!$rows) $rows = repoAllTeachers($conn);
    sendJson($rows);
  }

  if ($entity === 'plan_subjects_by_teacher') {
    $teacherId = (int)getQuery('teacher_id', 0);
    if ($teacherId <= 0) errorJson('teacher_id required', 400);
    sendJson(repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId));
  }


  errorJson('Unknown plan entity', 404);
}