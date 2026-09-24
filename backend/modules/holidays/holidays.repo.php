
<?php
require_once __DIR__ . '/../../lib/db.php';

// Выходные / сокращённые дни (таблица holiday — бывшая TB_Holidays)
//
// kind: 'off'     — красный день в календаре «Выходные дни»: полный выходной,
//                   такие дни исключаются из таблиц расписания;
//         'reduced' — жёлтый день: занятия остаются, но время пар меняется
//                   на сокращённое (тип звонков 'holiday').

// Гарантируем наличие колонки kind в уже созданных базах (мягкая миграция).
// Вызывается один раз за запрос; при ошибке молча полагаемся на значение по умолчанию.
function holidayEnsureKindColumn($conn) {
  static $ok = false;
  if ($ok) return;
  try {
    $col = dbRow(
      $conn,
      "SELECT COLUMN_NAME FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'holiday' AND COLUMN_NAME = 'kind'"
    );
    if (!$col) {
      $conn->query(
        "ALTER TABLE holiday ADD COLUMN kind VARCHAR(10) NOT NULL DEFAULT 'off' AFTER holiday_date"
      );
    }
  } catch (Exception $e) {
    // таблица могла быть создана без колонки — обработаем это ниже дефолтом 'off'
  }
  $ok = true;
}

// Добавить/обновить отметку дня (date + kind: off|reduced)
function repoAddHoliday($conn, $date, $kind = 'off') {
  holidayEnsureKindColumn($conn);
  $kind = ($kind === 'reduced') ? 'reduced' : 'off';
  dbExec(
    $conn,
    "INSERT INTO holiday (holiday_date, kind) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE kind = VALUES(kind)",
    [$date, $kind]
  );
}

// Удалить отметку с дня
function repoRemoveHoliday($conn, $date) {
  dbExec($conn, "DELETE FROM holiday WHERE holiday_date = ?", [$date]);
}

// Отметки дней в диапазоне дат.
// Возвращает список [{ date: "YYYY-MM-DD", kind: "off"|"reduced" }, ...]
function repoGetHolidaysRange($conn, $start, $end) {
  holidayEnsureKindColumn($conn);
  $rows = dbAll(
    $conn,
    "SELECT DATE_FORMAT(holiday_date, '%Y-%m-%d') AS date,
            COALESCE(kind, 'off') AS kind
     FROM holiday
     WHERE holiday_date >= ? AND holiday_date <= ?
     ORDER BY holiday_date",
    [$start, $end]
  );

  $data = [];
  foreach ($rows as $row) {
    $data[] = [
      'date' => $row['date'],
      'kind' => ($row['kind'] === 'reduced') ? 'reduced' : 'off',
    ];
  }
  return $data;
}
