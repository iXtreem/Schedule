<?php
function repoGetRooms($conn) {
  // Выбираем реальные колонки и создаем псевдоним Name для совместимости с фронтендом
  $sql = "SELECT idRoom, Building, RoomNumber, Capacity, IsDeleted, 
                 CONCAT(Building, ' ', RoomNumber) AS RoomName 
          FROM TB_Room 
          WHERE IsDeleted = 0 
          ORDER BY Building ASC, RoomNumber ASC";
  
  $result = $conn->query($sql);
  
  if (!$result) {
    throw new Exception("Error fetching rooms: " . $conn->error);
  }
  
  $rooms = [];
  while ($row = $result->fetch_assoc()) {
    $rooms[] = $row;
  }
  return $rooms;
}

function repoAddRoom($conn, $building, $roomNumber, $capacity) {
  $stmt = $conn->prepare("INSERT INTO TB_Room (Building, RoomNumber, Capacity, IsDeleted) VALUES (?, ?, ?, 0)");
  
  if (!$stmt) {
    throw new Exception("Prepare failed: " . $conn->error);
  }
  
  // capacity может быть null
  if ($capacity === '' || $capacity === null) {
      $stmt->bind_param("ssi", $building, $roomNumber, $null_val);
      // Примечание: если capacity NULL, нужна хитрость с bind_param, но проще передать 0 или проверить тип
      // Для простоты, если пришло пустое значение, считаем его NULL через отдельную логику или передаем 0
      // Исправленный вариант для nullable int:
      $stmt->bind_param("ssii", $building, $roomNumber, $capacity_int);
      $capacity_int = is_numeric($capacity) ? (int)$capacity : null;
      // Переподготовим запрос с учетом NULL, если нужно, но стандартный bind_param не любит null для i без флагов
      // Самый надежный способ для MySQLi с nullable:
  }
  
  // Переписываем функцию добавления для корректной работы с NULL
  $stmt->close();
  
  $capacityVal = is_numeric($capacity) ? (int)$capacity : null;
  
  if ($capacityVal === null) {
      $stmt = $conn->prepare("INSERT INTO TB_Room (Building, RoomNumber, Capacity, IsDeleted) VALUES (?, ?, NULL, 0)");
      if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
      $stmt->bind_param("ss", $building, $roomNumber);
  } else {
      $stmt = $conn->prepare("INSERT INTO TB_Room (Building, RoomNumber, Capacity, IsDeleted) VALUES (?, ?, ?, 0)");
      if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
      $stmt->bind_param("ssi", $building, $roomNumber, $capacityVal);
  }
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $newId = $stmt->insert_id;
  $stmt->close();
  
  return $newId;
}

function repoUpdateRoom($conn, $id, $building, $roomNumber, $capacity) {
  $capacityVal = is_numeric($capacity) ? (int)$capacity : null;

  if ($capacityVal === null) {
      $stmt = $conn->prepare("UPDATE TB_Room SET Building = ?, RoomNumber = ?, Capacity = NULL WHERE idRoom = ?");
      if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
      $stmt->bind_param("ssi", $building, $roomNumber, $id);
  } else {
      $stmt = $conn->prepare("UPDATE TB_Room SET Building = ?, RoomNumber = ?, Capacity = ? WHERE idRoom = ?");
      if (!$stmt) throw new Exception("Prepare failed: " . $conn->error);
      $stmt->bind_param("ssii", $building, $roomNumber, $capacityVal, $id);
  }
  
  if (!$stmt->execute()) {
    $stmt->close();
    throw new Exception("Execute failed: " . $stmt->error);
  }
  
  $affected = $stmt->affected_rows;
  $stmt->close();
  
  return $affected > 0;
}

function repoDeleteRoom($conn, $id) {
  $stmt = $conn->prepare("UPDATE TB_Room SET IsDeleted = 1 WHERE idRoom = ?");
  
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