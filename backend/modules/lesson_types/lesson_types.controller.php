<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/lesson_types.repo.php';

function lessonTypesController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetLessonTypes($conn));
}
