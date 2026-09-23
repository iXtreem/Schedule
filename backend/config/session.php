<?php
if (session_status() === PHP_SESSION_ACTIVE) {
  return;
}

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'domain' => '',
  'secure' => $isHttps,
  'httponly' => true,
  'samesite' => 'Lax',
]);

session_start();
