
<?php
require_once __DIR__ . '/../../lib/db.php';

// Учебные недели (таблица week — новая схема, бывшая TB_Weeks)
function repoGetWeeks($conn) {
  return dbAll(
    $conn,
    "SELECT
        id,
        name,
        DATE_FORMAT(start_date, '%Y-%m-%d') AS start_date,
        DATE_FORMAT(end_date,   '%Y-%m-%d') AS end_date
     FROM week
     WHERE is_deleted = 0
     ORDER BY start_date DESC"
  );
}

// Добавление учебной недели
function repoAddWeek($conn, $name, $start, $end) {
  return dbInsert(
    $conn,
    "INSERT INTO week (name, start_date, end_date, is_deleted) VALUES (?, ?, ?, 0)",
    [$name, $start, $end]
  );
}

// ---- Автосоздание недель на основе выходных --------------------------------

// Все даты ПОЛНЫХ выходных (kind='off') из таблицы holiday.
// Жёлтые дни (kind='reduced' — праздник с альтернативным расписанием)
// учебными не считаются, но неделю не «рвут» и пропускаются здесь так же,
// как раньше: в генераторе недель нерабочими остаются только красные дни.
// Используем при генерации недель: день, объявленный полным выходным,
// не является учебным и «разрывает» непрерывную учебную неделю.
function repoGetAllHolidayDates($conn) {
  $rows = dbAll(
    $conn,
    "SELECT DATE_FORMAT(holiday_date, '%Y-%m-%d') AS d
     FROM holiday WHERE COALESCE(kind, 'off') = 'off'"
  );
  $set = [];
  foreach ($rows as $row) $set[$row['d']] = true;
  return $set; // ассоциативный массив дата => true для быстрой проверки
}

// Дата начала следующей недели после указанной (по существующим неделям в БД).
function repoMaxWeekEndDate($conn) {
  return dbScalar($conn, "SELECT MAX(end_date) FROM week WHERE is_deleted = 0");
}

// Проверка: есть ли уже неделя с таким понедельником (защита от дублей).
function repoWeekExistsByStart($conn, $startDate) {
  $n = dbScalar(
    $conn,
    "SELECT COUNT(*) FROM week WHERE start_date = ? AND is_deleted = 0",
    [$startDate],
    0
  );
  return (int)$n > 0;
}

// Обновить границы существующей недели (например, после добавления выходных
// неделя могла «оборваться» раньше — подтягиваем end_date к актуальному).
function repoUpdateWeekRange($conn, $id, $name, $start, $end) {
  return dbExec(
    $conn,
    "UPDATE week SET name = ?, start_date = ?, end_date = ? WHERE id = ?",
    [$name, $start, $end, $id]
  );
}

// Вставка недели без флага удаления (для пакетной генерации).
function repoInsertWeekRaw($conn, $name, $start, $end) {
  return dbInsert(
    $conn,
    "INSERT INTO week (name, start_date, end_date, is_deleted) VALUES (?, ?, ?, 0)",
    [$name, $start, $end]
  );
}
