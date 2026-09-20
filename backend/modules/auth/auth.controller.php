<?php
require_once __DIR__ . '/../../lib/response.php';
require_once __DIR__ . '/../../lib/request.php';
require_once __DIR__ . '/auth.repo.php';

function authCurrentUser() {
  $user = $_SESSION['auth_user'] ?? null;
  if (!is_array($user)) return null;
  return [
    'id' => (int)($user['id'] ?? 0),
    'login' => (string)($user['login'] ?? ''),
  ];
}

function authLogoutLocalSession() {
  $_SESSION = [];

  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params['path'],
      $params['domain'],
      (bool)$params['secure'],
      (bool)$params['httponly']
    );
  }

  session_destroy();
}

function authController($conn, $method, $entity) {
  if ($entity === 'auth_me') {
    if ($method !== 'GET') errorJson('Метод не поддерживается', 405);

    $hasUsers = repoAuthUsersCount($conn) > 0;
    $user = authCurrentUser();

    sendJson([
      'success' => true,
      'authenticated' => $user !== null && $user['id'] > 0,
      'has_users' => $hasUsers,
      'user' => $user,
    ]);
  }

  if ($entity === 'auth_login') {
    if ($method !== 'POST') errorJson('Метод не поддерживается', 405);

    $body = getJsonBody();
    if (!$body) errorJson('Некорректное JSON-тело запроса', 400);

    $login = trim((string)($body['login'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($login === '' || $password === '') {
      errorJson('Логин и пароль обязательны', 400);
    }

    $user = repoAuthFindUserByLogin($conn, $login);
    if (!$user) errorJson('Неверный логин или пароль', 401);

    $hash = (string)($user['password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
      errorJson('Неверный логин или пароль', 401);
    }

    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
      'id' => (int)$user['id'],
      'login' => (string)$user['login_name'],
    ];

    sendJson([
      'success' => true,
      'user' => authCurrentUser(),
    ]);
  }

  if ($entity === 'auth_logout') {
    if ($method !== 'POST') errorJson('Метод не поддерживается', 405);

    authLogoutLocalSession();
    sendJson(['success' => true]);
  }

  if ($entity === 'auth_register_first') {
    if ($method !== 'POST') errorJson('Метод не поддерживается', 405);

    if (repoAuthUsersCount($conn) > 0) {
      errorJson('Первый пользователь уже создан. Регистрация отключена.', 409);
    }

    $body = getJsonBody();
    if (!$body) errorJson('Некорректное JSON-тело запроса', 400);

    $login = trim((string)($body['login'] ?? ''));
    $password = (string)($body['password'] ?? '');

    if ($login === '' || $password === '') {
      errorJson('Логин и пароль обязательны', 400);
    }
    if (mb_strlen($password, 'UTF-8') < 6) {
      errorJson('Пароль должен содержать минимум 6 символов', 400);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
      errorJson('Не удалось зашифровать пароль', 500);
    }

    $newId = repoAuthCreateUser($conn, $login, $hash);
    if ($newId <= 0) errorJson('Не удалось создать пользователя', 500);

    session_regenerate_id(true);
    $_SESSION['auth_user'] = [
      'id' => $newId,
      'login' => $login,
    ];

    sendJson([
      'success' => true,
      'user' => authCurrentUser(),
    ], 201);
  }

  errorJson('Неизвестный auth-запрос', 404);
}
