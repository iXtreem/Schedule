<?php
require_once __DIR__ . '/../../lib/utf8.php';

function repoEnsureRoomPrefsTable($conn) {
  $sql = "
    IF OBJECT_ID('dbo.TB_RoomPref', 'U') IS NULL
    BEGIN
      CREATE TABLE dbo.TB_RoomPref (
        idRoomPref INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        idDiscipl INT NOT NULL,
        idRoom INT NOT NULL,
        [Priority] INT NOT NULL CONSTRAINT DF_TB_RoomPref_Priority DEFAULT(100),
        IsPrimary BIT NOT NULL CONSTRAINT DF_TB_RoomPref_IsPrimary DEFAULT(0),
        IsDeleted BIT NOT NULL CONSTRAINT DF_TB_RoomPref_IsDeleted DEFAULT(0),
        CreatedAt DATETIME NOT NULL CONSTRAINT DF_TB_RoomPref_CreatedAt DEFAULT(GETDATE()),
        UpdatedAt DATETIME NULL
      );
    END
  ";

  $res = @odbc_exec($conn, $sql);
  if (!$res) throw new Exception("RoomPref table init failed: " . odbc_errormsg($conn));
}

function repoNormalizeRoomPrefs(array $prefs): array {
  $out = [];
  $seen = [];

  foreach ($prefs as $row) {
    $roomId = (int)($row['roomId'] ?? $row['room_id'] ?? $row['idRoom'] ?? 0);
    if ($roomId <= 0) continue;
    if (isset($seen[$roomId])) continue;

    $priority = (int)($row['priority'] ?? 100);
    if ($priority < 0) $priority = 100;

    $isPrimary = (int)($row['isPrimary'] ?? $row['is_primary'] ?? 0);
    $isPrimary = $isPrimary === 1 ? 1 : 0;

    $seen[$roomId] = true;
    $out[] = [
      'room_id' => $roomId,
      'priority' => $priority,
      'is_primary' => $isPrimary,
    ];
  }

  if (count($out) > 0) {
    $hasPrimary = false;
    foreach ($out as $item) {
      if ($item['is_primary'] === 1) {
        $hasPrimary = true;
        break;
      }
    }
    if (!$hasPrimary) $out[0]['is_primary'] = 1;

    $primarySeen = false;
    foreach ($out as &$item) {
      if ($item['is_primary'] === 1 && !$primarySeen) {
        $primarySeen = true;
        continue;
      }
      if ($item['is_primary'] === 1 && $primarySeen) {
        $item['is_primary'] = 0;
      }
    }
    unset($item);
  }

  return $out;
}

function repoGetRoomPrefsBySubject($conn, $subjectId) {
  repoEnsureRoomPrefsTable($conn);

  $sql = "
    SELECT
      rp.idRoomPref AS id,
      rp.idDiscipl AS subject_id,
      rp.idRoom AS room_id,
      (RTRIM(r.Building) + '-' + RTRIM(r.RoomNumber)) AS room_name,
      CAST(rp.[Priority] AS INT) AS priority,
      CAST(rp.IsPrimary AS INT) AS is_primary
    FROM TB_RoomPref rp
    JOIN TB_Room r ON r.idRoom = rp.idRoom AND r.IsDeleted = 0
    WHERE rp.IsDeleted = 0
      AND rp.idDiscipl = ?
    ORDER BY rp.IsPrimary DESC, rp.[Priority] ASC, r.Building, r.RoomNumber
  ";

  $st = odbc_prepare($conn, $sql);
  if (!$st) throw new Exception(odbc_errormsg($conn));
  if (!odbc_execute($st, [(int)$subjectId])) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($st)) $data[] = convertToUtf8($row);
  return $data;
}

function repoGetRoomPrefsAll($conn) {
  repoEnsureRoomPrefsTable($conn);

  $sql = "
    SELECT
      rp.idDiscipl AS subject_id,
      d.DisciplName AS subject_name,
      rp.idRoom AS room_id,
      (RTRIM(r.Building) + '-' + RTRIM(r.RoomNumber)) AS room_name,
      CAST(rp.[Priority] AS INT) AS priority,
      CAST(rp.IsPrimary AS INT) AS is_primary
    FROM TB_RoomPref rp
    JOIN TB_Discipl d ON d.idDiscipl = rp.idDiscipl AND d.DisciplDeleted = 0
    JOIN TB_Room r ON r.idRoom = rp.idRoom AND r.IsDeleted = 0
    WHERE rp.IsDeleted = 0
    ORDER BY d.DisciplName, rp.IsPrimary DESC, rp.[Priority] ASC, r.Building, r.RoomNumber
  ";

  $res = odbc_exec($conn, $sql);
  if (!$res) throw new Exception(odbc_errormsg($conn));

  $data = [];
  while ($row = odbc_fetch_array($res)) $data[] = convertToUtf8($row);
  return $data;
}

function repoSaveRoomPrefs($conn, $subjectId, array $prefs) {
  repoEnsureRoomPrefsTable($conn);
  $subjectId = (int)$subjectId;
  if ($subjectId <= 0) throw new Exception("subject_id required");

  $normalized = repoNormalizeRoomPrefs($prefs);

  odbc_autocommit($conn, false);
  try {
    $sqlDelete = "
      UPDATE TB_RoomPref
      SET IsDeleted = 1, UpdatedAt = GETDATE()
      WHERE idDiscipl = ? AND IsDeleted = 0
    ";
    $stDel = odbc_prepare($conn, $sqlDelete);
    if (!$stDel) throw new Exception(odbc_errormsg($conn));
    if (!odbc_execute($stDel, [$subjectId])) throw new Exception(odbc_errormsg($conn));

    if (count($normalized) > 0) {
      $sqlInsert = "
        INSERT INTO TB_RoomPref (idDiscipl, idRoom, [Priority], IsPrimary, IsDeleted)
        VALUES (?, ?, ?, ?, 0)
      ";
      $stIns = odbc_prepare($conn, $sqlInsert);
      if (!$stIns) throw new Exception(odbc_errormsg($conn));

      foreach ($normalized as $row) {
        $ok = odbc_execute($stIns, [
          $subjectId,
          (int)$row['room_id'],
          (int)$row['priority'],
          (int)$row['is_primary'],
        ]);
        if (!$ok) throw new Exception(odbc_errormsg($conn));
      }
    }

    odbc_commit($conn);
    odbc_autocommit($conn, true);
    return ['success' => true, 'subject_id' => $subjectId, 'count' => count($normalized)];
  } catch (Exception $e) {
    odbc_rollback($conn);
    odbc_autocommit($conn, true);
    throw $e;
  }
}
