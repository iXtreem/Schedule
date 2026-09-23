<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/lessons.repo.php';

function scheduleForWeekController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);

  $weekId = (int)getQuery('week_id', 0);
  if ($weekId <= 0) errorJson('week_id required', 400);

  sendJson(repoGetScheduleForWeek($conn, $weekId));
}

function scheduleLessonsController($conn, $method) {
  try {
    if ($method === 'POST') {
      $body = getJsonBody();
      if (!$body) errorJson('Invalid JSON body', 400);

      $newId = repoCreateLesson($conn, $body);
      sendJson(['success' => true, 'id' => $newId], 201);
    }

    if ($method === 'PUT') {
      $id = (int)getQuery('id', 0);
      if ($id <= 0) errorJson('id required', 400);

      $body = getJsonBody();
      if (!$body) errorJson('Invalid JSON body', 400);

      repoUpdateLesson($conn, $id, $body);
      sendJson(['success' => true]);
    }

    if ($method === 'DELETE') {
      $id = (int)getQuery('id', 0);
      if ($id <= 0) errorJson('id required', 400);

      repoSoftDeleteLesson($conn, $id);
      sendJson(['success' => true]);
    }

    errorJson('Method not allowed', 405);

  } catch (Exception $e) {
    //это бизнес-ошибка (лимит часов и т.п.) не 500
    errorJson($e->getMessage(), 409);
  }
}
