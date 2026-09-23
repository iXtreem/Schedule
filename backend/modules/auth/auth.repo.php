<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoAuthUsersCount($conn) {
  $sql = "SELECT COUNT(*) AS cnt FROM TB_AppUser WHERE IsDeleted = 0";
  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));
  $row = odbc_fetch_array($res);
  return (int)($row['cnt'] ?? 0);
}

function repoAuthFindUserByLogin($conn, $login) {
  $sql = "
    SELECT TOP 1
      idUser AS id,
      LoginName AS login_name,
      PasswordHash AS password_hash
    FROM TB_AppUser
    WHERE IsDeleted = 0
      AND LoginName = ?
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$login])) throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  if (!$row) return null;
  return convertToUtf8($row);
}

function repoAuthCreateUser($conn, $login, $passwordHash) {
  $sql = "
    INSERT INTO TB_AppUser (LoginName, PasswordHash, IsDeleted, CreatedAt, UpdatedAt)
    OUTPUT INSERTED.idUser AS id
    VALUES (?, ?, 0, GETDATE(), GETDATE())
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [$login, $passwordHash])) throw new Exception(odbc_errormsg($conn));

  $row = odbc_fetch_array($st);
  if (is_array($row)) {
    foreach ($row as $value) {
      if (is_numeric($value)) {
        return (int)$value;
      }
    }
  }

  // Fallback: если драйвер не вернул OUTPUT-строку, достаём ID по логину.
  $created = repoAuthFindUserByLogin($conn, $login);
  return (int)($created['id'] ?? 0);
}
