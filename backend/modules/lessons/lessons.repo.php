<?php
require_once __DIR__ . '/../../lib/db.php';

// Приводит структуру таблицы TB_Schedule к виду, который ожидает приложение:
// добавляет колонку CustomText и делает поля занятия необязательными.
function repoEnsureCustomTextColumn($conn) {
  static $checked = false;
  if ($checked) return;

  // есть ли колонка CustomText
  $has = (int)dbScalar(
    $conn,
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TB_Schedule' AND COLUMN_NAME = 'CustomText'",
    [],
    0
  );
  if ($has === 0) {
    $conn->query("ALTER TABLE TB_Schedule ADD COLUMN CustomText VARCHAR(500) NULL");
  }

  // nullable-колонки idDiscipl / idTeacher / idRoom / idLessonType
  $rows = dbAll(
    $conn,
    "SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TB_Schedule'
       AND COLUMN_NAME IN ('idDiscipl','idTeacher','idRoom','idLessonType')"
  );
  foreach ($rows as $row) {
    if (strtoupper((string)$row['nullable']) === 'NO') {
      $col = $row['name'];
      $type = dbScalar(
        $conn,
        "SELECT CONCAT(DATA_TYPE, IF(CHARACTER_MAXIMUM_LENGTH IS NOT NULL,
                 CONCAT('(', CHARACTER_MAXIMUM_LENGTH, ')'), ''))
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'TB_Schedule' AND COLUMN_NAME = ?",
        [$col],
        'int'
      );
      $conn->query("ALTER TABLE TB_Schedule MODIFY COLUMN `$col` $type NULL");
    }
  }

  $checked = true;
}

function repoNullablePositiveInt($value) {
  if ($value === null || $value === '') return null;
  $n = (int)$value;
  return $n > 0 ? $n : null;
}

function repoGetScheduleForWeek($conn, $weekId) {
  repoEnsureCustomTextColumn($conn);

  return dbAll(
    $conn,
    "SELECT
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
     ORDER BY DayOfWeek, TimeSlot, idGroup",
    [(int)$weekId]
  );
}

// вычислить Hours: 1.0 если праздник, иначе 2.0
function calcHoursByHoliday($conn, $weekId, $dayOfWeek) {
  // дата конкретного дня недели: StartDate + (DayOfWeek-1)
  $offset = max(0, (int)$dayOfWeek - 1);

  $row = dbRow(
    $conn,
    "SELECT CASE WHEN EXISTS(
              SELECT 1 FROM TB_Holidays h
              WHERE h.HolidayDate = DATE_ADD(w.StartDate, INTERVAL ? DAY)
           ) THEN 1.0 ELSE 2.0 END AS hours
     FROM TB_Weeks w
     WHERE w.idWeek = ?",
    [$offset, (int)$weekId]
  );

  return $row ? (float)$row['hours'] : 2.0;
}

//Проверка для 1 преподаватель 1 аудиотория не повторялись
function repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, $excludeScheduleId = 0) {
  $row = dbRow(
    $conn,
    "SELECT
        MAX(CASE WHEN s.idTeacher = ? THEN 1 ELSE 0 END) AS teacher_conflict,
        MAX(CASE WHEN s.idRoom = ? THEN 1 ELSE 0 END) AS room_conflict
     FROM TB_Schedule s
     WHERE s.IsDeleted = 0
       AND s.idWeek = ?
       AND s.DayOfWeek = ?
       AND s.TimeSlot = ?
       AND (? = 0 OR s.idSchedule <> ?)
       AND (s.idTeacher = ? OR s.idRoom = ?)",
    [
      (int)$teacherId,
      (int)$roomId,
      (int)$weekId,
      (int)$dayOfWeek,
      (int)$timeSlot,
      (int)$excludeScheduleId,
      (int)$excludeScheduleId,
      (int)$teacherId,
      (int)$roomId,
    ]
  );
  if (!$row) return;

  $teacherBusy = (int)($row['teacher_conflict'] ?? 0) === 1;
  $roomBusy    = (int)($row['room_conflict'] ?? 0) === 1;

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

// Общие правила расчёта часов и проверок для create/update
function repoLessonPrepareData($conn, $p, $excludeScheduleId = 0) {
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

    repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, $excludeScheduleId);

    $term = isset($p['term']) ? (int)$p['term'] : 0;
    if ($term <= 0) throw new Exception("term required");

    $planned = repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId);
    $done    = repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, $excludeScheduleId);

    $EPS = 0.0001;
    if ($planned > $EPS && ($done + $hours) > ($planned + $EPS)) {
      $left = max(0.0, $planned - $done);
      throw new Exception("Hours limit exceeded: left {$left}, attempted {$hours}.");
    }
  }

  return compact('weekId', 'groupId', 'subjectId', 'teacherId', 'typeId', 'roomId',
                 'dayOfWeek', 'timeSlot', 'hours', 'customText');
}

function repoCreateLesson($conn, $p) {
  repoEnsureCustomTextColumn($conn);
  $d = repoLessonPrepareData($conn, $p, 0);

  return dbInsert(
    $conn,
    "INSERT INTO TB_Schedule
        (idWeek, idGroup, idDiscipl, idTeacher, idRoom, DayOfWeek, TimeSlot, idLessonType, Hours, CustomText, IsDeleted)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)",
    [
      $d['weekId'],
      $d['groupId'],
      $d['subjectId'],
      $d['teacherId'],
      $d['roomId'],
      $d['dayOfWeek'],
      $d['timeSlot'],
      $d['typeId'],
      $d['hours'],
      $d['customText'],
    ]
  );
}

function repoUpdateLesson($conn, $id, $p) {
  repoEnsureCustomTextColumn($conn);
  $id = (int)$id;
  $d = repoLessonPrepareData($conn, $p, $id);

  dbExec(
    $conn,
    "UPDATE TB_Schedule
     SET idWeek=?, idGroup=?, idDiscipl=?, idTeacher=?, idRoom=?, DayOfWeek=?, TimeSlot=?,
         idLessonType=?, Hours=?, CustomText=?
     WHERE idSchedule=? AND IsDeleted=0",
    [
      $d['weekId'],
      $d['groupId'],
      $d['subjectId'],
      $d['teacherId'],
      $d['roomId'],
      $d['dayOfWeek'],
      $d['timeSlot'],
      $d['typeId'],
      $d['hours'],
      $d['customText'],
      $id,
    ]
  );

  return true;
}

function repoSoftDeleteLesson($conn, $id) {
  dbExec($conn, "UPDATE TB_Schedule SET IsDeleted = 1 WHERE idSchedule = ?", [(int)$id]);
  return true;
}

//Функции план и выполенно для контроля часов
function repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId) {
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
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId, (int)$typeId],
    0.0
  );
}

function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, $excludeScheduleId = 0) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(s.Hours, 0)) AS done
     FROM TB_Schedule s
     WHERE s.IsDeleted = 0
       AND s.idGroup = ?
       AND s.idDiscipl = ?
       AND s.idTeacher = ?
       AND s.idLessonType = ?
       AND (? = 0 OR s.idSchedule <> ?)",
    [(int)$groupId, (int)$subjectId, (int)$teacherId, (int)$typeId, (int)$excludeScheduleId, (int)$excludeScheduleId],
    0.0
  );
}
