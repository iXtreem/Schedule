
<?php
require_once __DIR__ . '/../../lib/db.php';

/*
 * Репозиторий расписания (новая схема БД uchet_lfpstu).
 *   TB_Schedule  -> schedule_lesson  (занятия)
 *   TB_GdTcShip  -> plan_hours       (часы учебного плана)
 *   TB_TcShip    -> study_stream     (поток группы)
 *   TB_Holidays  -> holiday, TB_Weeks -> week
 * Колонки — snake_case: id, week_id, group_id, discipline_id, teacher_id,
 * room_id, lesson_type_id, day_of_week, time_slot, hours, custom_text.
 */

// Проверка наличия колонки в текущей базе (страховка от старых копий БД)
function repoHasColumn($conn, string $table, string $column): bool {
  return dbScalar(
    $conn,
    "SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
    [$table, $column],
    0
  ) > 0;
}

// Приводит структуру таблицы schedule_lesson к виду, который ожидает приложение:
// добавляет колонку custom_text и делает поля занятия необязательными.
function repoEnsureScheduleColumns($conn) {
  static $checked = false;
  if ($checked) return;

  // есть ли колонка custom_text
  if (!repoHasColumn($conn, 'schedule_lesson', 'custom_text')) {
    $conn->query("ALTER TABLE schedule_lesson ADD COLUMN custom_text VARCHAR(500) NULL");
  }

  // nullable-колонки discipline_id / teacher_id / room_id / lesson_type_id
  $rows = dbAll(
    $conn,
    "SELECT COLUMN_NAME AS name, IS_NULLABLE AS nullable
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedule_lesson'
       AND COLUMN_NAME IN ('discipline_id','teacher_id','room_id','lesson_type_id')"
  );
  foreach ($rows as $row) {
    if (strtoupper((string)$row['nullable']) === 'NO') {
      $col = $row['name'];
      $type = dbScalar(
        $conn,
        "SELECT CONCAT(DATA_TYPE, IF(CHARACTER_MAXIMUM_LENGTH IS NOT NULL,
                 CONCAT('(', CHARACTER_MAXIMUM_LENGTH, ')'), ''))
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schedule_lesson' AND COLUMN_NAME = ?",
        [$col],
        'int'
      );
      $conn->query("ALTER TABLE schedule_lesson MODIFY COLUMN `$col` $type NULL");
    }
  }

  $checked = true;
}

// null для пустых значений, иначе положительное число
function repoNullablePositiveInt($value) {
  if ($value === null || $value === '') return null;
  $n = (int)$value;
  return $n > 0 ? $n : null;
}

// Все занятия выбранной недели (для отрисовки сетки расписания)
function repoGetScheduleForWeek($conn, $weekId) {
  repoEnsureScheduleColumns($conn);

  return dbAll(
    $conn,
    "SELECT
        id,
        week_id,
        group_id,
        discipline_id AS subject_id,
        teacher_id,
        room_id,
        day_of_week,
        time_slot,
        lesson_type_id,
        hours,
        custom_text
     FROM schedule_lesson
     WHERE is_deleted = 0 AND week_id = ?
     ORDER BY day_of_week, time_slot, group_id",
    [(int)$weekId]
  );
}

// Вычислить Hours: 1.0 если праздник, иначе 2.0
function calcHoursByHoliday($conn, $weekId, $dayOfWeek) {
  // дата конкретного дня недели: start_date + (day_of_week - 1)
  $offset = max(0, (int)$dayOfWeek - 1);

  $row = dbRow(
    $conn,
    "SELECT CASE WHEN EXISTS(
              SELECT 1 FROM holiday h
              WHERE h.holiday_date = DATE_ADD(w.start_date, INTERVAL ? DAY)
           ) THEN 1.0 ELSE 2.0 END AS hours
     FROM week w
     WHERE w.id = ?",
    [$offset, (int)$weekId]
  );

  return $row ? (float)$row['hours'] : 2.0;
}

// Проверка: на одной паре преподаватель и аудитория не могут быть заняты дважды
function repoEnsureNoTeacherOrRoomConflict($conn, $weekId, $dayOfWeek, $timeSlot, $teacherId, $roomId, $excludeScheduleId = 0) {
  $row = dbRow(
    $conn,
    "SELECT
        MAX(CASE WHEN s.teacher_id = ? THEN 1 ELSE 0 END) AS teacher_conflict,
        MAX(CASE WHEN s.room_id = ? THEN 1 ELSE 0 END) AS room_conflict
     FROM schedule_lesson s
     WHERE s.is_deleted = 0
       AND s.week_id = ?
       AND s.day_of_week = ?
       AND s.time_slot = ?
       AND (? = 0 OR s.id <> ?)
       AND (s.teacher_id = ? OR s.room_id = ?)",
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
// Конец функции проверки

// Общие правила расчёта часов и проверок для create/update
function repoLessonPrepareData($conn, $p, $excludeScheduleId = 0) {
  $weekId      = (int)$p['weekId'];
  $dayOfWeek   = (int)$p['dayOfWeek'];
  $groupId     = (int)$p['groupId'];
  $subjectId   = repoNullablePositiveInt($p['subjectId'] ?? null);
  $teacherId   = repoNullablePositiveInt($p['teacherId'] ?? null);
  $typeId      = repoNullablePositiveInt($p['typeId'] ?? null);
  $roomId      = repoNullablePositiveInt($p['roomId'] ?? null);
  $timeSlot    = (int)$p['timeSlot'];
  $hoursReq    = isset($p['hours']) ? (float)$p['hours'] : 2.0;

  $customText = trim((string)($p['customText'] ?? $p['custom_text'] ?? ''));
  if ($customText === '') $customText = null;
  if ($customText !== null) {
    $customText = function_exists('mb_substr')
      ? mb_substr($customText, 0, 500, 'UTF-8')
      : substr($customText, 0, 500);
  }

  // «своя запись» — ячейка только с текстом, без дисциплины/преподавателя...
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

    // контроль часов по учебному плану: нельзя поставить больше, чем запланировано
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

// Создание занятия
function repoCreateLesson($conn, $p) {
  repoEnsureScheduleColumns($conn);
  $d = repoLessonPrepareData($conn, $p, 0);

  // id формируется автоматически (AUTO_INCREMENT), счётчик TB_Sequence не нужен
  return dbInsert(
    $conn,
    "INSERT INTO schedule_lesson
        (week_id, group_id, discipline_id, teacher_id, room_id, day_of_week, time_slot, lesson_type_id, hours, custom_text, is_deleted)
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

// Обновление занятия
function repoUpdateLesson($conn, $id, $p) {
  repoEnsureScheduleColumns($conn);
  $id = (int)$id;
  $d = repoLessonPrepareData($conn, $p, $id);

  dbExec(
    $conn,
    "UPDATE schedule_lesson
     SET week_id=?, group_id=?, discipline_id=?, teacher_id=?, room_id=?, day_of_week=?, time_slot=?,
         lesson_type_id=?, hours=?, custom_text=?
     WHERE id=? AND is_deleted=0",
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

// Мягкое удаление занятия
function repoSoftDeleteLesson($conn, $id) {
  dbExec($conn, "UPDATE schedule_lesson SET is_deleted = 1 WHERE id = ?", [(int)$id]);
  return true;
}

// Функции «план» и «выполнено» для контроля часов
// Запланированные часы по связке группа/семестр/дисциплина/преподаватель/тип
function repoPlannedHours($conn, $groupId, $term, $subjectId, $teacherId, $typeId) {
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
    [(int)$groupId, (int)$term, (int)$subjectId, (int)$teacherId, (int)$typeId],
    0.0
  );
}

// Уже поставленные часы по той же связке (для проверки лимита)
function repoDoneHours($conn, $groupId, $subjectId, $teacherId, $typeId, $excludeScheduleId = 0) {
  return (float)dbScalar(
    $conn,
    "SELECT SUM(COALESCE(s.hours, 0)) AS done
     FROM schedule_lesson s
     WHERE s.is_deleted = 0
       AND s.group_id = ?
       AND s.discipline_id = ?
       AND s.teacher_id = ?
       AND s.lesson_type_id = ?
       AND (? = 0 OR s.id <> ?)",
    [(int)$groupId, (int)$subjectId, (int)$teacherId, (int)$typeId, (int)$excludeScheduleId, (int)$excludeScheduleId],
    0.0
  );
}
