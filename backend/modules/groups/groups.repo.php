<?php
function repoGetGroups($conn) {
  $sql = "SELECT idGroup, GroupShortName, GroupName, GroupYear, GroupMaxContrBook 
          FROM TB_Group 
          WHERE GroupDeleted = 0 
          ORDER BY GroupShortName ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching groups: " . $conn->error);
  }
  
  $groups = [];
  while ($row = $result->fetch_assoc()) {
    $groups[] = $row;
  }
  return $groups;
}

function repoAddGroup($conn, $shortName, $fullName, $year, $maxContrBook) {
  $stmt = $conn->prepare("INSERT INTO TB_Group (GroupShortName, GroupName, GroupYear, GroupMaxContrBook, GroupDeleted) VALUES (?, ?, ?, ?, 0)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  // maxContrBook может быть null
  $stmt->bind_param("ssii", $shortName, $fullName, $year, $maxContrBook);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoUpdateGroup($conn, $id, $shortName, $fullName, $year, $maxContrBook) {
  $stmt = $conn->prepare("UPDATE TB_Group SET GroupShortName = ?, GroupName = ?, GroupYear = ?, GroupMaxContrBook = ? WHERE idGroup = ?");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("ssi ii", $shortName, $fullName, $year, $maxContrBook, $id);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $affected = $stmt->affected_rows;
  $stmt->close();
  
  return $affected > 0;
}

function repoDeleteGroup($conn, $id) {
  $stmt = $conn->prepare("UPDATE TB_Group SET GroupDeleted = 1 WHERE idGroup = ?");
  
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