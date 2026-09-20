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
    sendJson(repoPlanTeachers($conn, $groupId, $term, $subjectId));
  }

  if ($entity === 'plan_lesson_types') {
    $subjectId = (int)getQuery('subject_id', 0);
    $teacherId = (int)getQuery('teacher_id', 0);
    if ($subjectId <= 0 || $teacherId <= 0) errorJson('subject_id and teacher_id required', 400);
    sendJson(repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId));
  }

    if ($entity === 'plan_teachers_base') {
    sendJson(repoPlanTeachersBase($conn, $groupId, $term));
  }

  if ($entity === 'plan_subjects_by_teacher') {
    $teacherId = (int)getQuery('teacher_id', 0);
    if ($teacherId <= 0) errorJson('teacher_id required', 400);
    sendJson(repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId));
  }


  errorJson('Unknown plan entity', 404);
}
