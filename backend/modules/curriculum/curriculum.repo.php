<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/db.php';

/*
 * Репозиторий окна «📚 Учебный план» (отдельная база uchetlfpstu_plan).
 * ---------------------------------------------------------------------------
 * Таблицы (см. База данных/schema_plan.sql):
 *   department        — кафедры;
 *   discipline_meta   — дисциплина учебного плана + привязка к кафедре;
 *   activity_kind     — виды учебной деятельности (колонки часов);
 *   curriculum        — карточка плана: дисциплина + курс + семестр + трудоемкость;
 *   curriculum_hours  — распределение трудоемкости по видам деятельности
 *                       для КАЖДОЙ ГРУППЫ в конкретном семестре;
 *   control_type      — виды контроля (Экзамен, Диф. зачет, Зачет, КП, КР);
 *   curriculum_control— вид контроля по семестрам (несколько семестров).
 *
 * Если прав на создание отдельной базы нет, таблицы автоматически создаются
 * в основной базе с префиксом plan_ (режим «префикс») — см. planDB()/planTab().
 */

// Префикс основной базы для кросс-базовых JOIN (режим separate) или '' (prefix).
function currMainPrefix(): string {
  global $database;
  $mode = $GLOBALS['__plan_mode_cache'] ?? 'separate';
  return $mode === 'prefix' ? '' : "`{$database}`.";
}

// -- Кафедры ------------------------------------------------------------------

function repoCurrDepartments(mysqli $conn): array {
  $t = planTab('department');
  return dbAll($conn, "SELECT id, name, short_name FROM {$t}
                      WHERE is_deleted = 0 ORDER BY name");
}

function repoCurrDepartmentSave(mysqli $conn, ?int $id, $body): int {
  $t    = planTab('department');
  $name = trim((string)($body['name'] ?? ''));
  if ($name === '') throw new Exception('Название кафедры обязательно');
  $short = trim((string)($body['short_name'] ?? '')) ?: null;

  if ($id && $id > 0) {
    dbExec($conn, "UPDATE {$t} SET name = ?, short_name = ? WHERE id = ?",
      [$name, $short, $id]);
    return $id;
  }
  return dbInsert($conn, "INSERT INTO {$t} (name, short_name) VALUES (?, ?)",
    [$name, $short]);
}

function repoCurrDepartmentDelete(mysqli $conn, int $id): void {
  $t = planTab('department');
  dbExec($conn, "UPDATE {$t} SET is_deleted = 1 WHERE id = ?", [$id]);
}

// -- Виды учебной деятельности --------------------------------------------------

function repoCurrActivityKinds(mysqli $conn): array {
  $t = planTab('activity_kind');
  return dbAll($conn, "SELECT id, name, short_name, sort_order FROM {$t}
                      WHERE is_deleted = 0 ORDER BY sort_order, name");
}

function repoCurrActivityKindSave(mysqli $conn, ?int $id, $body): int {
  $t    = planTab('activity_kind');
  $name = trim((string)($body['name'] ?? ''));
  if ($name === '') throw new Exception('Название вида деятельности обязательно');
  $short  = trim((string)($body['short_name'] ?? '')) ?: null;
  $sort   = (int)($body['sort_order'] ?? 0);

  if ($id && $id > 0) {
    dbExec($conn, "UPDATE {$t} SET name = ?, short_name = ?, sort_order = ? WHERE id = ?",
      [$name, $short, $sort, $id]);
    return $id;
  }
  return dbInsert($conn, "INSERT INTO {$t} (name, short_name, sort_order) VALUES (?, ?, ?)",
    [$name, $short, $sort]);
}

function repoCurrActivityKindDelete(mysqli $conn, int $id): void {
  $t = planTab('activity_kind');
  dbExec($conn, "UPDATE {$t} SET is_deleted = 1 WHERE id = ?", [$id]);
}

// -- Виды контроля ----------------------------------------------------------------

function repoCurrControlTypes(mysqli $conn): array {
  $t = planTab('control_type');
  return dbAll($conn, "SELECT id, name, sort_order FROM {$t}
                      WHERE is_deleted = 0 ORDER BY sort_order, name");
}

function repoCurrControlTypeSave(mysqli $conn, ?int $id, $body): int {
  $t    = planTab('control_type');
  $name = trim((string)($body['name'] ?? ''));
  if ($name === '') throw new Exception('Название вида контроля обязательно');
  $sort = (int)($body['sort_order'] ?? 0);

  if ($id && $id > 0) {
    dbExec($conn, "UPDATE {$t} SET name = ?, sort_order = ? WHERE id = ?", [$name, $sort, $id]);
    return $id;
  }
  return dbInsert($conn, "INSERT INTO {$t} (name, sort_order) VALUES (?, ?)", [$name, $sort]);
}

function repoCurrControlTypeDelete(mysqli $conn, int $id): void {
  $t = planTab('control_type');
  dbExec($conn, "UPDATE {$t} SET is_deleted = 1 WHERE id = ?", [$id]);
}

// -- Дисциплины учебного плана ------------------------------------------------------

function repoCurrDisciplines(mysqli $conn): array {
  $dm = planTab('discipline_meta');
  $mp = currMainPrefix();

  // Имя LEFT JOIN-таблицы задаём явно, чтобы не зависеть от порядка конкатенации.
  $depJoin = planTab('department') . ' dep';

  return dbAll($conn,
    "SELECT dm.id AS id,
            dm.discipline_id AS discipline_id,
            COALESCE({$mp}d.name, '(нет в справочнике)') AS name,
            dm.department_id AS department_id,
            COALESCE(dep.short_name, dep.name) AS department_name
     FROM {$dm} dm
     LEFT JOIN {$depJoin} ON dep.id = dm.department_id AND dep.is_deleted = 0
     LEFT JOIN {$mp}discipline d ON d.id = dm.discipline_id AND d.is_deleted = 0
     WHERE dm.is_deleted = 0
     ORDER BY name");
}

function repoCurrDisciplineSave(mysqli $conn, ?int $id, $body): int {
  $dm = planTab('discipline_meta');

  $disciplineId = (int)($body['discipline_id'] ?? $body['disciplineId'] ?? 0);
  if ($disciplineId <= 0) throw new Exception('Выберите дисциплину из справочника');
  $departmentId = (int)($body['department_id'] ?? $body['departmentId'] ?? 0);
  $departmentId = $departmentId > 0 ? $departmentId : null;

  if ($id && $id > 0) {
    dbExec($conn, "UPDATE {$dm} SET discipline_id = ?, department_id = ? WHERE id = ?",
      [$disciplineId, $departmentId, $id]);
    return $id;
  }

  // Повторная карточка той же дисциплины — просто обновляем кафедру.
  $existing = (int)dbScalar($conn,
    "SELECT id FROM {$dm} WHERE discipline_id = ? AND is_deleted = 0",
    [$disciplineId], 0);
  if ($existing > 0) {
    dbExec($conn, "UPDATE {$dm} SET department_id = ? WHERE id = ?", [$departmentId, $existing]);
    return $existing;
  }

  return dbInsert($conn, "INSERT INTO {$dm} (discipline_id, department_id) VALUES (?, ?)",
    [$disciplineId, $departmentId]);
}

function repoCurrDisciplineDelete(mysqli $conn, int $id): void {
  $dm   = planTab('discipline_meta');
  $curr = planTab('curriculum');
  dbExec($conn, "UPDATE {$dm} SET is_deleted = 1 WHERE id = ?", [$id]);
  // Карточки плана этой дисциплины больше не нужны.
  dbExec($conn, "UPDATE {$curr} SET is_deleted = 1 WHERE discipline_id = ?", [$id]);
}

// -- Карточки учебного плана ----------------------------------------------------------

function repoCurrList(mysqli $conn, int $course, int $term, int $departmentId, string $search): array {
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');
  $ch   = planTab('curriculum_hours');
  $ak   = planTab('activity_kind');
  $cc   = planTab('curriculum_control');
  $ct   = planTab('control_type');
  $mp   = currMainPrefix();
  $depJoin = planTab('department') . ' dep';

  $where  = ['c.is_deleted = 0', 'dm.is_deleted = 0'];
  $params = [];

  if ($course > 0) { $where[] = 'c.course = ?'; $params[] = $course; }
  if ($term   > 0) { $where[] = 'c.term = ?';   $params[] = $term; }
  if ($departmentId > 0) { $where[] = 'dm.department_id = ?'; $params[] = $departmentId; }
  if ($search !== '') {
    $where[] = "({$mp}d.name LIKE ? OR dep.name LIKE ?)";
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like;
  }

  $rows = dbAll($conn,
    "SELECT c.id, c.discipline_id, c.course, c.term, c.total_hours, c.comment,
            COALESCE({$mp}d.name, '(нет в справочнике)') AS discipline_name,
            dm.department_id,
            COALESCE(dep.short_name, dep.name) AS department_name
     FROM {$curr} c
     JOIN {$dm}  dm ON dm.id = c.discipline_id
     LEFT JOIN {$depJoin} ON dep.id = dm.department_id
     LEFT JOIN {$mp}discipline d ON d.id = dm.discipline_id
     WHERE " . implode(' AND ', $where) . "
     ORDER BY c.course, c.term, discipline_name
     LIMIT 500",
    $params);

  // Часы по группам и видам деятельности одной запрос-пачкой
  $ids = array_map(static fn($r) => (int)$r['id'], $rows);
  $hoursMap = [];
  $ctrlMap  = [];
  if ($ids) {
    $in = implode(',', array_fill(0, count($ids), '?'));

    foreach (dbAll($conn,
      "SELECT ch.curriculum_id, ch.group_id, ch.activity_kind_id, ch.hours,
              ak.name AS kind_name, ak.short_name
       FROM {$ch} ch
       JOIN {$ak} ak ON ak.id = ch.activity_kind_id AND ak.is_deleted = 0
       WHERE ch.is_deleted = 0 AND ch.curriculum_id IN ({$in})
       ORDER BY ak.sort_order", $ids) as $h) {
      $hoursMap[(int)$h['curriculum_id']][] = $h;
    }

    foreach (dbAll($conn,
      "SELECT cc.curriculum_id, cc.term, cc.control_type_id, ct.name
       FROM {$cc} cc
       JOIN {$ct} ct ON ct.id = cc.control_type_id AND ct.is_deleted = 0
       WHERE cc.is_deleted = 0 AND cc.curriculum_id IN ({$in})
       ORDER BY ct.sort_order", $ids) as $ctl) {
      $ctrlMap[(int)$ctl['curriculum_id']][] = $ctl;
    }
  }

  foreach ($rows as &$r) {
    $cid = (int)$r['id'];
    $r['hours']    = $hoursMap[$cid] ?? [];
    $r['controls'] = $ctrlMap[$cid] ?? [];
  }
  unset($r);

  return $rows;
}

function repoCurrCard(mysqli $conn, int $id): array {
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');
  $mp   = currMainPrefix();

  $card = dbRow($conn,
    "SELECT c.*, COALESCE({$mp}d.name, '(нет в справочнике)') AS discipline_name,
            dm.department_id
     FROM {$curr} c
     JOIN {$dm} dm ON dm.id = c.discipline_id
     LEFT JOIN {$mp}discipline d ON d.id = dm.discipline_id
     WHERE c.id = ? AND c.is_deleted = 0", [$id]);
  if (!$card) throw new Exception('Карточка учебного плана не найдена');

  $ch = planTab('curriculum_hours');
  $cc = planTab('curriculum_control');
  $card['hours']    = dbAll($conn, "SELECT group_id, activity_kind_id, hours FROM {$ch}
                                    WHERE curriculum_id = ? AND is_deleted = 0", [$id]);
  $card['controls'] = dbAll($conn, "SELECT control_type_id, term FROM {$cc}
                                    WHERE curriculum_id = ? AND is_deleted = 0", [$id]);
  return $card;
}

/**
 * Создание/обновление карточки плана.
 * body: { id?, discipline_meta_id, course, term, total_hours?, comment?,
 *         hours: [{group_id, activity_kind_id, hours}],
 *         controls: [{control_type_id, terms: [..]}] }
 */
function repoCurrSave(mysqli $conn, $body): int {
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');

  $metaId = (int)($body['discipline_meta_id'] ?? $body['disciplineMetaId'] ?? 0);
  if ($metaId <= 0) throw new Exception('discipline_meta_id required');
  if ((int)dbScalar($conn, "SELECT COUNT(*) FROM {$dm} WHERE id = ? AND is_deleted = 0",
      [$metaId], 0) === 0) {
    throw new Exception('Дисциплина учебного плана не найдена');
  }

  $course = (int)($body['course'] ?? 0);
  $term   = (int)($body['term'] ?? 0);
  if ($course < 1 || $course > 12) throw new Exception('Курс должен быть 1..12');
  if ($term < 1 || $term > 24)      throw new Exception('Семестр должен быть 1..24');

  $total   = (float)($body['total_hours'] ?? $body['totalHours'] ?? 0);
  $comment = trim((string)($body['comment'] ?? '')) ?: null;
  $id      = (int)($body['id'] ?? 0);

  // UNIQUE (discipline_id, course, term) — не даём дублям.
  $dupSql = "SELECT id FROM {$curr}
             WHERE discipline_id = ? AND course = ? AND term = ? AND is_deleted = 0"
           . ($id > 0 ? " AND id <> ?" : "");
  $dupParams = [$metaId, $course, $term];
  if ($id > 0) $dupParams[] = $id;
  if ((int)dbScalar($conn, $dupSql, $dupParams, 0) > 0) {
    throw new Exception('Такая дисциплина уже есть в плане на этом курсе/семестре');
  }

  if ($id > 0) {
    dbExec($conn,
      "UPDATE {$curr} SET course = ?, term = ?, total_hours = ?, comment = ?,
              updated_at = NOW() WHERE id = ?",
      [$course, $term, $total, $comment, $id]);
  } else {
    $id = dbInsert($conn,
      "INSERT INTO {$curr} (discipline_id, course, term, total_hours, comment)
       VALUES (?, ?, ?, ?, ?)",
      [$metaId, $course, $term, $total, $comment]);
  }

  repoCurrReplaceHours($conn, $id, $body['hours'] ?? []);
  repoCurrReplaceControls($conn, $id, $body['controls'] ?? []);

  return $id;
}

// Перезапись часов карточки (полный список из окна). Строка с group_id = null
// («все группы потока сразу») раскладывается на все живые группы.
function repoCurrReplaceHours(mysqli $conn, int $curriculumId, $hours): void {
  $ch = planTab('curriculum_hours');
  if (!is_array($hours)) return;

  dbExec($conn, "DELETE FROM {$ch} WHERE curriculum_id = ?", [$curriculumId]);

  $allGroups = null; // ленивая выборка всех групп (для общей строки часов)

  foreach ($hours as $row) {
    $kindId = (int)($row['activity_kind_id'] ?? $row['activityKindId'] ?? 0);
    if ($kindId <= 0) continue;
    $h = (float)($row['hours'] ?? 0);
    if ($h < 0) $h = 0;

    $groupIdRaw = $row['group_id'] ?? $row['groupId'] ?? null;
    if ($groupIdRaw === null || $groupIdRaw === '' || (int)$groupIdRaw <= 0) {
      // Общая строка на весь поток: раскладываем по всем группам.
      if ($allGroups === null) $allGroups = currAllGroupIds($conn);
      foreach ($allGroups as $gid) {
        dbExec($conn,
          "INSERT INTO {$ch} (curriculum_id, group_id, activity_kind_id, hours)
           VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE hours = VALUES(hours)",
          [$curriculumId, $gid, $kindId, $h]);
      }
      continue;
    }

    dbExec($conn,
      "INSERT INTO {$ch} (curriculum_id, group_id, activity_kind_id, hours)
       VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE hours = VALUES(hours)",
      [$curriculumId, (int)$groupIdRaw, $kindId, $h]);
  }
}

// Перезапись видов контроля: [{control_type_id, terms:[1,2]}]
function repoCurrReplaceControls(mysqli $conn, int $curriculumId, $controls): void {
  $cc = planTab('curriculum_control');
  if (!is_array($controls)) return;

  dbExec($conn, "DELETE FROM {$cc} WHERE curriculum_id = ?", [$curriculumId]);

  foreach ($controls as $row) {
    $ctId = (int)($row['control_type_id'] ?? $row['controlTypeId'] ?? 0);
    if ($ctId <= 0) continue;
    $terms = $row['terms'] ?? ($row['term'] ?? []);
    if (!is_array($terms)) $terms = [$terms];
    foreach ($terms as $t) {
      $t = (int)$t;
      if ($t < 1 || $t > 24) continue;
      dbExec($conn,
        "INSERT INTO {$cc} (curriculum_id, control_type_id, term)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE id = id",
        [$curriculumId, $ctId, $t]);
    }
  }
}

function repoCurrDelete(mysqli $conn, int $id): void {
  $curr = planTab('curriculum');
  $ch   = planTab('curriculum_hours');
  $cc   = planTab('curriculum_control');
  dbExec($conn, "UPDATE {$curr} SET is_deleted = 1, updated_at = NOW() WHERE id = ?", [$id]);
  dbExec($conn, "DELETE FROM {$ch} WHERE curriculum_id = ?", [$id]);
  dbExec($conn, "DELETE FROM {$cc} WHERE curriculum_id = ?", [$id]);
}

// -- Мост «Учебный план -> требования расписания» ----------------------------------------

// Все живые группы основной базы (для разложения общей строки часов на группы).
function currAllGroupIds(mysqli $conn): array {
  static $cache = null;
  if ($cache !== null) return $cache;

  $mp = currMainPrefix();
  $cache = array_map(
    static fn($g) => (int)$g['id'],
    dbAll($conn, "SELECT id FROM {$mp}student_group WHERE is_deleted = 0")
  );
  return $cache;
}

/*
 * Часовая сетка плана для группы и семестра: дисциплина x вид деятельности.
 * Используется модалкой занятия (ручное редактирование) и генератором
 * автозаполнения как источник требования «не больше часов из учебного плана».
 */
function repoCurrGroupTermGrid(mysqli $conn, int $groupId, int $term): array {
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');
  $ch   = planTab('curriculum_hours');
  $ak   = planTab('activity_kind');
  $mp   = currMainPrefix();

  return dbAll($conn,
    "SELECT c.id AS curriculum_id,
            dm.discipline_id AS discipline_id,
            COALESCE({$mp}d.name, '(нет в справочнике)') AS discipline_name,
            ch.activity_kind_id, ak.name AS kind_name, ak.short_name,
            ch.hours
     FROM {$curr} c
     JOIN {$dm} dm ON dm.id = c.discipline_id AND dm.is_deleted = 0
     JOIN {$ch} ch ON ch.curriculum_id = c.id AND ch.is_deleted = 0
                  AND (ch.group_id = ? OR ch.group_id IS NULL)
     JOIN {$ak} ak ON ak.id = ch.activity_kind_id AND ak.is_deleted = 0
     LEFT JOIN {$mp}discipline d ON d.id = dm.discipline_id
     WHERE c.term = ? AND c.is_deleted = 0
     ORDER BY discipline_name, ak.sort_order",
    [$groupId, $term]);
}

// Вид контроля по группе/семестру (для отображения «Экзамен/Зачет» в модалке)
function repoCurrGroupTermControls(mysqli $conn, int $groupId, int $term): array {
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');
  $cc   = planTab('curriculum_control');
  $ct   = planTab('control_type');
  $mp   = currMainPrefix();

  return dbAll($conn,
    "SELECT dm.discipline_id, COALESCE({$mp}d.name, '') AS discipline_name,
            cc.control_type_id, ct.name AS control_name, cc.term
     FROM {$curr} c
     JOIN {$dm} dm ON dm.id = c.discipline_id AND dm.is_deleted = 0
     JOIN {$cc} cc ON cc.curriculum_id = c.id AND cc.is_deleted = 0 AND cc.term = ?
     JOIN {$ct} ct ON ct.id = cc.control_type_id AND ct.is_deleted = 0
     LEFT JOIN {$mp}discipline d ON d.id = dm.discipline_id
     WHERE c.term = ? AND c.is_deleted = 0
     ORDER BY discipline_name, ct.sort_order",
    [$term, $term]);
}

// Нормализация названий для сопоставления «вид деятельности <-> тип занятия»:
// убираем гласные и окончания, чтобы «Лекции» совпало с «Лекция»,
// «Лабораторные работы» — с «Лабораторная», «Практические занятия» — с «Практическое».
function currNormalizeKind(string $s): string {
  $s = mb_strtolower(trim($s));
  $s = str_replace(['ё'], ['е'], $s);
  // убираем типичные окончания русских слов, оставляя «корень»
  $s = preg_replace('/(ия|ии|ие|ий|ая|оя|ые|ах|а|я|о|е|ы|и|ь)$/u', '', $s);
  $s = preg_replace('/\s+/u', '', $s);
  return $s;
}

// Совпадает ли вид деятельности плана с типом занятия расписания?
// «Лекции» ~ «Лекция», «Лабораторные работы» ~ «Лабораторная», «КСР/КРВ» ~ «КСР».
function currKindMatchesLessonType(string $kindName, string $shortName, string $typeName): bool {
  $normKind  = currNormalizeKind($kindName);
  $normType  = currNormalizeKind($typeName);
  $normShort = currNormalizeKind($shortName);

  if ($normKind !== '' && $normType !== '') {
    if ($normKind === $normType) return true;
    if (mb_strlen($normKind) >= 3 && mb_stripos($normType, $normKind) !== false) return true;
    if (mb_strlen($normType) >= 3 && mb_stripos($normKind, $normType) !== false) return true;
  }

  if ($normShort !== '' && mb_strlen($normShort) >= 2) {
    if (mb_stripos($normType, $normShort) !== false) return true;
    if (mb_stripos($typeName, (string)$shortName) !== false) return true;
  }

  return false;
}

/*
 * Плановые часы связки «группа + семестр + дисциплина + тип занятия» из
 * УЧЕБНОГО ПЛАНА (проверка при ручном редактировании и автозаполнении).
 * Вид деятельности сопоставляется с типом занятия по нормализованному имени
 * (или по короткому имени: «Лек», «Лаб», «Прак», «КСР»).
 * Возвращает 0.0, если план по дисциплине пуст — тогда ограничение не действует.
 */
function repoCurrPlannedHours(mysqli $conn, int $groupId, int $term, int $disciplineId, int $lessonTypeId): float {
  $mp = currMainPrefix();

  $typeName = dbScalar($conn, "SELECT name FROM {$mp}lesson_type WHERE id = ?",
    [$lessonTypeId], null);
  if (!$typeName) return 0.0;

  $sum = 0.0;
  foreach (repoCurrGroupTermGrid($conn, $groupId, $term) as $g) {
    if ((int)$g['discipline_id'] !== $disciplineId) continue;

    if (currKindMatchesLessonType(
        (string)$g['kind_name'],
        (string)($g['short_name'] ?? ''),
        (string)$typeName)) {
      $sum += (float)$g['hours'];
    }
  }
  return $sum;
}

/*
 * Счётчики «выполнено/план» для ВСЕХ типов занятий связки
 * группа + семестр + дисциплина (из отдельной базы «Учебный план»).
 * Возвращает [lesson_type_id => ['planned' => float, 'done' => float]];
 * пустой массив — если плана по дисциплине нет (тогда ограничение не
 * действует и фронт показывает типы без счётчика).
 */
function repoCurrPlannedDoneByTypes(mysqli $planConn, mysqli $mainConn, int $groupId, int $term, int $disciplineId): array {
  // Плановые часы вида деятельности для группы и семестра
  $grid = repoCurrGroupTermGrid($planConn, $groupId, $term);
  $byDisc = array_values(array_filter($grid,
    static fn($g) => (int)$g['discipline_id'] === $disciplineId));
  if (!$byDisc) return [];

  // Типы занятий основной базы
  $mp = currMainPrefix();
  $types = dbAll($mainConn, "SELECT id, name FROM {$mp}lesson_type WHERE is_deleted = 0");

  // Уже поставленные часы группы по дисциплине, сгруппированные по типу
  $doneRows = dbAll($mainConn,
    "SELECT lesson_type_id AS tid, SUM(COALESCE(hours, 0)) AS done
     FROM schedule_lesson
     WHERE is_deleted = 0 AND group_id = ? AND discipline_id = ?
     GROUP BY lesson_type_id",
    [$groupId, $disciplineId]);
  $doneMap = [];
  foreach ($doneRows as $d) $doneMap[(int)$d['tid']] = (float)$d['done'];

  $result = [];
  foreach ($types as $t) {
    $planned = 0.0;
    foreach ($byDisc as $g) {
      if (currKindMatchesLessonType(
          (string)$g['kind_name'],
          (string)($g['short_name'] ?? ''),
          (string)$t['name'])) {
        $planned += (float)$g['hours'];
      }
    }
    if ($planned <= 0.0001) continue; // плана по этому типу нет
    $result[(int)$t['id']] = [
      'planned' => round($planned, 2),
      'done'    => round($doneMap[(int)$t['id']] ?? 0.0, 2),
    ];
  }
  return $result;
}
