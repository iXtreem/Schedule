<?php
function repoGetAllWeeks($conn) {
  $sql = "SELECT idWeek, Name, DateStart, DateEnd FROM TB_Weeks WHERE IsDeleted = 0 ORDER BY DateStart ASC";
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching weeks: " . $conn->error);
  }
  
  $weeks = [];
  while ($row = $result->fetch_assoc()) {
    $weeks[] = $row;
  }
  return $weeks;
}

function repoAddWeek($conn, $name, $dateStart, $dateEnd) {
  // Исправлено: используем prepare() вместо odbc_prepare()
  $stmt = $conn->prepare("INSERT INTO TB_Weeks (Name, DateStart, DateEnd, IsDeleted) VALUES (?, ?, ?, 0)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  // Исправлено: используем bind_param() и execute()
  $stmt->bind_param("sss", $name, $dateStart, $dateEnd);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoUpdateWeek($conn, $id, $name, $dateStart, $dateEnd) {
  $stmt = $conn->prepare("UPDATE TB_Weeks SET Name = ?, DateStart = ?, DateEnd = ? WHERE idWeek = ?");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("sssi", $name, $dateStart, $dateEnd, $id);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $affected = $stmt->affected_rows;
  $stmt->close();
  
  return $affected > 0;
}

function repoDeleteWeek($conn, $id) {
  // Обычно делают мягкое удаление
  $stmt = $conn->prepare("UPDATE TB_Weeks SET IsDeleted = 1 WHERE idWeek = ?");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("i", $id);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $stmt->close();
  return true;
}
?>