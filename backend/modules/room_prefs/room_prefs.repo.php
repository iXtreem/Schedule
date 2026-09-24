
<?php
require_once __DIR__ . '/../../lib/db.php';

// Создаёт таблицу предпочтений аудиторий, если её ещё нет (новая схема: room_preference)
function repoEnsureRoomPrefsTable($conn) {
  static $checked = false;
  if ($checked) return;

  $conn->query("
    CREATE TABLE IF NOT EXISTS room_preference (
      id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
      discipline_id INT NOT NULL,
      room_id       INT NOT NULL,
      priority      INT NOT NULL DEFAULT 100,
      is_primary    TINYINT(1) NOT NULL DEFAULT 0,
      is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
      created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at    DATETIME NULL,
      KEY idx_pref_disc (discipline_id),
      CONSTRAINT fk_pref_disc FOREIGN KEY (discipline_id) REFERENCES discipline (id),
      CONSTRAINT fk_pref_room FOREIGN KEY (room_id)       REFERENCES room (id)
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

// Базовый SELECT списка предпочтений (используется в нескольких запросах)
function roomPrefsSelectSql() {
  return "
    SELECT
      rp.id,
      rp.discipline_id AS subject_id,
      rp.room_id,
      CONCAT(TRIM(r.building), '-', TRIM(r.room_number)) AS room_name,
      CAST(rp.priority AS SIGNED) AS priority,
      CAST(rp.is_primary AS SIGNED) AS is_primary
    FROM room_preference rp
    JOIN room r ON r.id = rp.room_id AND r.is_deleted = 0
  ";
}

// Предпочтения аудиторий одной дисциплины
function repoGetRoomPrefsBySubject($conn, $subjectId) {
  repoEnsureRoomPrefsTable($conn);

  return dbAll(
    $conn,
    roomPrefsSelectSql() . "
    WHERE rp.is_deleted = 0
      AND rp.discipline_id = ?
    ORDER BY rp.is_primary DESC, rp.priority ASC, r.building, r.room_number",
    [(int)$subjectId]
  );
}

// Все предпочтения (для сводной модалки настроек)
function repoGetRoomPrefsAll($conn) {
  repoEnsureRoomPrefsTable($conn);

  return dbAll(
    $conn,
    "SELECT
        rp.discipline_id AS subject_id,
        d.name AS subject_name,
        rp.room_id,
        CONCAT(TRIM(r.building), '-', TRIM(r.room_number)) AS room_name,
        CAST(rp.priority AS SIGNED) AS priority,
        CAST(rp.is_primary AS SIGNED) AS is_primary
     FROM room_preference rp
     JOIN discipline d ON d.id = rp.discipline_id AND d.is_deleted = 0
     JOIN room r ON r.id = rp.room_id AND r.is_deleted = 0
     WHERE rp.is_deleted = 0
     ORDER BY d.name, rp.is_primary DESC, rp.priority ASC, r.building, r.room_number"
  );
}

// Полная перезапись предпочтений дисциплины (в транзакции: старые -> удалены, новые вставлены)
function repoSaveRoomPrefs($conn, $subjectId, array $prefs) {
  repoEnsureRoomPrefsTable($conn);
  $subjectId = (int)$subjectId;
  if ($subjectId <= 0) throw new Exception("subject_id required");

  $normalized = repoNormalizeRoomPrefs($prefs);

  $conn->begin_transaction();
  try {
    dbExec(
      $conn,
      "UPDATE room_preference SET is_deleted = 1, updated_at = NOW()
       WHERE discipline_id = ? AND is_deleted = 0",
      [$subjectId]
    );

    foreach ($normalized as $row) {
      dbInsert(
        $conn,
        "INSERT INTO room_preference (discipline_id, room_id, priority, is_primary, is_deleted)
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
