<?php
// Подключение к БД
$serverName = "localhost";
$database = "UchetLFPSTU";
$username = "root";
$password = "";

$conn = new mysqli($serverName, $username, $password, $database);

if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Данные администратора
$login = 'admin';
$plainPassword = 'admin'; // Пароль, который вы хотите задать
$fullName = 'Администратор';

// Генерация хеша точно так же, как это делает приложение (bcrypt)
$hash = password_hash($plainPassword, PASSWORD_DEFAULT);

// Проверка, существует ли уже пользователь
$stmt = $conn->prepare("SELECT idUser FROM TB_AppUser WHERE LoginName = ?");
$stmt->bind_param("s", $login);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    // Обновляем пароль существующему пользователю
    $updateStmt = $conn->prepare("UPDATE TB_AppUser SET PasswordHash = ?, FullName = ? WHERE LoginName = ?");
    $updateStmt->bind_param("sss", $hash, $fullName, $login);
    if ($updateStmt->execute()) {
        echo "Успешно! Пароль для пользователя '$login' обновлен на '$plainPassword'.<br>";
        echo "Новый хеш: $hash";
    } else {
        echo "Ошибка обновления: " . $updateStmt->error;
    }
    $updateStmt->close();
} else {
    // Создаем нового пользователя
    $insertStmt = $conn->prepare("INSERT INTO TB_AppUser (LoginName, PasswordHash, FullName, IsDeleted) VALUES (?, ?, ?, 0)");
    $insertStmt->bind_param("sss", $login, $hash, $fullName);
    if ($insertStmt->execute()) {
        echo "Успешно! Пользователь '$login' с паролем '$plainPassword' создан.<br>";
        echo "Хеш: $hash";
    } else {
        echo "Ошибка создания: " . $insertStmt->error;
    }
    $insertStmt->close();
}

$stmt->close();
$conn->close();
?>