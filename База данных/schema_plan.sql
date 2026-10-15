-- ============================================================================
-- ОТДЕЛЬНАЯ БАЗА ДАННЫХ «УЧЕБНЫЙ ПЛАН» — uchetlfpstu_plan (MySQL / MariaDB)
-- ----------------------------------------------------------------------------
-- Выполняется АВТОМАТИЧЕСКИ при запуске программы (backend/config/db.php):
-- если база не существует — она создаётся вместе со всеми таблицами и
-- справочниками по умолчанию. Файл нужен для ручной установки через phpMyAdmin.
--
-- ВНИМАНИЕ: если пользователь MySQL не имеет прав на создание второй базы,
-- программа автоматически создаст те же таблицы в основной базе UchetLFPSTU
-- с префиксом plan_ (режим «префикс»). Тогда этот файл выполнять не нужно.
--
-- Порядок выполнения (ручной вариант): schema.sql -> create_admin.sql ->
--                                          schema_plan.sql -> demo_data.sql
-- ============================================================================

CREATE DATABASE IF NOT EXISTS uchetlfpstu_plan
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uchetlfpstu_plan;

-- Кафедры -------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS department (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,            -- название кафедры
  short_name VARCHAR(50)  NULL,                -- сокращение
  is_deleted TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_department_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Дисциплины учебного плана (привязка дисциплины к кафедре) ------------------
CREATE TABLE IF NOT EXISTS discipline_meta (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id INT NULL,                      -- id из справочника discipline
  department_id INT NULL,                      -- кафедра
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_discipline_meta (discipline_id),
  KEY idx_dm_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Виды учебной деятельности (колонки часов) ----------------------------------
CREATE TABLE IF NOT EXISTS activity_kind (
  id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,            -- «Лекции», «Лабораторные»...
  short_name     VARCHAR(30)  NULL,
  lesson_type_id INT NULL,                     -- связь с типом занятия (lesson_type.id)
  sort_order     INT NOT NULL DEFAULT 0,
  is_deleted     TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_activity_name (name),
  KEY idx_ak_lesson_type (lesson_type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Карточка учебного плана: дисциплина + курс + семестр -----------------------
-- term = 1..8: 1-й курс — семестры 1 и 2, ..., 4-й курс — 7 и 8
-- (9..12 допускаются для специалитетов).
CREATE TABLE IF NOT EXISTS curriculum (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id INT NOT NULL,                  -- ссылка на discipline_meta.id
  course        INT NOT NULL,                  -- курс 1..4
  term          INT NOT NULL,                  -- семестр 1..12
  total_hours   DECIMAL(8,2) NOT NULL DEFAULT 0, -- общая трудоемкость
  comment       VARCHAR(500) NULL,
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  UNIQUE KEY uq_curriculum (discipline_id, course, term),
  KEY idx_curr_term (term),
  CONSTRAINT fk_curr_disc FOREIGN KEY (discipline_id) REFERENCES discipline_meta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Распределение общей трудоемкости по видам учебной деятельности -------------
-- Отдельная строка часов для КАЖДОЙ ГРУППЫ в конкретном семестре.
CREATE TABLE IF NOT EXISTS curriculum_hours (
  id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curriculum_id    INT NOT NULL,
  group_id         INT NULL,                   -- группа (student_group.id)
  activity_kind_id INT NOT NULL,               -- вид деятельности
  hours            DECIMAL(8,2) NOT NULL DEFAULT 0,
  is_deleted       TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_curr_hours (curriculum_id, group_id, activity_kind_id),
  KEY idx_ch_curr (curriculum_id),
  CONSTRAINT fk_ch_curr FOREIGN KEY (curriculum_id)     REFERENCES curriculum (id),
  CONSTRAINT fk_ch_kind FOREIGN KEY (activity_kind_id)  REFERENCES activity_kind (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Справочник видов контроля ---------------------------------------------------
CREATE TABLE IF NOT EXISTS control_type (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,            -- «Экзамен», «Диф. Зачет»...
  sort_order INT NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_control_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вид контроля по семестрам (несколько семестров в одном пункте) --------------
CREATE TABLE IF NOT EXISTS curriculum_control (
  id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curriculum_id   INT NOT NULL,
  control_type_id INT NOT NULL,
  term            INT NOT NULL,                -- семестр (1..12)
  is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_curr_control (curriculum_id, control_type_id, term),
  KEY idx_cc_curr (curriculum_id),
  CONSTRAINT fk_cc_curr FOREIGN KEY (curriculum_id)   REFERENCES curriculum (id),
  CONSTRAINT fk_cc_type FOREIGN KEY (control_type_id) REFERENCES control_type (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Наполнение справочников по умолчанию (идемпотентно) -------------------------
INSERT IGNORE INTO activity_kind (name, short_name, sort_order) VALUES
 ('Экзамен',              'Экз',  10),
 ('Лекции',               'Лек',  20),
 ('Лабораторные работы',  'Лаб',  30),
 ('Практические занятия', 'Прак', 40),
 ('КСР/КРВ',              'КСР',  50),
 ('Курсовой проект',      'КурПр',60),
 ('Курсовая работа',      'КурРа',70),
 ('Самостоятельная работа','СР',   80);

INSERT IGNORE INTO control_type (name, sort_order) VALUES
 ('Экзамен',                 10),
 ('Дифференцированный зачет',20),
 ('Зачет',                   30),
 ('Курсовой проект',         40),
 ('Курсовая работа',         50);
