<?php
require_once __DIR__ . '/../../lib/db.php';

// Количество незарегистрированных (не удалённых) пользователей
function repoAuthUsersCount($conn) {
  return (int)dbScalar(
    $conn,
    "SELECT COUNT(*) FROM TB_AppUser WHERE IsDeleted = 0",
    [],
    0
  );
}

function repoAuthFindUserByLogin($conn, $login) {
  return dbRow(
    $conn,
    "SELECT
        idUser AS id,
        LoginName AS login_name,
        PasswordHash AS password_hash
     FROM TB_AppUser
     WHERE IsDeleted = 0 AND LoginName = ?
     LIMIT 1",
    [$login]
  );
}

function repoAuthCreateUser($conn, $login, $passwordHash) {
  return dbInsert(
    $conn,
    "INSERT INTO TB_AppUser (LoginName, PasswordHash, IsDeleted, CreatedAt, UpdatedAt)
     VALUES (?, ?, 0, NOW(), NOW())",
    [$login, $passwordHash]
  );
}
