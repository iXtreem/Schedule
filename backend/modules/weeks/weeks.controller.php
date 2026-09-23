<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/weeks.repo.php';

function weeksController($conn, $method) {
  if ($method === 'GET') {
    sendJson(repoGetWeeks($conn));
  }

  if ($method === 'POST') {
    $body = getJsonBody();
    if (!$body) errorJson('Invalid JSON body', 400);

    $name = trim($body['name'] ?? '');
    $start = $body['start_date'] ?? '';
    $end = $body['end_date'] ?? '';
    if (!$name || !$start || !$end) errorJson('name/start_date/end_date required', 400);

    $id = repoAddWeek($conn, $name, $start, $end);
    sendJson(['success' => true, 'id' => $id], 201);
  }

  errorJson('Method not allowed', 405);
}
