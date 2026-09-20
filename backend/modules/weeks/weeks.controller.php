<?php
function weeksController($conn, $method, $data = null) {
  require_once __DIR__ . '/weeks.repo.php';

  // Получаем данные: либо из JSON тела запроса, либо из $_POST
  $input = $data;
  if (!$input) {
      $rawInput = file_get_contents('php://input');
      $jsonInput = json_decode($rawInput, true);
      if ($jsonInput) {
          $input = $jsonInput;
      } else {
          $input = $_POST;
      }
  }

  if ($method === 'GET') {
    try {
        $weeks = repoGetAllWeeks($conn);
        return ['success' => true, 'data' => $weeks];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  if ($method === 'POST') {
    // Поддержка разных имен полей от фронтенда
    $name = $input['name'] ?? $input['WeekName'] ?? $input['weekName'] ?? null;
    $dateStartRaw = $input['dateStart'] ?? $input['StartDate'] ?? $input['startDate'] ?? null;
    $dateEndRaw = $input['dateEnd'] ?? $input['EndDate'] ?? $input['endDate'] ?? null;

    // Проверка наличия полей
    if (!$name || !$dateStartRaw || !$dateEndRaw) {
        return [
            'success' => false, 
            'error' => 'Отсутствуют обязательные поля', 
            'debug' => [
                'received_name' => $name,
                'received_start' => $dateStartRaw,
                'received_end' => $dateEndRaw,
                'full_input' => $input
            ]
        ];
    }

    // Функция конвертации даты из DD.MM.YYYY в YYYY-MM-DD
    $convertDate = function($dateStr) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateStr, $matches)) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }
        // Если уже в формате YYYY-MM-DD, оставляем как есть
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            return $dateStr;
        }
        return null; // Неверный формат
    };

    $dateStart = $convertDate($dateStartRaw);
    $dateEnd = $convertDate($dateEndRaw);

    if (!$dateStart || !$dateEnd) {
        return [
            'success' => false, 
            'error' => 'Неверный формат даты. Ожидался ДД.ММ.ГГГГ или ГГГГ-ММ-ДД',
            'debug' => [
                'start_raw' => $dateStartRaw,
                'start_converted' => $dateStart,
                'end_raw' => $dateEndRaw,
                'end_converted' => $dateEnd
            ]
        ];
    }

    try {
        $newId = repoAddWeek($conn, $name, $dateStart, $dateEnd);
        return ['success' => true, 'id' => $newId];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  if ($method === 'PUT') {
    $id = $input['id'] ?? $input['idWeek'] ?? null;
    $name = $input['name'] ?? $input['WeekName'] ?? null;
    $dateStartRaw = $input['dateStart'] ?? $input['StartDate'] ?? null;
    $dateEndRaw = $input['dateEnd'] ?? $input['EndDate'] ?? null;

    $convertDate = function($dateStr) {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $dateStr, $matches)) {
            return "{$matches[3]}-{$matches[2]}-{$matches[1]}";
        }
        return $dateStr;
    };

    $dateStart = $convertDate($dateStartRaw);
    $dateEnd = $convertDate($dateEndRaw);

    if (!$id || !$name || !$dateStart || !$dateEnd) {
        return ['success' => false, 'error' => 'Ошибка обновления: проверьте все поля'];
    }

    try {
        $updated = repoUpdateWeek($conn, $id, $name, $dateStart, $dateEnd);
        return ['success' => $updated];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  if ($method === 'DELETE') {
    $id = $input['id'] ?? $input['idWeek'] ?? null;
    // Если ID нет в теле, пробуем взять из GET параметров (если удаляют через URL)
    if (!$id && isset($_GET['id'])) {
        $id = $_GET['id'];
    }

    if (!$id) {
        return ['success' => false, 'error' => 'ID недели не указан'];
    }

    try {
        $deleted = repoDeleteWeek($conn, $id);
        return ['success' => $deleted];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
  }

  return ['success' => false, 'error' => 'Метод не поддерживается'];
}
?>