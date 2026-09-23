<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/room_prefs.repo.php';

function roomPrefsController($conn, $method, $entity) {
  try {
    if ($entity === 'room_prefs_all') {
      if ($method !== 'GET') errorJson('Method not allowed', 405);
      sendJson(repoGetRoomPrefsAll($conn));
    }

    if ($entity === 'room_prefs') {
      if ($method === 'GET') {
        $subjectId = (int)getQuery('subject_id', 0);
        if ($subjectId <= 0) errorJson('subject_id required', 400);
        sendJson(repoGetRoomPrefsBySubject($conn, $subjectId));
      }

      if ($method === 'POST' || $method === 'PUT') {
        $body = getJsonBody();
        if (!$body) errorJson('Invalid JSON body', 400);

        $subjectId = (int)($body['subject_id'] ?? $body['subjectId'] ?? 0);
        if ($subjectId <= 0) errorJson('subject_id required', 400);

        $prefs = $body['prefs'] ?? [];
        if (!is_array($prefs)) errorJson('prefs must be array', 400);

        sendJson(repoSaveRoomPrefs($conn, $subjectId, $prefs));
      }

      errorJson('Method not allowed', 405);
    }

    errorJson('Unknown entity', 404);
  } catch (Exception $e) {
    errorJson($e->getMessage(), 409);
  }
}
