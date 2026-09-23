<?php
function getQuery($key, $default = null) {
  return $_GET[$key] ?? $default;
}

function getJsonBody() {
  $raw = file_get_contents('php://input');
  if (!$raw) return null;
  $data = json_decode($raw, true);
  return is_array($data) ? $data : null;
}
