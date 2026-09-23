<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/groups.repo.php';

function groupsController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);
  $groups = repoGetGroups($conn);
  sendJson($groups);
}

