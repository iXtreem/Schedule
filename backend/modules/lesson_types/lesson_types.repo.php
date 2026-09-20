<?php
function repoGetLessonTypes($conn) {
  // Выбираем данные и создаем псевдонимы (aliases), чтобы фронтенд получил ожидаемые имена полей
  $sql = "SELECT idTimeType AS idType, TimeTypeName AS name, TimeTypeShortName AS shortName 
          FROM TB_TimeType 
          ORDER BY idTimeType ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching lesson types: " . $conn->error);
  }
  
  $types = [];
  while ($row = $result->fetch_assoc()) {
    $types[] = $row;
  }
  return $types;
}

function repoAddLessonType($conn, $name, $shortName = null) {
  $stmt = $conn->prepare("INSERT INTO TB_TimeType (TimeTypeName, TimeTypeShortName) VALUES (?, ?)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("ss", $name, $shortName);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoUpdateLessonType($conn, $id, $name, $shortName = null) {
  $stmt = $conn->prepare("UPDATE TB_TimeType SET TimeTypeName = ?, TimeTypeShortName = ? WHERE idTimeType = ?");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("ssi", $name, $shortName, $id);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $affected = $stmt->affected_rows;
  $stmt->close();
  
  return $affected > 0;
}

function repoDeleteLessonType($conn, $id) {
  $stmt = $conn->prepare("DELETE FROM TB_TimeType WHERE idTimeType = ?");
  
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