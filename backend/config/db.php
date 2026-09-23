<?php
/*
 * Подключение к базе данных через MySQLi (XAMPP: Apache + MariaDB/MySQL).
 * Расширение php_odbc / SQL Server в этом проекте больше НЕ используется.
 */

$serverName = "localhost";     // XAMPP: MySQL доступен по localhost
$database   = "UchetLFPSTU";   // Имя базы данных
$username   = "root";          // Стандартный пользователь XAMPP
$password   = "";              // В XAMPP пароль root по умолчанию пустой

// Если MySQL поднят на нестандартном порту — раскомментируйте нужную строку:
// $serverName = "127.0.0.1:3307";

function getDBConnection() {
  global $serverName, $database, $username, $password;

  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  try {
    $conn = new mysqli($serverName, $username, $password, $database);
  } catch (Throwable $e) {
    throw new Exception(
      'Не удалось подключиться к MySQL (XAMPP). Проверьте, что модули Apache "Apache(MySQL)" и "Apache(httpd)" запущены, '
      . 'что в php.ini включено расширение mysqli (extension=mysqli), '
      . 'что база "' . $GLOBALS['database'] . '" существует (импортируйте backend/sql/schema.sql через phpMyAdmin). '
      . 'Ошибка: ' . $e->getMessage()
    );
  }

  // Полная кириллица без потерь
  $conn->set_charset("utf8mb4");

  return $conn;
}

function dbClose($conn) {
  if ($conn instanceof mysqli) {
    $conn->close();
  }
}
