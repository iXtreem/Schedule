<?php
$serverName = "EKATERINA\\SQLEXPRESS";
$database = "UchetLFPSTU";

function getDBConnection() {
  global $serverName, $database;

  $conn = odbc_connect(
    "Driver={ODBC Driver 17 for SQL Server};Server=$serverName;Database=$database;Trusted_Connection=Yes;",
    "", ""
  );

  if (!$conn) {
    throw new Exception("DB connection failed: " . odbc_errormsg());
  }
  return $conn;
}

function dbClose($conn) {
  if ($conn) odbc_close($conn);
}
