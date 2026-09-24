
<?php
require_once __DIR__ . '/../../lib/db.php';

/*
 * Репозиторий учебного плана (новая схема БД uchet_lfpstu).
 *   TB_GdTcShip -> plan_hours, TB_TcShip -> study_stream,
 *   TB_Discipl  -> discipline, TB_Teacher -> teacher,
 *   TB_TimeType -> lesson_type, TB_Schedule -> schedule_lesson.
 */

// Направление выбора в модалке: все дисциплины группы в семестре
function repoPlanSubjects($conn, $groupId, $term) {
  return dbAll(
    $conn,
    "SELECT DISTINCT d.id AS id, d.name AS name
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN discipline d ON d.id = p.discipline_id AND d.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ?
     ORDER BY d.name",
    [(int)$groupId, (int)$term]
  );
}

// SQL-выражение сборки ФИО преподавателя (переиспользуется в запросах)
function planTeacherNameSql() {
  return "CONCAT_WS(' ', TRIM(t.surname), TRIM(t.first_name), TRIM(t.patronymic))";
}

// Преподаватели по конкретной дисциплине в плане группы
function repoPlanTeachers($conn, $groupId, $term, $subjectId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id
     WHERE st.group_id = ? AND p.term = ? AND p.discipline_id = ?
     ORDER BY name",
    [(int)$groupId, (int)$term, (int)$subjectId]
  );
}

// Все преподаватели группы в семестре (без привязки к дисциплине)
function repoPlanTeachersBase($conn, $groupId, $term) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id
     WHERE st.group_id = ? AND p.term = ?
     ORDER BY name",
    [(int)$groupId, (int)$term]
  );
}

// Типы занятий по связке дисциплина+преподаватель с планом и выполнением часов
function repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        lt.id AS id,
        lt.name AS name,
        (COALESCE(p.base_hours, 0) + COALESCE(p.var_hours, 0)) AS planned_hours,
        COALESCE((
          SELECT SUM(COALESCE(s.hours, 0))
          FROM schedule_lesson s
          WHERE s.is_deleted = 0
            AND s.group_id = st.group_id
            AND s.discipline_id = p.discipline_id
            AND s.teacher_id = p.teacher_id
            AND s.lesson_type_id = p.lesson_type_id
        ), 0) AS done_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN lesson_type lt ON lt.id = p.lesson_type_id
     WHERE st.group_id = ?
       AND p.term = ?
       AND p.discipline_id = ?
       AND p.teacher_id = ?
     ORDER BY lt.name",
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId]
  );
}

// Дисциплины, закреплённые за конкретным преподавателем в плане группы
function repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT d.id AS id, d.name AS name
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN discipline d ON d.id = p.discipline_id AND d.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ? AND p.teacher_id = ?
     ORDER BY d.name",
    [(int)$groupId, (int)$term, (int)$teacherId]
  );
}

// Год поступления группы (нужен для расчёта дат семестра)
function repoGetGroupYear($conn, $groupId) {
  return (int)dbScalar(
    $conn,
    "SELECT admission_year FROM student_group WHERE id = ? AND is_deleted = 0",
    [(int)$groupId],
    0
  );
}

// term -> [startDate, endDate] в формате YYYY-MM-DD
function repoTermDateRange($groupYear, $term) {
  $term = (int)$term;
  $groupYear = (int)$groupYear;
  if ($term <= 0 || $groupYear <= 0) return [null, null];

  $k = intdiv(($term - 1), 2);        // 0 для 1-2, 1 для 3-4, ...
  $base = $groupYear + $k;

  if ($term % 2 === 1) { // осень
    $start = sprintf("%04d-09-01", $base);
    $end   = sprintf("%04d-01-31", $base + 1);
  } else { // весна
    $start = sprintf("%04d-02-01", $base + 1);
    $end   = sprintf("%04d-06-30", $base + 1);
  }

  return [$start, $end];
}

// Запланированные часы учебным планом (базовая + вариативная части)
function repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $timeTypeId) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(p.base_hours, 0) + COALESCE(p.var_hours, 0)) AS planned
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     WHERE st.group_id = ?
       AND p.term = ?
       AND p.discipline_id = ?
       AND p.teacher_id = ?
       AND p.lesson_type_id = ?",
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId, (int)$timeTypeId],
    0.0
  );
}

// Уже поставленные часы в расписании в датах указанного семестра
function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $lessonTypeId, $termStart, $termEnd) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(s.hours, 0)) AS done
     FROM schedule_lesson s
     JOIN week w ON w.id = s.week_id
     WHERE s.is_deleted = 0
       AND s.group_id = ?
       AND s.discipline_id = ?
       AND s.teacher_id = ?
       AND s.lesson_type_id = ?
       AND w.start_date >= ?
       AND w.start_date <= ?",
    [(int)$groupId, (int)$subjectId, (int)$teacherId, (int)$lessonTypeId, $termStart, $termEnd],
    0.0
  );
}
