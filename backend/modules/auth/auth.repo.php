<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoAuthUsersCount($conn) {
  $sql = "SELECT COUNT(*) AS cnt FROM TB_AppUser WHERE IsDeleted = 0";
  $res = $conn->query($sql);
  if (!$res) throw new Exception($conn->error);
  $row = $res->fetch_assoc();
  return (int)($row['cnt'] ?? 0);
}

function repoAuthFindUserByLogin($conn, $login) {
  $sql = "
    SELECT
      idUser AS id,
      LoginName AS login_name,
      PasswordHash AS password_hash
    FROM TB_AppUser
    WHERE IsDeleted = 0
      AND LoginName = ?
    LIMIT 1
  ";

  $st = $conn->prepare($sql);
  if (!$st) throw new Exception($conn->error);
  $st->bind_param("s", $login);
  if (!$st->execute()) throw new Exception($st->error);

  $result = $st->get_result();
  $row = $result->fetch_assoc();
  $st->close();
  
  if (!$row) return null;
  return convertToUtf8($row);
}

function repoAuthCreateUser($conn, $login, $passwordHash) {
  $sql = "
    INSERT INTO TB_AppUser (LoginName, PasswordHash, IsDeleted, CreatedAt, UpdatedAt)
    VALUES (?, ?, 0, NOW(), NOW())
  ";

  $st = $conn->prepare($sql);
  if (!$st) throw new Exception($conn->error);
  $st->bind_param("ss", $login, $passwordHash);
  if (!$st->execute()) throw new Exception($st->error);
  
  $id = $conn->insert_id;
  $st->close();

  return (int)$id;
}
?>