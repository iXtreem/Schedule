<?php
function sendJson($data, $status = 200) {
  http_response_code($status);
  $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  if ($json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'JSON encode failed', 'details' => json_last_error_msg()]);
    exit;
  }
  echo $json;
  exit;
}

function errorJson($message, $status = 400) {
  sendJson(['success' => false, 'error' => $message], $status);
}
