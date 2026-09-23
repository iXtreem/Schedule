<?php
/*
 * Небольшая обёртка над MySQLi: prepared statements + извлечение строк.
 * Все репозитории работают только через эти функции (никакого ODBC).
 */

if (!function_exists('dbParamType')) {

  // Тип параметра для bind_param: NULL/число -> "i", остальное -> "s"
  function dbParamType($value) {
    return ($value === null || is_int($value) || is_float($value)) ? 'i' : 's';
  }

  function dbNormalizeValue($value) {
    if ($value === null) return null;
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_int($value) || is_float($value) || is_string($value)) return $value;
    return (string)$value;
  }

  function dbRun(mysqli $conn, string $sql, array $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
      throw new Exception('Ошибка подготовки запроса: ' . $conn->error . "\nЗапрос: " . $sql);
    }

    $params = array_values($params);
    if (count($params) > 0) {
      $types = '';
      foreach ($params as $p) $types .= dbParamType($p);
      $args = [];
      foreach ($params as $i => $p) $args[] = dbNormalizeValue($p);
      $stmt->bind_param($types, ...$args);
      $stmt->execute();
    } else {
      $stmt->execute();
    }

    return $stmt;
  }

  // Список ассоциативных строк результата SELECT
  function dbAll(mysqli $conn, string $sql, array $params = []): array {
    $stmt = dbRun($conn, $sql, $params);
    $res  = $stmt->get_result();
    $rows = [];
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $stmt->close();
    return $rows;
  }

  // Первая строка результата SELECT или null
  function dbRow(mysqli $conn, string $sql, array $params = []): ?array {
    $rows = dbAll($conn, $sql, $params);
    return $rows[0] ?? null;
  }

  // INSERT: возвращает id последней вставленной записи
  function dbInsert(mysqli $conn, string $sql, array $params = []): int {
    $stmt = dbRun($conn, $sql, $params);
    $id   = (int)$conn->insert_id;
    $stmt->close();
    return $id;
  }

  // UPDATE / DELETE: возвращает число затронутых строк
  function dbExec(mysqli $conn, string $sql, array $params = []): int {
    $stmt = dbRun($conn, $sql, $params);
    $aff  = (int)$stmt->affected_rows;
    $stmt->close();
    return $aff;
  }

  // Значение одного поля из первой строки (или $default)
  function dbScalar(mysqli $conn, string $sql, array $params = [], $default = null) {
    $row = dbRow($conn, $sql, $params);
    if (!$row) return $default;
    $value = reset($row);
    return $value === null ? $default : $value;
  }
}
