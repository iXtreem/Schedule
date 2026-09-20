<?php
function repoGetTeachers($conn) {
  $sql = "SELECT idTeacher, TeacherSurname, TeacherFirstName, TeacherLastName 
          FROM TB_Teacher 
          WHERE TeacherDeleted = 0 
          ORDER BY TeacherSurname ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching teachers: " . $conn->error);
  }
  
  $teachers = [];
  while ($row = $result->fetch_assoc()) {
    $teachers[] = $row;
  }
  return $teachers;
}

function repoAddTeacher($conn, $surname, $firstname, $lastname) {
  $stmt = $conn->prepare("INSERT INTO TB_Teacher (TeacherSurname, TeacherFirstName, TeacherLastName, TeacherDeleted) VALUES (?, ?, ?, 0)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  // lastname может быть пустым
  $stmt->bind_param("sss", $surname, $firstname, $lastname);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoUpdateTeacher($conn, $id, $surname, $firstname, $lastname) {
  $stmt = $conn->prepare("UPDATE TB_Teacher SET TeacherSurname = ?, TeacherFirstName = ?, TeacherLastName = ? WHERE idTeacher = ?");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("sssi", $surname, $firstname, $lastname, $id);
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $affected = $stmt->affected_rows;
  $stmt->close();
  
  return $affected > 0;
}

function repoDeleteTeacher($conn, $id) {
  $stmt = $conn->prepare("UPDATE TB_Teacher SET TeacherDeleted = 1 WHERE idTeacher = ?");
  
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