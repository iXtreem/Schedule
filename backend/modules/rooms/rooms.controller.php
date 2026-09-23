<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/rooms.repo.php';

function roomsController($conn, $method) {
  if ($method !== 'GET') errorJson('Method not allowed', 405);
  sendJson(repoGetRooms($conn));
}
