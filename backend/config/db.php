<?php
$serverName = "localhost"; // XAMPP использует localhost
$database = "UchetLFPSTU"; // Имя вашей базы данных
$username = "root";        // Стандартный пользователь XAMPP
$password = "";            // Стандартный пароль пустой

function getDBConnection() {
  global $serverName, $database, $username, $password;
  
  // Используем mysqli вместо ODBC для работы с MySQL в XAMPP
  $conn = new mysqli($serverName, $username, $password, $database);

  if ($conn->connect_error) {
    throw new Exception("DB connection failed: " . $conn->connect_error);
  }
  
  // Устанавливаем кодировку utf8
  $conn->set_charset("utf8");
  
  return $conn;
}

function dbClose($conn) {
  if ($conn) {
    $conn->close();
  }
}
?>