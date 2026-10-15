<?php
/*
 * Подключение к базе данных через MySQLi (XAMPP: Apache + MariaDB/MySQL).
 * Расширение php_odbc / SQL Server в этом проекте больше НЕ используется.
 */

$serverName = "localhost";     // XAMPP: MySQL доступен по localhost
$database   = "UchetLFPSTU";   // Имя базы данных
$username   = "root";          // Стандартный пользователь XAMPP
$password   = "";              // В XAMPP пароль root по умолчанию пустой

// Отдельная база «Учебный план» (модуль backend/modules/curriculum).
// Создаётся автоматически при первом запуске; если прав на CREATE DATABASE
// нет — таблицы создаются в основной базе с префиксом plan_ (см. planTable()).
$planDatabase = "uchetlfpstu_plan";

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

  // Отдельная база «Учебный план»: создаётся автоматически при первом запуске
  // (см. planDB()). Ошибки не блокируют основную работу программы.
  try {
    planDB($conn);
  } catch (Throwable $e) {
    error_log('Не удалось подготовить базу учебного плана: ' . $e->getMessage());
  }

  return $conn;
}

/* ==========================================================================
 * БАЗА ДАННЫХ «УЧЕБНЫЙ ПЛАН» (модуль backend/modules/curriculum)
 * --------------------------------------------------------------------------
 * Это ОТДЕЛЬНАЯ база uchetlfpstu_plan на том же MySQL-сервере (XAMPP).
 * Если её нет — она создаётся автоматически вместе со всеми таблицами и
 * справочниками по умолчанию (кафедра, дисциплина, виды учебной
 * деятельности, распределение трудоемкости, виды контроля по семестрам).
 * Если прав на CREATE DATABASE нет (общий хостинг), таблицы создаются
 * в основной базе с префиксом plan_ — режим «префикс».
 * ========================================================================== */

// Режим расположения таблиц плана: 'separate' | 'prefix' (кэш запроса).
function planMode(mysqli $conn): string {
  static $mode = null;
  if ($mode !== null) return $mode;

  global $planDatabase;

  // Уже существующая отдельная база?
  $res = $conn->query(
    "SELECT COUNT(*) AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '"
    . $conn->real_escape_string($planDatabase) . "'"
  );
  if ($res && ($row = $res->fetch_assoc()) && (int)$row['c'] > 0) {
    return $mode = 'separate';
  }

  // Пробуем создать отдельную базу (idempotent).
  if (@$conn->query("CREATE DATABASE IF NOT EXISTS `$planDatabase` "
      . "DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    return $mode = 'separate';
  }

  // Не получилось (нет прав) — работаем в основной базе с префиксом plan_.
  return $mode = 'prefix';
}

// Возвращает готовый к работе mysqli-ресурс базы учебного плана
// (или то же соединение, если таблицы живут в основной базе с префиксом).
function planDB(mysqli $conn): mysqli {
  global $planDatabase;

  static $planConn = null;
  static $schemaDone = false;

  $mode = planMode($conn);

  if ($mode === 'prefix') {
    // Таблицы живут в основной базе: имена — через глобальный префикс plan_.
    planSetMode('prefix');
    if (!$schemaDone) {
      planCreateSchema($conn, 'plan_');
      planFillDefaults($conn, 'plan_');
      $schemaDone = true;
    }
    return $conn;
  }

  // Отдельная база: своё соединение.
  if (!$planConn || !$planConn->ping()) {
    mysqli_report(MYSQLI_REPORT_OFF);
    $new = @new mysqli($GLOBALS['serverName'], $GLOBALS['username'], $GLOBALS['password'], $planDatabase);
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    if ($new->connect_errno) {
      throw new Exception('Не удалось подключиться к базе учебного плана: ' . $new->connect_error);
    }
    $new->set_charset('utf8mb4');
    $planConn = $new;
    $schemaDone = false;
  }

  planSetMode('separate');
  if (!$schemaDone) {
    planCreateSchema($planConn, '');
    planFillDefaults($planConn, '');
    $schemaDone = true;
  }
  return $planConn;
}

function planSetMode(string $m) {
  // внутренний сброс статического режима для planTable()
  static $current = null;
  if ($current === $m) return;
  $current = $m;
  // перезаписываем статик через глобал
  $GLOBALS['__plan_mode_cache'] = $m;
}

// Имя таблицы плана с учётом режима (используется репозиторием curriculum).
function planTab(string $base): string {
  $mode = $GLOBALS['__plan_mode_cache'] ?? 'separate';
  return $mode === 'prefix' ? 'plan_' . $base : $base;
}

// Создание таблиц базы учебного плана (идемпотентно; копия схемы
// «База данных/schema_plan.sql»). $prefix — '' или 'plan_'.
function planCreateSchema(mysqli $conn, string $prefix) {
  $p = $prefix;

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}department (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    short_name VARCHAR(50)  NULL,
    is_deleted TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY uq_department_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $fkDisc = $prefix === ''
    ? ", CONSTRAINT fk_curr_disc FOREIGN KEY (discipline_id) REFERENCES {$p}discipline_meta (id)"
    : "";

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}discipline_meta (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discipline_id INT NULL,
    department_id INT NULL,
    is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_discipline_meta (discipline_id),
    KEY idx_dm_department (department_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}activity_kind (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    short_name VARCHAR(30)  NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_activity_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}curriculum (
    id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    discipline_id INT NOT NULL,
    course        INT NOT NULL,
    term          INT NOT NULL,
    total_hours   DECIMAL(8,2) NOT NULL DEFAULT 0,
    comment       VARCHAR(500) NULL,
    is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NULL,
    UNIQUE KEY uq_curriculum (discipline_id, course, term),
    KEY idx_curr_term (term)
    $fkDisc
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}curriculum_hours (
    id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    curriculum_id    INT NOT NULL,
    group_id         INT NULL,
    activity_kind_id INT NOT NULL,
    hours            DECIMAL(8,2) NOT NULL DEFAULT 0,
    is_deleted       TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_curr_hours (curriculum_id, group_id, activity_kind_id),
    KEY idx_ch_curr (curriculum_id),
    CONSTRAINT fk_ch_curr FOREIGN KEY (curriculum_id)    REFERENCES {$p}curriculum (id),
    CONSTRAINT fk_ch_kind FOREIGN KEY (activity_kind_id) REFERENCES {$p}activity_kind (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}control_type (
    id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_control_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $conn->query("CREATE TABLE IF NOT EXISTS {$p}curriculum_control (
    id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    curriculum_id   INT NOT NULL,
    control_type_id INT NOT NULL,
    term            INT NOT NULL,
    is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_curr_control (curriculum_id, control_type_id, term),
    KEY idx_cc_curr (curriculum_id),
    CONSTRAINT fk_cc_curr FOREIGN KEY (curriculum_id)   REFERENCES {$p}curriculum (id),
    CONSTRAINT fk_cc_type FOREIGN KEY (control_type_id) REFERENCES {$p}control_type (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// Справочники по умолчанию (виды деятельности и виды контроля).
function planFillDefaults(mysqli $conn, string $prefix) {
  $p = $prefix;

  $activities = [
    ['Экзамен',              'Экз',   10],
    ['Лекции',               'Лек',   20],
    ['Лабораторные работы',  'Лаб',   30],
    ['Практические занятия', 'Прак',  40],
    ['КСР/КРВ',              'КСР',   50],
    ['Курсовой проект',      'КурПр', 60],
    ['Курсовая работа',      'КурРа', 70],
    ['Самостоятельная работа','СР',    80],
  ];
  foreach ($activities as [$name, $short, $order]) {
    $stmt = $conn->prepare(
      "INSERT IGNORE INTO {$p}activity_kind (name, short_name, sort_order) VALUES (?, ?, ?)"
    );
    $stmt->bind_param('ssi', $name, $short, $order);
    $stmt->execute();
    $stmt->close();
  }

  $controls = [
    ['Экзамен',                 10],
    ['Дифференцированный зачет',20],
    ['Зачет',                   30],
    ['Курсовой проект',         40],
    ['Курсовая работа',         50],
  ];
  foreach ($controls as [$name, $order]) {
    $stmt = $conn->prepare(
      "INSERT IGNORE INTO {$p}control_type (name, sort_order) VALUES (?, ?)"
    );
    $stmt->bind_param('si', $name, $order);
    $stmt->execute();
    $stmt->close();
  }
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

// Проверка существования таблицы (для авто-миграции новых таблиц схемы).
function dbTableExists(mysqli $conn, string $table): bool {
  $stmt = $conn->prepare(
    "SELECT COUNT(*) AS c FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
  );
  $stmt->bind_param('s', $table);
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

  // teacher.working_days — рабочие дни преподавателя (7 символов Пн..Вс,
  // '1' — может работать). Используется окном «⚡ Условия заполнения»
  // (вкладка «Преподаватели») и подсветкой красным при назначении занятия
  // в нерабочий день/вне рабочего времени.
  if (!dbColumnExists($conn, 'teacher', 'working_days')) {
    $conn->query(
      "ALTER TABLE teacher
         ADD COLUMN working_days CHAR(7) NOT NULL DEFAULT '1111111'
         AFTER max_hours"
    );
  }

  // teacher.work_start / work_end — рабочее время («со скольки до скольки»).
  // NULL — ограничение по времени не задано (работает весь день).
  if (!dbColumnExists($conn, 'teacher', 'work_start')) {
    $conn->query(
      "ALTER TABLE teacher
         ADD COLUMN work_start TIME NULL
         AFTER working_days"
    );
  }
  if (!dbColumnExists($conn, 'teacher', 'work_end')) {
    $conn->query(
      "ALTER TABLE teacher
         ADD COLUMN work_end TIME NULL
         AFTER work_start"
    );
  }

  // teacher_discipline — дисциплины, которые может вести преподаватель
  // (окно «⚡ Условия заполнения расписания» → вкладка «Преподаватели»,
  // кнопка «Дисциплины»). Пустой список = ограничений нет. Красная
  // подсветка и подсказка при попытке поставить недозволенную дисциплину.
  if (!dbTableExists($conn, 'teacher_discipline')) {
    $conn->query(
      "CREATE TABLE teacher_discipline (
         id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
         teacher_id    INT NOT NULL,
         discipline_id INT NOT NULL,
         is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
         UNIQUE KEY uq_teacher_disc (teacher_id, discipline_id),
         KEY idx_td_teacher (teacher_id),
         CONSTRAINT fk_td_teacher    FOREIGN KEY (teacher_id)    REFERENCES teacher (id),
         CONSTRAINT fk_td_discipline FOREIGN KEY (discipline_id) REFERENCES discipline (id)
       ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
  }
}

function dbClose($conn) {
  if ($conn instanceof mysqli) {
    $conn->close();
  }
}