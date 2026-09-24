
<?php
require_once __DIR__ . '/../../lib/db.php';

// Количество незарегистрированных (не удалённых) пользователей
// Таблица user — новая схема (бывшая TB_AppUser), колонки в snake_case
function repoAuthUsersCount($conn) {
  return (int)dbScalar(
    $conn,
    "SELECT COUNT(*) FROM user WHERE is_deleted = 0",
    [],
    0
  );
}

// Поиск пользователя по логину (для проверки пароля при входе)
function repoAuthFindUserByLogin($conn, $login) {
  return dbRow(
    $conn,
    "SELECT
        id,
        login AS login_name,
        password_hash
     FROM user
     WHERE is_deleted = 0 AND login = ?
     LIMIT 1",
    [$login]
  );
}

// Создание первого (администратора) пользователя
function repoAuthCreateUser($conn, $login, $passwordHash) {
  return dbInsert(
    $conn,
    "INSERT INTO user (login, password_hash, is_deleted, created_at, updated_at)
     VALUES (?, ?, 0, NOW(), NOW())",
    [$login, $passwordHash]
  );
}
