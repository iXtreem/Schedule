<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/subjects.repo.php';

function subjectsController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetSubjects($conn));
}
