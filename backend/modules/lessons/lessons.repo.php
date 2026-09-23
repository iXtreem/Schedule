<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoEnsureCustomTextColumn($conn) {
  static $checked = false;
  if ($checked) return;

  $sql = "
    IF COL_LENGTH('TB_Schedule', 'CustomText') IS NULL
    BEGIN
      ALTER TABLE TB_Schedule ADD CustomText NVARCHAR(500) NULL
    END

    IF EXISTS (
      SELECT 1
      FROM sys.columns
      WHERE object_id = OBJECT_ID('TB_Schedule')
        AND name = 'idDiscipl'
        AND is_nullable = 0
    )
    BEGIN
      ALTER TABLE TB_Schedule ALTER COLUMN idDiscipl INT NULL
    END

    IF EXISTS (
      SELECT 1
      FROM sys.columns
      WHERE object_id = OBJECT_ID('TB_Schedule')
        AND name = 'idTeacher'
        AND is_nullable = 0
    )
    BEGIN
      ALTER TABLE TB_Schedule ALTER COLUMN idTeacher INT NULL
    END

    IF EXISTS (
      SELECT 1
      FROM sys.columns
      WHERE object_id = OBJECT_ID('TB_Schedule')
        AND name = 'idRoom'
        AND is_nullable = 0
    )
    BEGIN
      ALTER TABLE TB_Schedule ALTER COLUMN idRoom INT NULL
    END

    IF EXISTS (
      SELECT 1
      FROM sys.columns
      WHERE object_id = OBJECT_ID('TB_Schedule')
        AND name = 'idLessonType'
        AND is_nullable = 0
    )
    BEGIN
      ALTER TABLE TB_Schedule ALTER COLUMN idLessonType INT NULL
    END
  ";

  $ok = odbc_exec($conn, $sql);
  if (!$ok) throw new Exception(odbc_errormsg($conn));
  $checked = true;
}

function repoNullablePositiveInt($value) {
  if ($value === null || $value === '') return null;
  $n = (int)$value;
  return $n > 0 ? $n : null;
}

function repoGetScheduleForWeek($conn, $weekId) {
  repoEnsureCustomTextColumn($conn);

  $sql = "
    SELECT
      idSchedule AS id,
      idWeek AS week_id,
      idGroup AS group_id,
      idDiscipl AS subject_id,
      idTeacher AS teacher_id,
      idRoom AS room_id,
      DayOfWeek AS day_of_week,
      TimeSlot AS time_slot,
      idLessonType AS lesson_type_id,
      Hours AS hours,
      CustomText AS custom_text
    FROM TB_Schedule
    WHERE IsDeleted = 0 AND idWeek = ?
    ORDER BY DayOfWeek, TimeSlot, idGroup
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$weekId])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = $row;
  return $data;
}

// вычислить Hours: 1.0 если праздник, иначе 2.0
function calcHoursByHoliday($conn, $weekId, $dayOfWeek) {
  // дата конкретного дня недели: StartDate + (DayOfWeek-1)
  $sql = "
    SELECT
      CASE WHEN EXISTS(
        SELECT 1
        FROM TB_Holidays h
        WHERE h.HolidayDate = DATEADD(day, ?, w.StartDate)
      )
      THEN CAST(1.0 AS DECIMAL(4,2))
      ELSE CAST(2.0 AS DECIMAL(4,2))
      END AS hours
    FROM TB_Weeks w
    WHERE w.idWeek = ?
  ";

  $offset = (int)$dayOfWeek - 1;

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$offset, $weekId])) throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  return $row ? (float)$row['hours'] : 2.0;
}
//Проверка для 1 преподаватель 1 аудиотория не повторялись
function repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, $excludeScheduleId = 0) {
  $sql = "
    SELECT
      MAX(CASE WHEN s.idTeacher = ? THEN 1 ELSE 0 END) AS teacher_conflict,
      MAX(CASE WHEN s.idRoom = ? THEN 1 ELSE 0 END) AS room_conflict
    FROM TB_Schedule s
    WHERE s.IsDeleted = 0
      AND s.idWeek = ?
      AND s.DayOfWeek = ?
      AND s.TimeSlot = ?
      AND (? = 0 OR s.idSchedule <> ?)
      AND (s.idTeacher = ? OR s.idRoom = ?)
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));

  $ok = odbc_execute($st, [
    (int)$teacherId,
    (int)$roomId,
    (int)$weekId,
    (int)$dayOfWeek,
    (int)$timeSlot,
    (int)$excludeScheduleId,
    (int)$excludeScheduleId,
    (int)$teacherId,
    (int)$roomId
  ]);
  if (!$ok) throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  if (!$row) return;
  $row = array_change_key_case($row, CASE_LOWER);

  $teacherBusy = (int)($row['teacher_conflict'] ?? 0) === 1;
  $roomBusy = (int)($row['room_conflict'] ?? 0) === 1;

  if ($teacherBusy && $roomBusy) {
    throw new Exception("Конфликт: преподаватель и аудитория уже заняты в этой неделе на выбранной паре.");
  }
  if ($teacherBusy) {
    throw new Exception("Конфликт: преподаватель уже занят в этой неделе на выбранной паре.");
  }
  if ($roomBusy) {
    throw new Exception("Конфликт: аудитория уже занята в этой неделе на выбранной паре.");
  }
}
//Конец функции проверки

function repoCreateLesson($conn, $p) {
  repoEnsureCustomTextColumn($conn);

  $weekId    = (int)$p['weekId'];
  $dayOfWeek = (int)$p['dayOfWeek'];
  $groupId   = (int)$p['groupId'];
  $subjectId = repoNullablePositiveInt($p['subjectId'] ?? null);
  $teacherId = repoNullablePositiveInt($p['teacherId'] ?? null);
  $typeId    = repoNullablePositiveInt($p['typeId'] ?? null);
  $roomId    = repoNullablePositiveInt($p['roomId'] ?? null);
  $timeSlot  = (int)$p['timeSlot'];
  $hoursReq  = isset($p['hours']) ? (float)$p['hours'] : 2.0;

  $customText = trim((string)($p['customText'] ?? $p['custom_text'] ?? ''));
  if ($customText === '') $customText = null;
  if ($customText !== null) {
    $customText = function_exists('mb_substr')
      ? mb_substr($customText, 0, 500, 'UTF-8')
      : substr($customText, 0, 500);
  }

  $isCustomOnly = $customText !== null
    && $subjectId === null
    && $teacherId === null
    && $typeId === null
    && $roomId === null;

  if (!$isCustomOnly && ($subjectId === null || $teacherId === null || $typeId === null || $roomId === null)) {
    throw new Exception("Fill all lesson fields or keep only custom text.");
  }

  $hours = 0.0;
  if (!$isCustomOnly) {
    $autoHours = calcHoursByHoliday($conn, $weekId, $dayOfWeek);
    $hours = ($autoHours == 1.0) ? 1.0 : $hoursReq;
    if ($hours != 1.0 && $hours != 2.0) $hours = 2.0;

    repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, 0);

    $term = isset($p['term']) ? (int)$p['term'] : 0;
    if ($term <= 0) throw new Exception("term required");

    $planned = repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId);
    $done    = repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, 0);

    $EPS = 0.0001;
    if ($planned > $EPS && ($done + $hours) > ($planned + $EPS)) {
      $left = max(0.0, $planned - $done);
      throw new Exception("Hours limit exceeded: left {$left}, attempted {$hours}.");
    }
  }

  $sql = "
    INSERT INTO TB_Schedule
      (idWeek, idGroup, idDiscipl, idTeacher, idRoom, DayOfWeek, TimeSlot, idLessonType, Hours, CustomText, IsDeleted)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));

  $ok = odbc_execute($st, [
    $weekId,
    $groupId,
    $subjectId,
    $teacherId,
    $roomId,
    $dayOfWeek,
    $timeSlot,
    $typeId,
    $hours,
    $customText
  ]);

  if (!$ok) throw new Exception(odbc_errormsg($conn));

  $res = odbc_exec($conn, "SELECT SCOPE_IDENTITY() AS id");
  $row = odbc_fetch_array($res);
  return (int)$row['id'];
}
function repoUpdateLesson($conn, $id, $p) {
  repoEnsureCustomTextColumn($conn);

  $id = (int)$id;

  $weekId    = (int)$p['weekId'];
  $dayOfWeek = (int)$p['dayOfWeek'];
  $groupId   = (int)$p['groupId'];
  $subjectId = repoNullablePositiveInt($p['subjectId'] ?? null);
  $teacherId = repoNullablePositiveInt($p['teacherId'] ?? null);
  $typeId    = repoNullablePositiveInt($p['typeId'] ?? null);
  $roomId    = repoNullablePositiveInt($p['roomId'] ?? null);
  $timeSlot  = (int)$p['timeSlot'];
  $hoursReq  = isset($p['hours']) ? (float)$p['hours'] : 2.0;

  $customText = trim((string)($p['customText'] ?? $p['custom_text'] ?? ''));
  if ($customText === '') $customText = null;
  if ($customText !== null) {
    $customText = function_exists('mb_substr')
      ? mb_substr($customText, 0, 500, 'UTF-8')
      : substr($customText, 0, 500);
  }

  $isCustomOnly = $customText !== null
    && $subjectId === null
    && $teacherId === null
    && $typeId === null
    && $roomId === null;

  if (!$isCustomOnly && ($subjectId === null || $teacherId === null || $typeId === null || $roomId === null)) {
    throw new Exception("Fill all lesson fields or keep only custom text.");
  }

  $hours = 0.0;
  if (!$isCustomOnly) {
    $autoHours = calcHoursByHoliday($conn, $weekId, $dayOfWeek);
    $hours = ($autoHours == 1.0) ? 1.0 : $hoursReq;
    if ($hours != 1.0 && $hours != 2.0) $hours = 2.0;

    repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, $id);

    $term = isset($p['term']) ? (int)$p['term'] : 0;
    if ($term <= 0) throw new Exception("term required");

    $planned = repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId);
    $done    = repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, $id);

    $EPS = 0.0001;
    if ($planned > $EPS && ($done + $hours) > ($planned + $EPS)) {
      $left = max(0.0, $planned - $done);
      throw new Exception("Hours limit exceeded: left {$left}, attempted {$hours}.");
    }
  }

  $sql = "
    UPDATE TB_Schedule
    SET idWeek=?, idGroup=?, idDiscipl=?, idTeacher=?, idRoom=?, DayOfWeek=?, TimeSlot=?, idLessonType=?, Hours=?, CustomText=?
    WHERE idSchedule=? AND IsDeleted=0
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));

  $ok = odbc_execute($st, [
    $weekId,
    $groupId,
    $subjectId,
    $teacherId,
    $roomId,
    $dayOfWeek,
    $timeSlot,
    $typeId,
    $hours,
    $customText,
    $id
  ]);

  if (!$ok) throw new Exception(odbc_errormsg($conn));
  return true;
}
function repoSoftDeleteLesson($conn, $id) {
  $sql = "UPDATE TB_Schedule SET IsDeleted=1 WHERE idSchedule=?";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$id])) throw new Exception(odbc_errormsg($conn));

  return true;
}

//Функции план и выполенно для контроля часов
function repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId) {
  $sql = "
    SELECT
      SUM(
        CAST(ISNULL(p.GdTcShipBHour, 0) AS FLOAT) +
        CAST(ISNULL(p.GdTcShipVHour, 0) AS FLOAT)
      ) AS planned
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
  if (!odbc_execute($st, [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId, (int)$typeId])) {
    throw new Exception(odbc_errormsg($conn));
  }

  $row = odbc_fetch_array($st);
  return ($row && $row['planned'] !== null) ? (float)$row['planned'] : 0.0;
}

function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, $excludeScheduleId = 0) {
  $sql = "
    SELECT SUM(CAST(ISNULL(s.Hours, 0) AS FLOAT)) AS done
    FROM TB_Schedule s
    WHERE s.IsDeleted = 0
      AND s.idGroup = ?
      AND s.idDiscipl = ?
      AND s.idTeacher = ?
      AND s.idLessonType = ?
      AND (? = 0 OR s.idSchedule <> ?)
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [(int)$groupId, (int)$subjectId, (int)$teacherId, (int)$typeId, (int)$excludeScheduleId, (int)$excludeScheduleId])) {
    throw new Exception(odbc_errormsg($conn));
  }

  $row = odbc_fetch_array($st);
  return ($row && $row['done'] !== null) ? (float)$row['done'] : 0.0;
}
