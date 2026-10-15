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
 */
function curriculumController($method, $entity) {
  // Все таблицы живут в базе учебного плана — получаем её соединение
  // (с авто-созданием схемы и справочников по умолчанию).
  $mainConn = getDBConnection();
  $conn = planDB($mainConn);

  try {
    switch ($entity) {

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
    }

    errorJson('Unknown curriculum entity', 404);

  } catch (Exception $e) {
    // Бизнес-ошибка (дубликат карточки, нет прав и т.п.) — не 500.
    errorJson($e->getMessage(), 409);
  }
}
