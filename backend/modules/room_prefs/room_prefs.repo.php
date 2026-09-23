<?php
require_once __DIR__ . '/../../lib/db.php';

// Создаёт таблицу предпочтений аудиторий, если её ещё нет (MySQL-диалект)
function repoEnsureRoomPrefsTable($conn) {
  static $checked = false;
  if ($checked) return;

  $conn->query("
    CREATE TABLE IF NOT EXISTS TB_RoomPref (
      idRoomPref INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      idDiscipl  INT NOT NULL,
      idRoom     INT NOT NULL,
      Priority   INT NOT NULL DEFAULT 100,
      IsPrimary  TINYINT(1) NOT NULL DEFAULT 0,
      IsDeleted  TINYINT(1) NOT NULL DEFAULT 0,
      CreatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UpdatedAt  DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $checked = true;
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

function roomPrefsSelectSql() {
  return "
    SELECT
      rp.idRoomPref AS id,
      rp.idDiscipl AS subject_id,
      rp.idRoom AS room_id,
      CONCAT(TRIM(r.Building), '-', TRIM(r.RoomNumber)) AS room_name,
      CAST(rp.Priority AS SIGNED) AS priority,
      CAST(rp.IsPrimary AS SIGNED) AS is_primary
    FROM TB_RoomPref rp
    JOIN TB_Room r ON r.idRoom = rp.idRoom AND r.IsDeleted = 0
  ";
}

function repoGetRoomPrefsBySubject($conn, $subjectId) {
  repoEnsureRoomPrefsTable($conn);

  return dbAll(
    $conn,
    roomPrefsSelectSql() . "
    WHERE rp.IsDeleted = 0
      AND rp.idDiscipl = ?
    ORDER BY rp.IsPrimary DESC, rp.Priority ASC, r.Building, r.RoomNumber",
    [(int)$subjectId]
  );
}

function repoGetRoomPrefsAll($conn) {
  repoEnsureRoomPrefsTable($conn);

  return dbAll(
    $conn,
    "SELECT
        rp.idDiscipl AS subject_id,
        d.DisciplName AS subject_name,
        rp.idRoom AS room_id,
        CONCAT(TRIM(r.Building), '-', TRIM(r.RoomNumber)) AS room_name,
        CAST(rp.Priority AS SIGNED) AS priority,
        CAST(rp.IsPrimary AS SIGNED) AS is_primary
     FROM TB_RoomPref rp
     JOIN TB_Discipl d ON d.idDiscipl = rp.idDiscipl AND d.DisciplDeleted = 0
     JOIN TB_Room r ON r.idRoom = rp.idRoom AND r.IsDeleted = 0
     WHERE rp.IsDeleted = 0
     ORDER BY d.DisciplName, rp.IsPrimary DESC, rp.Priority ASC, r.Building, r.RoomNumber"
  );
}

function repoSaveRoomPrefs($conn, $subjectId, array $prefs) {
  repoEnsureRoomPrefsTable($conn);
  $subjectId = (int)$subjectId;
  if ($subjectId <= 0) throw new Exception("subject_id required");

  $normalized = repoNormalizeRoomPrefs($prefs);

  $conn->begin_transaction();
  try {
    dbExec(
      $conn,
      "UPDATE TB_RoomPref SET IsDeleted = 1, UpdatedAt = NOW()
       WHERE idDiscipl = ? AND IsDeleted = 0",
      [$subjectId]
    );

    foreach ($normalized as $row) {
      dbInsert(
        $conn,
        "INSERT INTO TB_RoomPref (idDiscipl, idRoom, Priority, IsPrimary, IsDeleted)
         VALUES (?, ?, ?, ?, 0)",
        [$subjectId, (int)$row['room_id'], (int)$row['priority'], (int)$row['is_primary']]
      );
    }

    $conn->commit();
    return ['success' => true, 'subject_id' => $subjectId, 'count' => count($normalized)];
  } catch (Throwable $e) {
    $conn->rollback();
    throw new Exception($e->getMessage());
  }
}
