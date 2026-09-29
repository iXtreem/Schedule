--- backend/modules/plan/plan.repo.php (原始)
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

// Все живые преподаватели из справочника teacher — запасной вариант для
// модалки занятия, когда учебный план (plan_hours) пуст или неоднозначен.
// Так поле «Преподаватель» никогда не остаётся пустым (как и «Аудитория»).
function repoAllTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(max_hours, 36) AS max_hours
     FROM teacher
     WHERE is_deleted = 0
     ORDER BY surname, first_name"
  );
}

// Преподаватели по конкретной дисциплине в плане группы
function repoPlanTeachers($conn, $groupId, $term, $subjectId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
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
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ?
     ORDER BY name",
    [(int)$groupId, (int)$term]
  );
}

// ---------------------------------------------------------------------------
// Преподаватели дисциплины — источник списка для модалки занятия.
// Правило: если учебным планом (plan_hours) за дисциплиной группы закреплён
// ровно один преподаватель — возвращаем его; иначе возвращаем пустой список,
// и фронтенд подставляет всех преподавателей из справочника teacher
// (?entity=teachers), чтобы поле «Преподаватель» никогда не было пустым.
// ---------------------------------------------------------------------------
function repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId) {
  $rows = dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ? AND p.discipline_id = ?
     ORDER BY name",
    [(int)$groupId, (int)$term, (int)$subjectId]
  );

  return count($rows) === 1 ? $rows : [];
}

// Типы занятий, которые хоть где-то встречаются в плане (для сужения запаса)
function repoPlanLessonTypeIdsUsed(mysqli $conn): array {
  $rows = dbAll(
    $conn,
    "SELECT DISTINCT lesson_type_id AS id FROM plan_hours"
  );
  return array_map(static fn($r) => (int)$r['id'], $rows);
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

+++ backend/modules/plan/plan.repo.php (修改后)
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

// Все живые преподаватели из справочника teacher — запасной вариант для
// модалки занятия, когда учебный план (plan_hours) пуст или неоднозначен.
// Так поле «Преподаватель» никогда не остаётся пустым (как и «Аудитория»).
function repoAllTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(max_hours, 36) AS max_hours
     FROM teacher
     WHERE is_deleted = 0
     ORDER BY surname, first_name"
  );
}

// Преподаватели по конкретной дисциплине в плане группы
function repoPlanTeachers($conn, $groupId, $term, $subjectId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
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
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ?
     ORDER BY name",
    [(int)$groupId, (int)$term]
  );
}

// ---------------------------------------------------------------------------
// Преподаватели дисциплины — источник списка для модалки занятия.
// Правило: если учебным планом (plan_hours) за дисциплиной группы закреплён
// ровно один преподаватель — возвращаем его; иначе возвращаем пустой список,
// и фронтенд подставляет всех преподавателей из справочника teacher
// (?entity=teachers), чтобы поле «Преподаватель» никогда не было пустым.
// ---------------------------------------------------------------------------
function repoPlanSubjectTeachers($conn, $groupId, $term, $subjectId) {
  $rows = dbAll(
    $conn,
    "SELECT DISTINCT
        t.id AS id,
        " . planTeacherNameSql() . " AS name,
        COALESCE(t.max_hours, 36) AS max_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN teacher t ON t.id = p.teacher_id AND t.is_deleted = 0
     WHERE st.group_id = ? AND p.term = ? AND p.discipline_id = ?
     ORDER BY name",
    [(int)$groupId, (int)$term, (int)$subjectId]
  );

  return count($rows) === 1 ? $rows : [];
}

// Типы занятий, которые хоть где-то встречаются в плане (для сужения запаса)
function repoPlanLessonTypeIdsUsed(mysqli $conn): array {
  $rows = dbAll(
    $conn,
    "SELECT DISTINCT lesson_type_id AS id FROM plan_hours"
  );
  return array_map(static fn($r) => (int)$r['id'], $rows);
}

// Типы занятий по связке дисциплина+преподаватель с планом и выполнением часов.
// Если строки plan_hours существуют, но часов в плане нет (план = 0), поля
// planned_hours/done_hours возвращаем как NULL: фронтенд не рисует счётчик
// «Лекция (0/0)» и не блокирует выбор типа — лимит считается по часам
// преподавателя за неделю (teacher.max_hours).
function repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        lt.id AS id,
        lt.name AS name,
        CASE WHEN (COALESCE(p.base_hours, 0) + COALESCE(p.var_hours, 0)) <= 0
             THEN NULL
             ELSE (COALESCE(p.base_hours, 0) + COALESCE(p.var_hours, 0))
        END AS planned_hours,
        CASE WHEN (COALESCE(p.base_hours, 0) + COALESCE(p.var_hours, 0)) <= 0
             THEN NULL
             ELSE COALESCE((
               SELECT SUM(COALESCE(s.hours, 0))
               FROM schedule_lesson s
               WHERE s.is_deleted = 0
                 AND s.group_id = st.group_id
                 AND s.discipline_id = p.discipline_id
                 AND s.teacher_id = p.teacher_id
                 AND s.lesson_type_id = p.lesson_type_id
             ), 0)
        END AS done_hours
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

// Типы занятий преподавателя без привязки к дисциплине (когда в модалке выбран
// только преподаватель). Часы плана здесь усреднять некорректно, поэтому
// planned_hours/done_hours = NULL -> счётчика «выполнено/план» нет, тип
// выбирается свободно, а нагрузка ограничивается недельным лимитом max_hours.
function repoPlanTeacherLessonTypes($conn, $groupId, $term, $teacherId) {
  $whereGroup = '';
  $params = [(int)$teacherId];
  if ((int)$groupId > 0) {
    $whereGroup = ' AND st.group_id = ?';
    $params[] = (int)$groupId;
  }
  $whereTerm = '';
  if ((int)$term > 0) {
    $whereTerm = ' AND p.term = ?';
    $params[] = (int)$term;
  }

  return dbAll(
    $conn,
    "SELECT DISTINCT
        lt.id AS id,
        lt.name AS name,
        NULL AS planned_hours,
        NULL AS done_hours
     FROM plan_hours p
     JOIN study_stream st ON st.id = p.stream_id AND st.is_deleted = 0
     JOIN lesson_type lt ON lt.id = p.lesson_type_id
     WHERE p.teacher_id = ?{$whereGroup}{$whereTerm}
     ORDER BY lt.name",
    $params
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