<?php
function repoGetSubjects($conn) {
  $sql = "SELECT idDiscipl, DisciplName, DisciplShortName 
          FROM TB_Discipl 
          WHERE DisciplDeleted = 0 
          ORDER BY DisciplName ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching subjects: " . $conn->error);
  }
  
  $subjects = [];
  while ($row = $result->fetch_assoc()) {
    $subjects[] = $row;
  }
  return $subjects;
}

function repoAddSubject($conn, $name, $shortName) {
  $stmt = $conn->prepare("INSERT INTO TB_Discipl (DisciplName, DisciplShortName, DisciplDeleted) VALUES (?, ?, 0)");
  
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

function repoUpdateSubject($conn, $id, $name, $shortName) {
  $stmt = $conn->prepare("UPDATE TB_Discipl SET DisciplName = ?, DisciplShortName = ? WHERE idDiscipl = ?");
  
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

function repoDeleteSubject($conn, $id) {
  $stmt = $conn->prepare("UPDATE TB_Discipl SET DisciplDeleted = 1 WHERE idDiscipl = ?");
  
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