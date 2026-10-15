<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/curriculum.repo.php';

/*
 * Контроллер окна «📚 Учебный план» (отдельная база uchetlfpstu_plan).
 * ---------------------------------------------------------------------------
 * Сущности API (entity=...):
 *   GET    curriculum_meta     — справочники окна: кафедры, виды деятельности,
 *                                виды контроля, дисциплины плана;
 *   POST   curriculum_department        — создать/обновить кафедру (id в body);
 *   DELETE curriculum_department&id=..  — удалить кафедру (soft);
 *   POST   curriculum_activity          — вид учебной деятельности;
 *   DELETE curriculum_activity&id=..
 *   POST   curriculum_control_type      — вид контроля;
 *   DELETE curriculum_control_type&id=..
 *   POST   curriculum_discipline        — дисциплина плана (+кафедра);
 *   DELETE curriculum_discipline&id=..
 *   GET    curriculum_list&course=&term=&department_id=&search=
 *                                — карточки плана с часами и контролем;
 *   GET    curriculum_card&id=..
 *   POST   curriculum_card       — сохранить карточку (часы/группы/контроль);
 *   DELETE curriculum_card&id=..
 *   GET    curriculum_grid&group_id=&term=   — часовая сетка плана для группы
 *                                (используется модалкой занятия и автозаполнением);
 *   GET    curriculum_controls&group_id=&term= — виды контроля группы.
 *
 * UI-эндпоинты окна «📚 Учебный план» (возвращают поля с camelCase-алиасами,
 * чтобы фронтенд не зависел от раскладки русских ключей):
 *   GET  curr_ui_meta            — кафедры + справочник дисциплин основной БД;
 *   POST curr_discipline         — {id?, discipline_id?, name?, department_id?};
 *   DELETE curr_discipline&id=.. ;
 *   GET  curr_cards&course=&term=&department_id=&search=  — карточки плана;
 *   GET  curr_card&id=..         — карточка + hours[{groupId,activityKindId,hours}]
 *                                  + controls[{controlTypeId,terms:[..]}];
 *   POST curr_card               — сохранить карточку (см. repoCurrSave);
 *   DELETE curr_card&id=..       — удалить карточку;
 *   GET  curr_hours&curriculum_id=.. — часы карточки (для перечитывания).
 */

// -- helpers для UI-эндпоинтов -------------------------------------------------

// ВАЖНО: под Apache mod_php процесс переиспользуется между запросами,
// поэтому static-кэш может устаревать. Кэш живёт в $GLOBALS и сбрасывается
// в начале каждого HTTP-запроса (см. curriculumController).
function currUiCache(string $key, callable $loader) {
  if (!isset($GLOBALS['__curr_cache']) || !is_array($GLOBALS['__curr_cache'])) {
    $GLOBALS['__curr_cache'] = [];
  }
  if (!array_key_exists($key, $GLOBALS['__curr_cache'])) {
    $GLOBALS['__curr_cache'][$key] = $loader();
  }
  return $GLOBALS['__curr_cache'][$key];
}

function currUiDepartments(): array {
  return currUiCache('departments', static function () {
    $conn = planDB(getDBConnection());
    return array_map(static fn($d) => [
      'id'   => (int)$d['id'],
      'name' => (string)$d['name'],
      'shortName' => (string)($d['short_name'] ?? ''),
    ], repoCurrDepartments($conn));
  });
}

function currUiActivityKinds(): array {
  return currUiCache('activityKinds', static function () {
    $conn = planDB(getDBConnection());
    return array_map(static fn($k) => [
      'id'   => (int)$k['id'],
      'name' => (string)$k['name'],
      'shortName' => (string)($k['short_name'] ?? ''),
    ], repoCurrActivityKinds($conn));
  });
}

function currUiControlTypes(): array {
  return currUiCache('controlTypes', static function () {
    $conn = planDB(getDBConnection());
    return array_map(static fn($c) => [
      'id'   => (int)$c['id'],
      'name' => (string)$c['name'],
    ], repoCurrControlTypes($conn));
  });
}

// Дисциплины: берём из справочника основной БД, подтягиваем id метаданной
// карточки плана (discipline_meta.id), если она уже заведена.
function currUiDisciplines(mysqli $mainConn): array {
  $planConn = planDB($mainConn);
  $dm = planTab('discipline_meta');
  $meta = [];
  foreach (dbAll($planConn, "SELECT discipline_id, id, department_id FROM {$dm} WHERE is_deleted = 0") as $m) {
    $meta[(int)$m['discipline_id']] = $m;
  }
  $deps = [];
  foreach (currUiDepartments() as $d) $deps[$d['id']] = $d['name'];

  $rows = dbAll($mainConn,
    "SELECT id, name FROM discipline WHERE is_deleted = 0 ORDER BY name");
  return array_map(static function ($r) use ($meta, $deps) {
    $id = (int)$r['id'];
    $m  = $meta[$id] ?? null;
    return [
      'id'          => $id,
      'name'        => (string)$r['name'],
      'metaId'      => $m ? (int)$m['id'] : null,
      'departmentId'=> $m && $m['department_id'] ? (int)$m['department_id'] : null,
      'departmentName' => $m && $m['department_id'] && isset($deps[(int)$m['department_id']])
        ? $deps[(int)$m['department_id']] : '',
    ];
  }, $rows);
}

// Группы основной БД с курсом (1..4) — для редактора часов по группам.
// Курс считаем от года поступления относительно текущего учебного года:
// поступившие в этом году — 1 курс, в прошлом — 2 курс и т.д.
function currAcademicYearStart(): int {
  $y = (int)date('Y');
  // С 1 сентября начинается новый учебный год.
  return (int)date('n') >= 9 ? $y : $y - 1;
}

function currUiGroups(mysqli $mainConn): array {
  return currUiCache('groups', static function () use ($mainConn) {
    $ayStart = currAcademicYearStart();
    return array_map(static function ($g) use ($ayStart) {
      $year = (int)($g['admission_year'] ?? 0);
      $course = $year > 0 ? max(1, min(8, $ayStart - $year + 1)) : 0;
      return [
        'id'            => (int)$g['id'],
        'name'          => (string)($g['short_name'] ?: $g['name']),
        'admissionYear' => $year ?: null,
        'course'        => $course,
      ];
    }, dbAll($mainConn,
        "SELECT id, name, short_name, admission_year FROM student_group
         WHERE is_deleted = 0 ORDER BY name"));
  });
}

// Сброс позапросного кэша. Вызывается в начале каждого обращения к
// curriculumController(): под mod_php процесс переиспользуется между
// запросами, и без сброса после CRUD-операций окно показывало бы
// устаревшие кафедры/виды деятельности/группы.
function currCacheReset() {
  $GLOBALS['__curr_cache'] = [];
}

// Карточка плана для формы: часы -> [{groupId, activityKindId, hours}],
// контроль -> [{controlTypeId, terms:[..]}].
function currUiCard(mysqli $planConn, int $id): array {
  $card = repoCurrCard($planConn, $id);
  $hours = [];
  foreach (($card['hours'] ?? []) as $h) {
    $hours[] = [
      'groupId'        => $h['group_id'] !== null ? (int)$h['group_id'] : null,
      'activityKindId' => (int)$h['activity_kind_id'],
      'hours'          => (float)$h['hours'],
    ];
  }
  $ctrl = [];
  foreach (($card['controls'] ?? []) as $c) {
    $ctId = (int)$c['control_type_id'];
    $t    = (int)$c['term'];
    if (!isset($ctrl[$ctId])) $ctrl[$ctId] = ['controlTypeId' => $ctId, 'terms' => []];
    $ctrl[$ctId]['terms'][] = $t;
  }
  return [
    'id'             => (int)$card['id'],
    'disciplineMetaId' => (int)$card['discipline_id'],
    'disciplineId'   => (int)($card['discipline_id'] ?? 0),
    'disciplineName' => (string)($card['discipline_name'] ?? ''),
    'course'         => (int)$card['course'],
    'term'           => (int)$card['term'],
    'totalHours'     => (float)($card['total_hours'] ?? 0),
    'comment'        => (string)($card['comment'] ?? ''),
    'hours'          => $hours,
    'controls'       => array_values($ctrl),
  ];
}
// Сохранение карточки из UI: camelCase-ключи -> формат repoCurrSave.
function currUiNormalizeCardBody($body): array {
  return [
    'id'               => (int)($body['id'] ?? 0),
    'discipline_meta_id' => (int)($body['disciplineMetaId'] ?? $body['discipline_meta_id'] ?? 0),
    'course'           => (int)($body['course'] ?? 0),
    'term'             => (int)($body['term'] ?? 0),
    'total_hours'      => (float)($body['totalHours'] ?? $body['total_hours'] ?? 0),
    'comment'          => (string)($body['comment'] ?? ''),
    'hours'            => array_map(static fn($h) => [
      'group_id'         => isset($h['groupId']) && $h['groupId'] ? (int)$h['groupId'] : null,
      'activity_kind_id' => (int)($h['activityKindId'] ?? $h['activity_kind_id'] ?? 0),
      'hours'            => (float)($h['hours'] ?? 0),
    ], is_array($body['hours'] ?? null) ? $body['hours'] : []),
    'controls'         => array_map(static fn($c) => [
      'control_type_id' => (int)($c['controlTypeId'] ?? $c['control_type_id'] ?? 0),
      'terms'           => is_array($c['terms'] ?? null) ? $c['terms'] : [],
    ], is_array($body['controls'] ?? null) ? $body['controls'] : []),
  ];
}

function curriculumController($method, $entity) {
  // Кэш справочников живёт только в рамках одного HTTP-запроса.
  currCacheReset();

  // Все таблицы живут в базе учебного плана — получаем её соединение
  // (с авто-созданием схемы и справочников по умолчанию).
  $mainConn = getDBConnection();
  $conn = planDB($mainConn);

  try {
    switch ($entity) {

      // ---------- UI-эндпоинты окна «📚 Учебный план» ----------

      case 'curr_ui_meta': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        sendJson([
          'departments'   => currUiDepartments(),
          'activityKinds' => currUiActivityKinds(),
          'controlTypes'  => currUiControlTypes(),
          'disciplines'   => currUiDisciplines($mainConn),
          'groups'        => currUiGroups($mainConn),
        ]);
        return;
      }

      // Дисциплина плана: создать (из справочника основной БД или новой
      // строкой) / назначить кафедру / удалить (soft).
      case 'curr_discipline': {
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody() ?? [];
          $id = (int)($body['id'] ?? 0); // id discipline_meta
          $disciplineId = (int)($body['disciplineId'] ?? $body['discipline_id'] ?? 0);

          // Если передали name вместо выбора из справочника — заводим дисциплину.
          if ($disciplineId <= 0 && trim((string)($body['name'] ?? '')) !== '') {
            $newName = trim((string)$body['name']);
            $exists = (int)dbScalar($mainConn,
              "SELECT id FROM discipline WHERE name = ? AND is_deleted = 0", [$newName], 0);
            if ($exists > 0) {
              $disciplineId = $exists;
            } else {
              $disciplineId = dbInsert($mainConn,
                "INSERT INTO discipline (name, short_name) VALUES (?, ?)",
                [$newName, trim((string)($body['shortName'] ?? '')) ?: $newName]);
            }
          }
          $body['discipline_id'] = $disciplineId;
          $send = ['success' => true,
                   'id' => repoCurrDisciplineSave($conn, $id ?: null, $body)];
          sendJson($send);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrDisciplineDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      // Список карточек плана для таблицы окна.
      case 'curr_cards': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        $rows = repoCurrList(
          $conn,
          (int)getQuery('course', 0),
          (int)getQuery('term', 0),
          (int)getQuery('department_id', 0),
          trim((string)getQuery('search', ''))
        );
        sendJson(array_map(static fn($r) => [
          'id'             => (int)$r['id'],
          'disciplineName' => (string)$r['discipline_name'],
          'departmentName' => (string)($r['department_name'] ?? ''),
          'departmentId'   => $r['department_id'] !== null ? (int)$r['department_id'] : null,
          'course'         => (int)$r['course'],
          'term'           => (int)$r['term'],
          'totalHours'     => (float)($r['total_hours'] ?? 0),
          'comment'        => (string)($r['comment'] ?? ''),
          // сводка часов по видам деятельности РАЗДЕЛЬНО ПО ГРУППАМ
          // (строка с group_id = NULL — «Все группы»)
          'hoursSummary'   => array_values(array_reduce(
            $r['hours'] ?? [],
            static function ($acc, $h) {
              $gid  = isset($h['group_id']) && $h['group_id'] !== null
                        ? (int)$h['group_id'] : 0;
              $k    = $gid . '|' . (int)$h['activity_kind_id'];
              if (!isset($acc[$k])) {
                $acc[$k] = ['groupId' => $gid ?: null,
                            'groupName' => (string)($h['group_name'] ?? ''),
                            'kindId' => (int)$h['activity_kind_id'],
                            'kindName' => (string)$h['kind_name'],
                            'shortName' => (string)($h['short_name'] ?? ''),
                            'hours' => 0.0];
              }
              $acc[$k]['hours'] += (float)$h['hours'];
              return $acc;
            },
            []
          )),
          'controlsSummary' => array_values(array_reduce(
            $r['controls'] ?? [],
            static function ($acc, $c) {
              $k = (int)$c['control_type_id'];
              if (!isset($acc[$k])) {
                $acc[$k] = ['controlTypeId' => $k, 'name' => (string)$c['name'], 'terms' => []];
              }
              $acc[$k]['terms'][] = (int)$c['term'];
              return $acc;
            },
            []
          )),
        ], $rows));
        return;
      }

      // Одна карточка для формы редактирования.
      case 'curr_card': {
        if ($method === 'GET') {
          sendJson(currUiCard($conn, (int)getQuery('id', 0)));
          return;
        }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody();
          if (!$body) errorJson('Invalid JSON body', 400);
          $id = repoCurrSave($conn, currUiNormalizeCardBody($body));
          sendJson(['success' => true, 'id' => $id, 'card' => currUiCard($conn, $id)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      // ---------- низкоуровневые эндпоинты (для интеграций) ----------

      case 'curriculum_meta': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        sendJson([
          'departments'    => repoCurrDepartments($conn),
          'activity_kinds' => repoCurrActivityKinds($conn),
          'control_types'  => repoCurrControlTypes($conn),
          'disciplines'    => repoCurrDisciplines($conn),
        ]);
        return;
      }

      case 'curriculum_department': {
        if ($method === 'GET') { sendJson(repoCurrDepartments($conn)); return; }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody() ?? [];
          $id = (int)($body['id'] ?? 0);
          sendJson(['success' => true, 'id' => repoCurrDepartmentSave($conn, $id ?: null, $body)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrDepartmentDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      case 'curriculum_activity': {
        if ($method === 'GET') { sendJson(repoCurrActivityKinds($conn)); return; }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody() ?? [];
          $id = (int)($body['id'] ?? 0);
          sendJson(['success' => true, 'id' => repoCurrActivityKindSave($conn, $id ?: null, $body)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrActivityKindDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      case 'curriculum_control_type': {
        if ($method === 'GET') { sendJson(repoCurrControlTypes($conn)); return; }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody() ?? [];
          $id = (int)($body['id'] ?? 0);
          sendJson(['success' => true, 'id' => repoCurrControlTypeSave($conn, $id ?: null, $body)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrControlTypeDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      case 'curriculum_discipline': {
        if ($method === 'GET') { sendJson(repoCurrDisciplines($conn)); return; }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody() ?? [];
          $id = (int)($body['id'] ?? 0);
          sendJson(['success' => true, 'id' => repoCurrDisciplineSave($conn, $id ?: null, $body)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrDisciplineDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      case 'curriculum_list': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        sendJson(repoCurrList(
          $conn,
          (int)getQuery('course', 0),
          (int)getQuery('term', 0),
          (int)getQuery('department_id', 0),
          trim((string)getQuery('search', ''))
        ));
        return;
      }

      case 'curriculum_card': {
        if ($method === 'GET') {
          sendJson(repoCurrCard($conn, (int)getQuery('id', 0)));
          return;
        }
        if ($method === 'POST' || $method === 'PUT') {
          $body = getJsonBody();
          if (!$body) errorJson('Invalid JSON body', 400);
          sendJson(['success' => true, 'id' => repoCurrSave($conn, $body)]);
          return;
        }
        if ($method === 'DELETE') {
          repoCurrDelete($conn, (int)getQuery('id', 0));
          sendJson(['success' => true]);
          return;
        }
        errorJson('Method not allowed', 405);
      }

      case 'curriculum_grid': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        $groupId = (int)getQuery('group_id', 0);
        $term    = (int)getQuery('term', 0);
        if ($groupId <= 0 || $term <= 0) errorJson('group_id and term required', 400);
        sendJson(repoCurrGroupTermGrid($conn, $groupId, $term));
        return;
      }

      case 'curriculum_controls': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        $groupId = (int)getQuery('group_id', 0);
        $term    = (int)getQuery('term', 0);
        if ($term <= 0) errorJson('term required', 400);
        sendJson(repoCurrGroupTermControls($conn, $groupId, $term));
        return;
      }

      // Нарушения учебного плана для сетки расписания (красная подсветка
      // занятий и предупреждение под таблицей). week_ids/group_ids — списки
      // через запятую. При недоступной базе плана — пустой ответ.
      case 'curr_violations': {
        if ($method !== 'GET') errorJson('Method not allowed', 405);
        $weekIds  = array_filter(array_map('intval', explode(',', (string)getQuery('week_ids', ''))));
        $groupIds = array_filter(array_map('intval', explode(',', (string)getQuery('group_ids', ''))));
        if (!$weekIds || !$groupIds) { sendJson(['violations' => [], 'groups' => []]); return; }
        try {
          sendJson(repoCurrWeekViolations($conn, $mainConn, $weekIds, $groupIds));
        } catch (Throwable $e) {
          error_log('curr_violations skipped: ' . $e->getMessage());
          sendJson(['violations' => [], 'groups' => []]);
        }
        return;
      }
    }

    errorJson('Unknown curriculum entity', 404);

  } catch (Exception $e) {
    // Бизнес-ошибка (дубликат карточки, нет прав и т.п.) — не 500.
    errorJson($e->getMessage(), 409);
  }
}
