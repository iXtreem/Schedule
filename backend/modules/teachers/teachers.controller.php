<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/teachers.repo.php';

function teachersController($conn, $method) {
  // POST ?entity=teachers&mode=disciplines — сохранение списка дисциплин,
  // которые может вести преподаватель (окно «⚡ Условия заполнения» →
  // вкладка «Преподаватели», кнопка «Дисциплины»).
  // Тело: { items: [{ teacher_id, discipline_ids: [..] }] }
  if ($method === 'POST' && getQuery('mode', '') === 'disciplines') {
    $body = getJsonBody();
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
      errorJson('Ожидается JSON-тело вида { items: [{ teacher_id, discipline_ids }] }', 400);
    }
    $saved = repoSaveTeacherDisciplines($conn, $body['items']);
    sendJson(['success' => true, 'saved' => $saved]);
    return;
  }

  // GET ?entity=teachers&mode=disciplines — все разрешения
  // Ответ: [{ teacher_id, discipline_ids: [..] }, ...]
  if ($method === 'GET' && getQuery('mode', '') === 'disciplines') {
    sendJson(repoGetTeacherDisciplines($conn));
    return;
  }

  // POST ?entity=teachers — сохранение лимитов часов и графика работы (вкладка
  // «Преподаватели» окна «Автозаполнение»).
  if ($method === 'POST') {
    $body = getJsonBody();
    if (!is_array($body) || !isset($body['items']) || !is_array($body['items'])) {
      errorJson('Ожидается JSON-тело вида { items: [{ id, max_hours }] }', 400);
    }
    $updated = repoSaveTeacherHours($conn, $body['items']);
    sendJson(['success' => true, 'updated' => $updated]);
    return;
  }

  // GET ?entity=teachers&load=week[&week_id=N] — недельная нагрузка
  // преподавателей (для подсветки в модалке занятия: зелёный/красный).
  if ($method === 'GET' && getQuery('load', '') === 'week') {
    $weekId = (int)getQuery('week_id', 0);
    sendJson(repoGetTeacherWeeklyLoad($conn, $weekId > 0 ? $weekId : null));
    return;
  }

  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetTeachers($conn));
}