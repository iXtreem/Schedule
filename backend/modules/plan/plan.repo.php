<?php
require_once __DIR__ . '/../../lib/utf8.php';

//направление выбора в модалке все предметы
function repoPlanSubjects($conn, $groupId, $term) {
  $sql = "
    SELECT DISTINCT d.idDiscipl AS id, d.DisciplName AS name
    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    JOIN TB_Discipl d ON d.idDiscipl = p.idDiscipl AND d.DisciplDeleted = 0
    WHERE tc.idGroup = ? AND p.GdTcShipTerm = ?
    ORDER BY d.DisciplName
  ";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}

//препода по предмету
function repoPlanTeachers($conn, $groupId, $term, $subjectId) {
  $sql = "
    SELECT DISTINCT
      t.idTeacher AS id,
      LTRIM(RTRIM(t.TeacherSurname)) + ' ' +
      LTRIM(RTRIM(t.TeacherFirstName)) + ' ' +
      LTRIM(RTRIM(t.TeacherLastName)) AS name
    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    JOIN TB_Teacher t ON t.idTeacher = p.idTeacher
    WHERE tc.idGroup = ? AND p.GdTcShipTerm = ? AND p.idDiscipl = ?
    ORDER BY name
  ";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term, $subjectId])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}

function repoPlanTeachersBase($conn, $groupId, $term) {
  $sql = "
    SELECT DISTINCT
      t.idTeacher AS id,
      LTRIM(RTRIM(t.TeacherSurname)) + ' ' +
      LTRIM(RTRIM(t.TeacherFirstName)) + ' ' +
      LTRIM(RTRIM(t.TeacherLastName)) AS name
    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    JOIN TB_Teacher t ON t.idTeacher = p.idTeacher
    WHERE tc.idGroup = ? AND p.GdTcShipTerm = ?
    ORDER BY name
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term])) throw new Exception(odbc_errormsg($conn)); //только 2 параметра

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}


//типы
function repoPlanLessonTypes($conn, $groupId, $term, $subjectId, $teacherId) {
  $sql = "
    SELECT DISTINCT
      tt.idTimeType AS id,
      tt.TimeTypeName AS name,

      CAST(
        ISNULL(p.GdTcShipBHour,0) + ISNULL(p.GdTcShipVHour,0)
      AS FLOAT) AS planned_hours,

      CAST(ISNULL((
        SELECT SUM(CAST(ISNULL(s.Hours,0) AS FLOAT))
        FROM TB_Schedule s
        WHERE s.IsDeleted = 0
          AND s.idGroup = tc.idGroup
          AND s.idDiscipl = p.idDiscipl
          AND s.idTeacher = p.idTeacher
          AND s.idLessonType = p.idTimeType
      ),0) AS FLOAT) AS done_hours

    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    JOIN TB_TimeType tt ON tt.idTimeType = p.idTimeType
    WHERE tc.idGroup = ?
      AND p.GdTcShipTerm = ?
      AND p.idDiscipl = ?
      AND p.idTeacher = ?
    ORDER BY tt.TimeTypeName
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term, $subjectId, $teacherId])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}

//предметы по преподавателю
function repoPlanSubjectsByTeacher($conn, $groupId, $term, $teacherId) {
  $sql = "
    SELECT DISTINCT d.idDiscipl AS id, d.DisciplName AS name
    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    JOIN TB_Discipl d ON d.idDiscipl = p.idDiscipl AND d.DisciplDeleted = 0
    WHERE tc.idGroup = ? AND p.GdTcShipTerm = ? AND p.idTeacher = ?
    ORDER BY d.DisciplName
  ";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term, $teacherId])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}

//Расчет часов
function repoGetGroupYear($conn, $groupId) {
  $sql = "SELECT GroupYear FROM TB_Group WHERE idGroup = ? AND GroupDeleted = 0";
  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId])) throw new Exception(odbc_errormsg($conn));
  $row = odbc_fetch_array($st);
  return $row ? (int)$row['GroupYear'] : 0;
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
  $sql = "
    SELECT
      SUM(COALESCE(p.GdTcShipBHour,0) + COALESCE(p.GdTcShipVHour,0)) AS planned
    FROM TB_GdTcShip p
    JOIN TB_TcShip tc ON tc.idTcShip = p.idTcShip AND tc.TcShipDeleted = 0
    WHERE tc.idGroup = ?
      AND p.GdTcShipTerm = ?
      AND p.idDiscipl = ?
      AND p.idTeacher = ?
      AND p.idTimeType = ?
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $term, $subjectId, $teacherId, $timeTypeId]))
    throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  return $row && $row['planned'] !== null ? (float)$row['planned'] : 0.0;
}

function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $lessonTypeId, $termStart, $termEnd) {
  $sql = "
    SELECT SUM(COALESCE(s.Hours,0)) AS done
    FROM TB_Schedule s
    JOIN TB_Weeks w ON w.idWeek = s.idWeek
    WHERE s.IsDeleted = 0
      AND s.idGroup = ?
      AND s.idDiscipl = ?
      AND s.idTeacher = ?
      AND s.idLessonType = ?
      AND w.StartDate >= ?
      AND w.StartDate <= ?
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$groupId, $subjectId, $teacherId, $lessonTypeId, $termStart, $termEnd]))
    throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  return $row && $row['done'] !== null ? (float)$row['done'] : 0.0;
}
