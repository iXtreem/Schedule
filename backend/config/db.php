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

  // Авто-синхронизация схемы: если программа открыта с базой, созданной по
  // более старой версии schema.sql, недостающие колонки добавляются сами.
  // (Новая база создаётся из schema.sql, где эти колонки уже есть.)
  dbAutoMigrate($conn);

  return $conn;
}

/*
 * Простейшие безопасные миграции «на лету» (идемпотентны):
 * проверяют information_schema и добавляют отсутствующие колонки.
 * Список сверяется с База данных/schema.sql — при каждом изменении
 * схемы сюда добавляется соответствующее правило.
 */
function dbColumnExists(mysqli $conn, string $table, string $column): bool {
  $stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
  );
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0) > 0;
}

function dbAutoMigrate(mysqli $conn) {
  // teacher.max_hours — макс. часов преподавателя в неделю (по умолчанию 36).
  // Используется окном «Автозаполнение» (вкладка «Преподаватели»)
  // и будущим генератором расписания как ограничение нагрузки.
  if (!dbColumnExists($conn, 'teacher', 'max_hours')) {
    $conn->query(
      "ALTER TABLE teacher
         ADD COLUMN max_hours DECIMAL(5,1) NOT NULL DEFAULT 36.0
         AFTER patronymic"
    );
  }
}

function dbClose($conn) {
  if ($conn instanceof mysqli) {
    $conn->close();
  }
}