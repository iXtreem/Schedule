<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/teachers.repo.php';

function teachersController($conn, $method) {
  // POST ?entity=teachers — сохранение лимитов часов (вкладка
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

  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetTeachers($conn));
}