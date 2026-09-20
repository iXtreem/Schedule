<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/teachers.repo.php';

function teachersController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetTeachers($conn));
}
