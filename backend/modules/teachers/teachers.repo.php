<?php
require_once __DIR__ . '/../../lib/db.php';

// Список преподавателей (таблица teacher — новая схема, бывшая TB_Teacher)
// ФИО собирается в одно поле name через CONCAT_WS
// max_hours — максимальная недельная нагрузка (по умолчанию 36, см. schema.sql);
// используется окном «Автозаполнение» и будущим генератором расписания.
// Рабочие дни/часы по умолчанию: '1111111' — работает все дни (Пн..Вс),
// work_start/work_end = NULL — время не ограничено.
function repoGetTeachers($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        CONCAT_WS(' ',
          TRIM(surname),
          TRIM(first_name),
          TRIM(patronymic)
        ) AS name,
        COALESCE(max_hours, 36) AS max_hours,
        COALESCE(working_days, '1111111') AS working_days,
        work_start,
        work_end
     FROM teacher
     WHERE is_deleted = 0
     ORDER BY surname, first_name"
  );
}

// Сохранение лимитов часов и графика работы: массив
//   { id, max_hours?, working_days?, work_start?, work_end? }.
// Поля, которых нет в элементе, не меняются. Возвращает число обновлённых записей.
function repoSaveTeacherHours($conn, array $items) {
  $updated = 0;
  foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0);
    if ($id <= 0) continue;

    // Собираем только те поля, которые реально прислали —
    // partial update (старые клиенты шлют только max_hours).
    $sets  = [];
    $params = [];
    $types  = '';

    if (array_key_exists('max_hours', $item)) {
      $hours = $item['max_hours'];
      $hours = ($hours === '' || $hours === null) ? 36 : (float)$hours;
      if ($hours < 0)   $hours = 0;
      if ($hours > 999) $hours = 999;
      // DECIMAL(5,1): храним с точностью до десятых
      $hours = round($hours * 10) / 10;
      $sets[]  = 'max_hours = ?';
      $params[] = $hours;
      $types   .= 'd';
    }

    if (array_key_exists('working_days', $item)) {
      $days = preg_replace('/[^01]/', '', (string)($item['working_days'] ?? ''));
      $days = substr(str_pad($days, 7, '1'), 0, 7); // Пн..Вс, недостающее = рабочий
      $sets[]  = 'working_days = ?';
      $params[] = $days;
      $types   .= 's';
    }

    // work_start / work_end: '' или null → NULL (без ограничения по времени)
    foreach (['work_start', 'work_end'] as $col) {
      if (!array_key_exists($col, $item)) continue;
      $v = trim((string)($item[$col] ?? ''));
      $valid = preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', $v);
      if ($v === '' || !$valid) {
        $sets[]  = "$col = NULL";
      } else {
        if (strlen($v) === 5) $v .= ':00';
        $sets[]  = "$col = ?";
        $params[] = $v;
        $types   .= 's';
      }
    }

    if (!$sets) continue;

    $params[] = $id;
    $types   .= 'i';

    $stmt = $conn->prepare(
      'UPDATE teacher SET ' . implode(', ', $sets) .
      ' WHERE id = ? AND is_deleted = 0'
    );
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $updated += $stmt->affected_rows;
    $stmt->close();
  }
  return $updated;
}

// ---------------------------------------------------------------------------
// Недельная нагрузка преподавателей (для подсветки в модалке занятия:
// зелёный — часов не больше лимита, красный — превышение).
//
// Правила (считаются на сервере, фронтенд их не дублирует):
//   * период сравнения — учебная неделя, для которой открывается ячейка
//     (передан week_id); если неделя обрезана (end_date раньше воскресенья),
//     считаются только её реальные дни;
//   * занятие попадает в неделю, если его дата (week.start_date +
//     day_of_week - 1) лежит внутри границ недели;
//   * часы преподавателя = сумма schedule_lesson.hours за эти дни;
//   * лимит = teacher.max_hours (по умолчанию 36, см. schema.sql).
// Ответ: [{ id, max_hours, week_hours }, ...] по всем живым преподавателям.
// ---------------------------------------------------------------------------
function repoGetTeacherWeeklyLoad($conn, $weekId = null) {
  if ($weekId === null || (int)$weekId <= 0) {
    // Без явной недели берём актуальную: сегодня внутри [start..end],
    // иначе ближайшую будущую
    $weekId = dbScalar(
      $conn,
      "SELECT id FROM week
       WHERE is_deleted = 0 AND CURDATE() BETWEEN start_date AND end_date
       ORDER BY start_date LIMIT 1"
    );
    if (!$weekId) {
      $weekId = dbScalar(
        $conn,
        "SELECT id FROM week WHERE is_deleted = 0 AND start_date >= CURDATE()
         ORDER BY start_date LIMIT 1"
      );
    }
  }
  $weekId = (int)$weekId;

  $rows = [];
  if ($weekId > 0) {
    $rows = dbAll(
      $conn,
      "SELECT s.teacher_id AS teacher_id, SUM(COALESCE(s.hours, 0)) AS h
       FROM schedule_lesson s
       JOIN week w ON w.id = s.week_id AND w.is_deleted = 0
       WHERE s.is_deleted = 0
         AND s.teacher_id IS NOT NULL
         AND s.week_id = ?
         AND DATE_ADD(w.start_date, INTERVAL (s.day_of_week - 1) DAY)
             BETWEEN w.start_date AND w.end_date
       GROUP BY s.teacher_id",
      [$weekId]
    );
  }

  $weekHours = [];
  foreach ($rows as $r) {
    $weekHours[(int)$r['teacher_id']] = round((float)$r['h'], 2);
  }

  $teachers = dbAll(
    $conn,
    "SELECT id, COALESCE(max_hours, 36) AS max_hours FROM teacher WHERE is_deleted = 0"
  );

  $out = [];
  foreach ($teachers as $t) {
    $id = (int)$t['id'];
    $out[] = [
      'id'         => $id,
      'week_id'    => $weekId,
      'max_hours'  => (float)$t['max_hours'],
      'week_hours' => $weekHours[$id] ?? 0.0,
    ];
  }
  return $out;
}