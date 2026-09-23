<?php
require_once __DIR__ . '/../../lib/db.php';

//направление выбора в модалке все предметы
function repoPlanSubjects($conn, $groupId, $term) {
  return dbAll(
    $conn,
    "SELECT DISTINCT d.idDiscipl AS id, d.DisciplName AS name
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     JOIN TB_Discipl d ON d.idDiscipl = p.idDiscipl AND d.DisciplDeleted = 0
     WHERE tc.idGroup = ? AND p.GdTcShipTerm = ?
     ORDER BY d.DisciplName",
    [(int)$groupId, (int)$term]
  );
}

function planTeacherNameSql() {
  return "CONCAT_WS(' ', TRIM(t.TeacherSurname), TRIM(t.TeacherFirstName), TRIM(t.TeacherLastName))";
}

//препода по предмету
function repoPlanTeachers($conn, $groupId, $term, $subjectId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.idTeacher AS id,
        " . planTeacherNameSql() . " AS name
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     JOIN TB_Teacher t ON t.idTeacher = p.idTeacher
     WHERE tc.idGroup = ? AND p.GdTcShipTerm = ? AND p.idDiscipl = ?
     ORDER BY name",
    [(int)$groupId, (int)$term, (int)$subjectId]
  );
}

function repoPlanTeachersBase($conn, $groupId, $term) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        t.idTeacher AS id,
        " . planTeacherNameSql() . " AS name
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     JOIN TB_Teacher t ON t.idTeacher = p.idTeacher
     WHERE tc.idGroup = ? AND p.GdTcShipTerm = ?
     ORDER BY name",
    [(int)$groupId, (int)$term]
  );
}

//типы
function repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT
        tt.idTimeType AS id,
        tt.TimeTypeName AS name,
        (COALESCE(p.GdTcShipBHour, 0) + COALESCE(p.GdTcShipVHour, 0)) AS planned_hours,
        COALESCE((
          SELECT SUM(COALESCE(s.Hours, 0))
          FROM TB_Schedule s
          WHERE s.IsDeleted = 0
            AND s.idGroup = tc.idGroup
            AND s.idDiscipl = p.idDiscipl
            AND s.idTeacher = p.idTeacher
            AND s.idLessonType = p.idTimeType
        ), 0) AS done_hours
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     JOIN TB_TimeType tt ON tt.idTimeType = p.idTimeType
     WHERE tc.idGroup = ?
       AND p.GdTcShipTerm = ?
       AND p.idDiscipl = ?
       AND p.idTeacher = ?
     ORDER BY tt.TimeTypeName",
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId]
  );
}

//предметы по преподавателю
function repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId) {
  return dbAll(
    $conn,
    "SELECT DISTINCT d.idDiscipl AS id, d.DisciplName AS name
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     JOIN TB_Discipl d ON d.idDiscipl = p.idDiscipl AND d.DisciplDeleted = 0
     WHERE tc.idGroup = ? AND p.GdTcShipTerm = ? AND p.idTeacher = ?
     ORDER BY d.DisciplName",
    [(int)$groupId, (int)$term, (int)$teacherId]
  );
}

//Расчет часов
function repoGetGroupYear($conn, $groupId) {
  return (int)dbScalar(
    $conn,
    "SELECT GroupYear FROM TB_Group WHERE idGroup = ? AND GroupDeleted = 0",
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

function repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $timeTypeId) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(p.GdTcShipBHour, 0) + COALESCE(p.GdTcShipVHour, 0)) AS planned
     FROM TB_GdTcShip p
     JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
     WHERE tc.idGroup = ?
       AND p.GdTcShipTerm = ?
       AND p.idDiscipl = ?
       AND p.idTeacher = ?
       AND p.idTimeType = ?",
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId, (int)$timeTypeId],
    0.0
  );
}

function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $lessonTypeId, $termStart, $termEnd) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(s.Hours, 0)) AS done
     FROM TB_Schedule s
     JOIN TB_Weeks w ON w.idWeek = s.idWeek
     WHERE s.IsDeleted = 0
       AND s.idGroup = ?
       AND s.idDiscipl = ?
       AND s.idTeacher = ?
       AND s.idLessonType = ?
       AND w.StartDate >= ?
       AND w.StartDate <= ?",
    [(int)$groupId, (int)$subjectId, (int)$teacherId, (int)$lessonTypeId, $termStart, $termEnd],
    0.0
  );
}
