<?php
function repoGetHolidays($conn) {
  $sql = "SELECT idHoliday, HolidayDate FROM TB_Holidays ORDER BY HolidayDate ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching holidays: " . $conn->error);
  }
  
  $holidays = [];
  while ($row = $result->fetch_assoc()) {
    $holidays[] = $row;
  }
  return $holidays;
}

function repoAddHoliday($conn, $date) {
  $stmt = $conn->prepare("INSERT INTO TB_Holidays (HolidayDate) VALUES (?)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  $stmt->bind_param("s", $date);
  
  if (!$stmt->execute()) {
    $stmt->close();
    // Проверяем, не является ли ошибка дубликатом уникального ключа
    if ($conn->errno == 1062) {
        throw new Exception("Эта дата уже добавлена в праздники.");
    }
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoDeleteHoliday($conn, $id) {
  $stmt = $conn->prepare("DELETE FROM TB_Holidays WHERE idHoliday = ?");
  
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