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
  return dbAll($conn, "SELECT id, name, short_name, sort_order, lesson_type_id FROM {$t}
                      WHERE is_deleted = 0 ORDER BY sort_order, name");
}

/*
 * Явная связь «вид деятельности <-> тип занятия» (lesson_type_id).
 * Если в карточке запроса поле не прислали — оставляем текущее значение.
 */
function repoCurrNormalizeLessonTypeId(mysqli $conn, $body): ?int {
  if (!array_key_exists('lesson_type_id', $body) &&
      !array_key_exists('lessonTypeId', $body)) {
    return -1; // «не трогать»
  }
  $v = $body['lesson_type_id'] ?? $body['lessonTypeId'] ?? null;
  if ($v === null || $v === '' || (int)$v <= 0) return null;
  return (int)$v;
}

function repoCurrActivityKindSave(mysqli $conn, ?int $id, $body): int {
  $t    = planTab('activity_kind');
  $name = trim((string)($body['name'] ?? ''));
  if ($name === '') throw new Exception('Название вида деятельности обязательно');
  $short  = trim((string)($body['short_name'] ?? '')) ?: null;
  $sort   = (int)($body['sort_order'] ?? 0);
  $ltid   = repoCurrNormalizeLessonTypeId($conn, $body);

  if ($id && $id > 0) {
    if ($ltid === -1) { // поле не прислали — оставляем связь как есть
      dbExec($conn, "UPDATE {$t} SET name = ?, short_name = ?, sort_order = ? WHERE id = ?",
        [$name, $short, $sort, $id]);
      $ltid = (int)dbScalar($conn, "SELECT lesson_type_id FROM {$t} WHERE id = ?", [$id], 0);
    } else {
      dbExec($conn, "UPDATE {$t} SET name = ?, short_name = ?, sort_order = ?, lesson_type_id = ? WHERE id = ?",
        [$name, $short, $sort, $ltid, $id]);
    }
    // Автосинхронизация со справочником «Типы занятий»: если связь не
    // выбрана вручную — подбираем тип занятия по совпадению названия.
    if (!$ltid) {
      $auto = repoCurrAutoLinkLessonType($conn, $id, $name, $short);
      if ($auto) $ltid = $auto;
    }
    repoCurrKindLinkCacheReset();
    return $id;
  }
  $newId = dbInsert($conn,
    "INSERT INTO {$t} (name, short_name, sort_order, lesson_type_id) VALUES (?, ?, ?, ?)",
    [$name, $short, $sort, $ltid === -1 ? null : $ltid]);
  if (!$ltid) {
    $auto = repoCurrAutoLinkLessonType($conn, $newId, $name, $short);
    if ($auto) $ltid = $auto;
  }
  repoCurrKindLinkCacheReset();
  return $newId;
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
              ak.name AS kind_name, ak.short_name,
              COALESCE(sg.name, CONCAT('гр.', ch.group_id)) AS group_name
       FROM {$ch} ch
       JOIN {$ak} ak ON ak.id = ch.activity_kind_id AND ak.is_deleted = 0
       LEFT JOIN {$mp}student_group sg ON sg.id = ch.group_id
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
  // Комментарий убран из формы карточки: при обновлении сохраняем то,
  // что уже лежит в БД (поле остаётся в схеме для совместимости).
  if (array_key_exists('comment', $body)) {
    $comment = trim((string)($body['comment'] ?? '')) ?: null;
  } else {
    $idPrev  = (int)($body['id'] ?? 0);
    $comment = $idPrev > 0
      ? dbScalar($conn, "SELECT comment FROM {$curr} WHERE id = ?", [$idPrev], null)
      : null;
  }
  $id      = (int)($body['id'] ?? 0);

  // Ограничение: сумма распределённых часов НЕ МОЖЕТ превышать общую
  // трудоемкость карточки. Считаем по группам (строка «все группы» — один раз).
  $hoursIn = $body['hours'] ?? [];
  if (is_array($hoursIn) && $hoursIn && $total > 0) {
    $perGroup = [];
    foreach ($hoursIn as $row) {
      $kindId = (int)($row['activity_kind_id'] ?? $row['activityKindId'] ?? 0);
      if ($kindId <= 0) continue;
      $h       = max(0, (float)($row['hours'] ?? 0));
      $gidRaw  = $row['group_id'] ?? $row['groupId'] ?? null;
      $key     = ($gidRaw === null || $gidRaw === '' || (int)$gidRaw <= 0) ? 'ALL' : (string)(int)$gidRaw;
      $perGroup[$key] = ($perGroup[$key] ?? 0) + $h;
    }
    foreach ($perGroup as $key => $sumG) {
      if ($sumG > $total + 1e-6) {
        $label = $key === 'ALL' ? '«Все группы»' : 'группа #' . $key;
        throw new Exception(
          'Сумма часов (' . rtrim(rtrim(number_format($sumG, 2, '.', ''), '0'), '.')
          . ' ч) в строке ' . $label . ' превышает общую трудоемкость ('
          . rtrim(rtrim(number_format($total, 2, '.', ''), '0'), '.') . ' ч).'
        );
      }
    }
  }

  // В таблице стоит жёсткий UNIQUE (discipline_id, course, term), поэтому
  // удалённые (is_deleted=1) карточки тоже занимают слот. Если при создании
  // новой карточки находится «мёртвый» дубль — оживляем его вместо INSERT
  // (иначе MySQL вернёт Duplicate entry '1-1-1' for key 'uq_curriculum').
  if ($id > 0) {
    $dupSql = "SELECT id FROM {$curr}
               WHERE discipline_id = ? AND course = ? AND term = ? AND is_deleted = 0
                 AND id <> ?";
    if ((int)dbScalar($conn, $dupSql, [$metaId, $course, $term, $id], 0) > 0) {
      throw new Exception('Такая дисциплина уже есть в плане на этом курсе/семестре');
    }
    dbExec($conn,
      "UPDATE {$curr} SET discipline_id = ?, course = ?, term = ?, total_hours = ?, comment = ?,
              is_deleted = 0, updated_at = NOW() WHERE id = ?",
      [$metaId, $course, $term, $total, $comment, $id]);
  } else {
    $dead = dbRow($conn,
      "SELECT id FROM {$curr}
       WHERE discipline_id = ? AND course = ? AND term = ? AND is_deleted = 1
       LIMIT 1",
      [$metaId, $course, $term]);
    if ($dead) {
      $id = (int)$dead['id'];
      dbExec($conn,
        "UPDATE {$curr} SET total_hours = ?, comment = ?,
                is_deleted = 0, updated_at = NOW() WHERE id = ?",
        [$total, $comment, $id]);
    } else {
      $live = dbRow($conn,
        "SELECT id FROM {$curr}
         WHERE discipline_id = ? AND course = ? AND term = ? AND is_deleted = 0
         LIMIT 1",
        [$metaId, $course, $term]);
      if ($live) {
        throw new Exception('Такая дисциплина уже есть в плане на этом курсе/семестре');
      }
      $id = dbInsert($conn,
        "INSERT INTO {$curr} (discipline_id, course, term, total_hours, comment)
         VALUES (?, ?, ?, ?, ?)",
        [$metaId, $course, $term, $total, $comment]);
    }
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
            c.course AS course,
            COALESCE({$mp}d.name, '(нет в справочнике)') AS discipline_name,
            ch.activity_kind_id, ak.name AS kind_name, ak.short_name,
            ak.lesson_type_id,
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

/*
 * Карта явных связей «вид деятельности -> тип занятия» из таблицы плана.
 * Возвращает [activity_kind_id => lesson_type_id] только для непустых связей.
 */
function repoCurrKindLessonTypeMap(mysqli $planConn): array {
  $map = [];
  foreach (repoCurrActivityKinds($planConn) as $k) {
    $lt = isset($k['lesson_type_id']) ? (int)$k['lesson_type_id'] : 0;
    if ($lt > 0) $map[(int)$k['id']] = $lt;
  }
  return $map;
}

/* Есть ли в учебном плане ХОТЬ ОДНА связь вида деятельности с типом занятия.
 * Если да — сопоставление считается СТРОГИМ: вид соответствует типу только
 * по явной связи lesson_type_id (справочники и план синхронизированы).
 * Если связей нет вообще (старая база) — работает прежнее сопоставление
 * по нормализованному имени («Лекции»~«Лекция»).
 * Кэш живёт в $GLOBALS и сбрасывается после каждого CRUD вида деятельности,
 * чтобы под mod_php переиспользование процесса не устаревало. */
function repoCurrHasAnyKindLink(?mysqli $planConn): bool {
  if ($planConn === null) return false; // соединения нет — не ужесточаем поведение
  if (isset($GLOBALS['__curr_haslink'])) return (bool)$GLOBALS['__curr_haslink'];
  $t = planTab('activity_kind');
  $v = dbScalar($planConn,
    "SELECT 1 FROM {$t} WHERE is_deleted = 0 AND lesson_type_id IS NOT NULL AND lesson_type_id > 0 LIMIT 1",
    [], 0);
  return $GLOBALS['__curr_haslink'] = ((int)$v === 1);
}

/* Сброс кэша проверки связей — вызывать после CRUD вида деятельности. */
function repoCurrKindLinkCacheReset(): void {
  unset($GLOBALS['__curr_haslink']);
}

/*
 * Автосинхронизация связей «вид деятельности <-> тип занятия» по имени.
 * Вызывается после сохранения вида: если связь не указана явно, пробуем
 * найти тип занятия основной БД с похожим названием и проставить его —
 * так справочник и учебный план всегда остаются идентичными.
 * Возвращает id найденного типа занятия (0 — совпадения нет).
 */
function repoCurrAutoLinkLessonType(mysqli $planConn, int $kindId, string $name, ?string $shortName): int {
  if ($kindId <= 0) return 0;
  $mp = currMainPrefix();
  $types = dbAll($planConn, "SELECT id, name FROM {$mp}lesson_type WHERE is_deleted = 0");
  foreach ($types as $ty) {
    if (currKindMatchesLessonType($name, (string)($shortName ?? ''), (string)$ty['name'])) {
      $t = planTab('activity_kind');
      dbExec($planConn, "UPDATE {$t} SET lesson_type_id = ? WHERE id = ?", [(int)$ty['id'], $kindId]);
      return (int)$ty['id'];
    }
  }
  return 0;
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
 * Сопоставление вида деятельности строки часовой сетки с типом занятия.
 * ЕСЛИ в плане задана хотя бы одна явная связь (activity_kind.lesson_type_id),
 * режим СТРОГИЙ: совпадение только по lesson_type_id — справочник «Типы
 * занятий» и учебный план полностью синхронизированы. Иначе (старая база
 * без связей) — прежнее сопоставление по нормализованному имени/сокращению.
 */
function currRowMatchesLessonType(array $row, int $lessonTypeId, ?string $typeName, array $kindLtMap): bool {
  $kindId = (int)($row['activity_kind_id'] ?? 0);
  if ($kindId > 0 && isset($kindLtMap[$kindId])) {
    // Явная связь задана — доверяем только ей
    return $kindLtMap[$kindId] === $lessonTypeId;
  }
  // Связь не задана: строгий режим (есть другие связи) -> не совпало;
  // старый режим (связей нет вообще) -> fallback по имени.
  $strict = repoCurrHasAnyKindLink(currPlanConnForMatch());
  if ($strict) return false;
  if ($typeName === null || $typeName === '') return false;
  return currKindMatchesLessonType(
    (string)($row['kind_name'] ?? ''),
    (string)($row['short_name'] ?? ''),
    (string)$typeName
  );
}

/* Глобальное соединение с базой плана (задаётся контроллером на запрос),
 * нужно для проверки наличия связей внутри currRowMatchesLessonType(). */
function currPlanConnForMatch(): ?mysqli {
  return $GLOBALS['__curr_plan_conn'] ?? null;
}

/*
 * Плановые часы связки «группа + семестр + дисциплина + тип занятия» из
 * УЧЕБНОГО ПЛАНА (проверка при ручном редактировании и автозаполнении).
 * Вид деятельности сопоставляется с типом занятия сначала по ЯВНОЙ связи
 * (activity_kind.lesson_type_id, настраивается в справочниках), затем по
 * нормализованному имени/сокращению («Лекции»~«Лекция», «Лаб»...).
 * Возвращает 0.0, если план по дисциплине пуст — тогда ограничение не действует.
 */
function repoCurrPlannedHours(mysqli $conn, int $groupId, int $term, int $disciplineId, int $lessonTypeId): float {
  $mp = currMainPrefix();

  $typeName = dbScalar($conn, "SELECT name FROM {$mp}lesson_type WHERE id = ?",
    [$lessonTypeId], null);
  if (!$typeName) return 0.0;

  $kindLtMap = repoCurrKindLessonTypeMap($conn);

  $sum = 0.0;
  foreach (repoCurrGroupTermGrid($conn, $groupId, $term) as $g) {
    if ((int)$g['discipline_id'] !== $disciplineId) continue;

    if (currRowMatchesLessonType($g, $lessonTypeId, (string)$typeName, $kindLtMap)) {
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
/* Есть ли дисциплина основной базы вообще в базе «Учебный план»
 * (хотя бы в одной карточке, для любой группы/семестра). Используется
 * модалкой занятия: если плана по дисциплине нет вообще — типы не красим;
 * если план по дисциплине существует, но не для этой группы — красим. */
function repoCurrDisciplineInPlan(mysqli $conn, int $disciplineId): bool {
  if ($disciplineId <= 0) return false;
  $curr = planTab('curriculum');
  $dm   = planTab('discipline_meta');
  $row = dbScalar(
    $conn,
    "SELECT 1 FROM {$curr} c
     JOIN {$dm} dm ON dm.id = c.discipline_id AND dm.is_deleted = 0
     WHERE dm.discipline_id = ? AND c.is_deleted = 0 LIMIT 1",
    [$disciplineId],
    0
  );
  return (int)$row === 1;
}

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

  // Явные связи «вид деятельности -> тип занятия» из учебного плана
  $kindLtMap = repoCurrKindLessonTypeMap($planConn);

  $result = [];
  foreach ($types as $t) {
    $planned = 0.0;
    foreach ($byDisc as $g) {
      if (currRowMatchesLessonType($g, (int)$t['id'], (string)$t['name'], $kindLtMap)) {
        $planned += (float)$g['hours'];
      }
    }
    if ($planned <= 0.0001) continue; // плана по этому типу нет
    $result[(int)$t['id']] = [
      'planned' => round($planned, 2),
      'done'    => round($doneMap[(int)$t['id']] ?? 0.0, 2),
      // есть ли в учебном плане эта дисциплина для ГРУППЫ вообще
      // (часы группы или строка «Все группы») — см. фильтр ниже
      'inGroupPlan' => false,
    ];
  }

  /* Дисциплина может стоять в плане другого курса (другая карточка с теми
   * же семестром и дисциплиной, но другой группы курсов). В сетке расписания
   * такие часы не показываются, поэтому и тип занятия для этой группы
   * считается НЕ предусмотренным планом (красный в модалке занятия). */
  $groupCourse = 0;
  try {
    $mp2 = currMainPrefix();
    $gc = dbScalar(
      $mainConn,
      "SELECT COALESCE(admission_year, 0) FROM {$mp2}student_group WHERE id = ? LIMIT 1",
      [$groupId],
      0
    );
    $ay = (int)($gc ?? 0);
    if ($ay > 0) {
      $now = time();
      $sep = mktime(0, 0, 0, 9, 1, (int)date('Y'));
      $yearCur = (int)date('Y') + ($now >= $sep ? 1 : 0);
      $groupCourse = max(0, min(4, $yearCur - $ay));
    }
  } catch (Throwable $e) {
    $groupCourse = 0; // не удалось определить курс — не фильтруем
  }

  foreach ($result as $tid => &$row) {
    $row['inGroupPlan'] = true;
    if ($groupCourse > 0) {
      $hasForCourse = false;
      foreach ($byDisc as $g) {
        if ((int)($g['course'] ?? 0) === $groupCourse) { $hasForCourse = true; break; }
      }
      $row['inGroupPlan'] = $hasForCourse;
    }
  }
  unset($row);

  return $result;
}

/* ---------------------------------------------------------------------------
 * НАРУШЕНИЯ УЧЕБНОГО ПЛАНА для сетки расписания (красный текст как у
 * преподавателей). Считает «выполнено/план» по связке группа+семестр+
 * дисциплина+тип занятия и помечает превышения.
 *
 *   $weekIds  — id недель текущей просматриваемой недели (обычно 2);
 *   $groupIds — показываемые группы;
 *   семестр каждой группы берётся из календаря (term_for_date): часы плана
 *   относятся к группе и семестру, поэтому все занятия этой группы в
 *   текущем семестре считаются выполненными независимо от недели.
 *
 * Возвращает ['violations' => [lessonId => ['over'=>..,'planned'=>..,
 * 'done'=>..]], 'groups' => [groupId => ['total_over'=>float]]].
 * При недоступной базе плана возвращает пустой результат — обычный режим
 * работы программы не блокируется.
 * ------------------------------------------------------------------------- */
function repoCurrWeekViolations(mysqli $planConn, mysqli $mainConn, array $weekIds, array $groupIds): array {
  $empty = ['violations' => [], 'groups' => []];
  $weekIds  = array_values(array_filter(array_map('intval', $weekIds)));
  $groupIds = array_values(array_filter(array_map('intval', $groupIds)));
  if (!$weekIds || !$groupIds) return $empty;

  $mp  = currMainPrefix();
  $wph = implode(',', array_fill(0, count($weekIds), '?'));
  $gph = implode(',', array_fill(0, count($groupIds), '?'));

  // Учебная неделя состоит из двух недель календаря — семестр считаем для
  // каждой из них отдельно (на стыке семестров проверяются оба).
  // Семестр недели = по её start_date и году поступления группы.
  $rowsW = dbAll($mainConn,
      "SELECT id, start_date FROM {$mp}week WHERE id IN ({$wph})",
      $weekIds);
  if (!$rowsW) return $empty;

  $gy = [];
  foreach (dbAll($mainConn,
      "SELECT id, admission_year AS y FROM {$mp}student_group WHERE id IN ({$gph})",
      $groupIds) as $g) {
    $gy[(int)$g['id']] = (int)($g['y'] ?? 0);
  }

  $terms    = [];   // множество проверяемых семестров
  $weekTerm = [];   // weekId -> term
  foreach ($rowsW as $rw) {
    $d   = strtotime((string)($rw['start_date'] ?? 'now')) ?: time();
    $wid = (int)$rw['id'];
    foreach ($gy as $y) {
      if ($y <= 0) continue;
      $t = currCalcTerm($d, $y);
      if ($t > 0) { $terms[$t] = $t; $weekTerm[$wid] = $t; break; }
    }
  }
  if (!$terms) return $empty;

  // Карточки плана с часами по видам деятельности для проверяемых семестров
  $termList  = array_values($terms);
  $inTerms   = implode(',', array_fill(0, count($termList), '?'));
  $grid = dbAll($planConn,
    "SELECT c.term, dm.discipline_id, ch.group_id, ch.activity_kind_id,
            ak.name AS kind_name, ak.short_name, ak.lesson_type_id,
            SUM(ch.hours) AS hours
     FROM " . planTab('curriculum') . " c
     JOIN " . planTab('discipline_meta') . " dm ON dm.id = c.discipline_id AND dm.is_deleted = 0
     JOIN " . planTab('curriculum_hours') . " ch ON ch.curriculum_id = c.id AND ch.is_deleted = 0
     JOIN " . planTab('activity_kind') . " ak ON ak.id = ch.activity_kind_id AND ak.is_deleted = 0
     WHERE c.is_deleted = 0 AND c.term IN ({$inTerms})
       AND (ch.group_id IS NULL OR ch.group_id IN ({$gph}))
     GROUP BY c.term, dm.discipline_id, ch.group_id, ch.activity_kind_id",
    array_merge($termList, $groupIds));
  if (!$grid) return $empty;

  // план[term][groupId][disciplineId][kindKey] = ['hours'=>float,
  //        'name'=>kind_name, 'short'=>short_name, 'ltid'=>lesson_type_id]
  // (groupId = -1 — строка «Все группы»)
  $plan = [];
  foreach ($grid as $g) {
    $gid  = $g['group_id'] !== null ? (int)$g['group_id'] : -1;
    $term = (int)$g['term']; $did = (int)$g['discipline_id'];
    $key  = (string)$g['kind_name'] . '|' . (string)($g['short_name'] ?? '')
          . '|' . (int)($g['activity_kind_id'] ?? 0);
    $slot = &$plan[$term][$gid][$did][$key];
    if (!isset($slot)) {
      $slot = ['hours' => 0.0,
               'name'  => (string)$g['kind_name'],
               'short' => (string)($g['short_name'] ?? ''),
               'ltid'  => isset($g['lesson_type_id']) ? (int)$g['lesson_type_id'] : 0];
    }
    $slot['hours'] += (float)$g['hours'];
    unset($slot);
  }

  // Типы занятий основной базы (для сопоставления «вид <-> тип»)
  $types = dbAll($mainConn, "SELECT id, name FROM {$mp}lesson_type WHERE is_deleted = 0");
  $typeNameById = [];
  foreach ($types as $t) $typeNameById[(int)$t['id']] = (string)$t['name'];

  // Выполненные часы всех занятий групп (часы плана считаются за семестр)
  $doneRows = dbAll($mainConn,
    "SELECT s.id, s.group_id, s.discipline_id, s.lesson_type_id,
            s.week_id, COALESCE(s.hours, 0) AS hours
     FROM schedule_lesson s
     WHERE s.is_deleted = 0 AND s.group_id IN ({$gph})",
    $groupIds);

  // done[term][groupId][disciplineId][typeId] = hours ; lessonRef[lessonId]
  $done = []; $lessonRef = [];
  foreach ($doneRows as $drow) {
    $gid  = (int)$drow['group_id']; $did = (int)$drow['discipline_id'];
    $tid  = (int)$drow['lesson_type_id'];
    $term = (int)($weekTerm[(int)$drow['week_id']] ?? 0);
    if ($term <= 0 || !isset($terms[$term])) continue; // неделя вне проверяемых семестров
    if ($did <= 0 || $tid <= 0) continue;
    $done[$term][$gid][$did][$tid] = ($done[$term][$gid][$did][$tid] ?? 0.0) + (float)$drow['hours'];
    $lessonRef[(int)$drow['id']] = [$term, $gid, $did, $tid];
  }

  // Плановые часы типа занятия = сумма видов деятельности, сопоставимых с ним.
  // СТРОГИЙ режим (в плане есть хотя бы одна связь lesson_type_id): совпадение
  // только по явной связи — справочник и учебный план синхронизированы.
  // Старый режим (связей нет): fallback по нормализованному имени/сокращению.
  $strictLinks = repoCurrHasAnyKindLink($planConn);
  $plannedForType = static function (array $kinds, int $typeId, string $typeName) use ($strictLinks) {
    $sum = 0.0;
    foreach ($kinds as $kind) {
      if ((int)($kind['ltid'] ?? 0) === $typeId && $typeId > 0) {
        $sum += (float)$kind['hours'];
        continue;
      }
      if ($strictLinks) continue; // вид привязан к другому типу — не считаем
      if ($typeName !== '' && currKindMatchesLessonType(
          (string)($kind['name'] ?? ''), (string)($kind['short'] ?? ''), $typeName)) {
        $sum += (float)$kind['hours'];
      }
    }
    return $sum;
  };

  $violations  = [];
  $groupTotals = [];
  foreach ($lessonRef as $lid => $ref) {
    [$term, $gid, $did, $tid] = $ref;
    $typeName = $typeNameById[$tid] ?? null;
    if ($typeName === null) continue;

    $planAll  = $plan[$term][-1][$did] ?? [];
    $planGrp  = $plan[$term][$gid][$did] ?? [];
    $planned = $plannedForType($planAll, $tid, $typeName)
             + $plannedForType($planGrp, $tid, $typeName);
    if ($planned <= 0.0001) continue; // плана по этому типу нет — не ограничиваем

    // выполнено = часы этого типа и типов, относящихся к тем же видам плана
    $tdone = 0.0;
    foreach ($done[$term][$gid][$did] ?? [] as $t2 => $h2) {
      $n2 = $typeNameById[$t2] ?? '';
      if ($plannedForType($planAll, (int)$t2, (string)$n2)
        + $plannedForType($planGrp, (int)$t2, (string)$n2) > 0.0001) {
        $tdone += (float)$h2;
      }
    }

    if ($tdone > $planned + 0.0001) {
      $over = round($tdone - $planned, 2);
      $violations[$lid] = [
        'over'    => $over,
        'planned' => round($planned, 2),
        'done'    => round($tdone, 2),
      ];
      $groupTotals[$gid] = round(($groupTotals[$gid] ?? 0) + $over, 2);
    }
  }

  return ['violations' => $violations, 'groups' => $groupTotals];
}

// Семестр по дате и году поступления группы (та же логика, что во фронтенде:
// сентябрь-декабрь -> нечётный, февраль-июнь -> чётный, январь -> нечётный).
function currCalcTerm(int $timestamp, int $admissionYear): int {
  $year  = (int)date('Y', $timestamp);
  $month = (int)date('n', $timestamp);
  if ($month >= 9) {
    $k = $year - $admissionYear; $term = $k * 2 + 1;
  } elseif ($month >= 2 && $month <= 6) {
    $k = $year - $admissionYear - 1; $term = $k * 2 + 2;
  } elseif ($month === 1) {
    $k = $year - $admissionYear - 1; $term = $k * 2 + 1;
  } else {
    $k = $year - $admissionYear; $term = $k * 2 + 1;
  }
  return max(1, min(12, $term));
}
