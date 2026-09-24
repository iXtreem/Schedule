-- ============================================================================
-- СХЕМА БАЗЫ ДАННЫХ «uchetlfpstu» (MySQL / MariaDB, XAMPP)
-- ----------------------------------------------------------------------------
-- Таблицы и колонки приведены к грамотным именам: с маленькой буквы, snake_case.
-- Соответствие старой схемы (UchetLFPSTU):
--   TB_AppUser  -> user             (пользователи системы)
--   TB_Group    -> student_group    (учебные группы; group — зарезервировано MySQL)
--   TB_Discipl  -> discipline       (дисциплины)
--   TB_Teacher  -> teacher          (преподаватели)
--   TB_Room     -> room             (аудитории)
--   TB_TimeType -> lesson_type      (типы занятий)
--   TB_Weeks    -> week             (учебные недели)
--   TB_Holidays -> holiday          (праздничные дни)
--   TB_TcShip   -> study_stream     (поток: набор группы на обучение)
--   TB_GdTcShip -> plan_hours       (часы учебного плана)
--   TB_Schedule -> schedule_lesson  (занятие в расписании)
--   TB_RoomPref -> room_preference  (предпочтения аудиторий по дисциплине)
--   TB_BellSchedule -> bell_schedule (расписание звонков)
--   Таблица-счётчик TB_Sequence удалена: id теперь AUTO_INCREMENT.
-- Порядок выполнения: schema.sql -> create_admin.sql -> demo_data.sql
-- ============================================================================

CREATE DATABASE IF NOT EXISTS uchetlfpstu
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE uchetlfpstu;

-- Пользователи системы ---------------------------------------------
CREATE TABLE IF NOT EXISTS user (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,   -- id пользователя
  login         VARCHAR(60)  NOT NULL,                     -- логин для входа
  password_hash VARCHAR(255) NOT NULL,                     -- bcrypt-хеш пароля
  full_name     VARCHAR(150) NULL,                         -- ФИО сотрудника
  is_deleted    TINYINT(1)   NOT NULL DEFAULT 0,           -- флаг мягкого удаления
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NULL,
  UNIQUE KEY uq_user_login (login)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебные группы ----------------------------------------------------
CREATE TABLE IF NOT EXISTS student_group (
  id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(100) NOT NULL,        -- полное название группы
  short_name     VARCHAR(30)  NULL,            -- сокращение (ИС-21)
  admission_year INT          NULL,            -- год поступления (для расчёта семестров)
  max_students   INT          NULL,            -- максимальное число студентов
  is_deleted     TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_group_short (short_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Дисциплины ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS discipline (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(255) NOT NULL,            -- полное название
  short_name VARCHAR(60)  NULL,                -- сокращение для сетки
  is_deleted TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_discipline_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Преподаватели -------------------------------------------------------
CREATE TABLE IF NOT EXISTS teacher (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  surname    VARCHAR(80) NULL,                 -- фамилия
  first_name VARCHAR(80) NULL,                 -- имя
  patronymic VARCHAR(80) NULL,                 -- отчество
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_teacher_surname (surname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Аудитории -----------------------------------------------------------
CREATE TABLE IF NOT EXISTS room (
  id          INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  building    VARCHAR(30)  NULL,               -- корпус
  room_number VARCHAR(30)  NULL,               -- номер аудитории
  capacity    INT          NULL,               -- вместимость
  is_deleted  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY idx_room_building (building, room_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Типы занятий (лекция, практика, лаборатория...) ---------------------
CREATE TABLE IF NOT EXISTS lesson_type (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NULL,                 -- полное название
  short_name VARCHAR(20) NULL,                 -- сокращение (Лек, Прак, Лаб)
  is_deleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебные недели -------------------------------------------------------
CREATE TABLE IF NOT EXISTS week (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL,             -- «1 неделя»
  start_date DATE NOT NULL,                    -- понедельник недели
  end_date   DATE NOT NULL,                    -- воскресенье недели
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_week_start (start_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Праздничные дни (короткие пары по 1 часу) ----------------------------
CREATE TABLE IF NOT EXISTS holiday (
  id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL,
  UNIQUE KEY uq_holiday_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебный план: поток (набор группы на обучение) ------------------------
-- В старой схеме назывался TB_TcShip
CREATE TABLE IF NOT EXISTS study_stream (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  group_id   INT NOT NULL,                     -- ссылка на student_group
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_stream_group (group_id),
  CONSTRAINT fk_stream_group FOREIGN KEY (group_id) REFERENCES student_group (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебный план: часы по связке дисциплина/преподаватель/тип -------------
-- В старой схеме назывался TB_GdTcShip
CREATE TABLE IF NOT EXISTS plan_hours (
  id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  stream_id      INT NOT NULL,                 -- ссылка на study_stream
  discipline_id  INT NOT NULL,
  teacher_id     INT NOT NULL,
  lesson_type_id INT NOT NULL,
  term           INT NOT NULL,                 -- номер семестра (1..N)
  base_hours     DECIMAL(7,2) NOT NULL DEFAULT 0,  -- часы базовой части
  var_hours      DECIMAL(7,2) NOT NULL DEFAULT 0,  -- часы вариативной части
  KEY idx_plan_stream (stream_id, discipline_id, teacher_id, lesson_type_id),
  CONSTRAINT fk_plan_stream    FOREIGN KEY (stream_id)      REFERENCES study_stream (id),
  CONSTRAINT fk_plan_disc      FOREIGN KEY (discipline_id)  REFERENCES discipline (id),
  CONSTRAINT fk_plan_teacher   FOREIGN KEY (teacher_id)     REFERENCES teacher (id),
  CONSTRAINT fk_plan_type      FOREIGN KEY (lesson_type_id) REFERENCES lesson_type (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Расписание: конкретные занятия ----------------------------------------
-- В старой схеме назывался TB_Schedule
CREATE TABLE IF NOT EXISTS schedule_lesson (
  id             INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  week_id        INT NOT NULL,
  group_id       INT NOT NULL,
  discipline_id  INT NULL,
  teacher_id     INT NULL,
  room_id        INT NULL,
  lesson_type_id INT NULL,
  day_of_week    INT NOT NULL,                 -- 1..7 (1 = понедельник)
  time_slot      INT NOT NULL,                 -- номер пары
  hours          DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  custom_text    VARCHAR(500) NULL,            -- своя запись в ячейке
  is_deleted     TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_lesson_week (week_id, day_of_week, time_slot),
  KEY idx_lesson_group (group_id),
  CONSTRAINT fk_lesson_week    FOREIGN KEY (week_id)        REFERENCES week (id),
  CONSTRAINT fk_lesson_group   FOREIGN KEY (group_id)       REFERENCES student_group (id),
  CONSTRAINT fk_lesson_disc    FOREIGN KEY (discipline_id)  REFERENCES discipline (id),
  CONSTRAINT fk_lesson_teacher FOREIGN KEY (teacher_id)     REFERENCES teacher (id),
  CONSTRAINT fk_lesson_room    FOREIGN KEY (room_id)        REFERENCES room (id),
  CONSTRAINT fk_lesson_type    FOREIGN KEY (lesson_type_id) REFERENCES lesson_type (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Предпочтения аудиторий по дисциплине (для авто-расписания) ------------
-- В старой схеме назывался TB_RoomPref
CREATE TABLE IF NOT EXISTS room_preference (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id INT NOT NULL,
  room_id       INT NOT NULL,
  priority      INT NOT NULL DEFAULT 100,      -- меньше = лучше
  is_primary    TINYINT(1) NOT NULL DEFAULT 0, -- основная пара аудиторий
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NULL,
  KEY idx_pref_disc (discipline_id),
  CONSTRAINT fk_pref_disc FOREIGN KEY (discipline_id) REFERENCES discipline (id),
  CONSTRAINT fk_pref_room FOREIGN KEY (room_id)       REFERENCES room (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Настройки времени пар (расписание звонков) -----------------------------
-- Используется backend/lib/bell.php; значения по умолчанию есть в коде,
-- поэтому таблица может быть пустой.
CREATE TABLE IF NOT EXISTS bell_schedule (
  id        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day_type  VARCHAR(20) NOT NULL,              -- workday | sunday | holiday
  slot_num  INT NOT NULL,                      -- номер пары
  time_text VARCHAR(200) NOT NULL,             -- текст времени («8:00-8:45<br>...»)
  UNIQUE KEY uq_bell (day_type, slot_num)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;