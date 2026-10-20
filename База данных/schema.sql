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
  id           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  surname      VARCHAR(80) NULL,                -- фамилия
  first_name   VARCHAR(80) NULL,                -- имя
  patronymic   VARCHAR(80) NULL,                -- отчество
  max_hours    DECIMAL(5,1) NOT NULL DEFAULT 36.0, -- макс. часов в неделю (окно «Автозаполнение»)
  working_days CHAR(7)     NOT NULL DEFAULT '1111111',
      -- рабочие дни преподавателя: 7 символов, Пн..Вс;
      -- '1' — может работать, '0' — не работает (по умолчанию работает всегда)
  work_start   TIME        NULL,                -- начало рабочего дня («со скольки»)
  work_end     TIME        NULL,                -- конец рабочего дня («до скольки»)
  is_deleted   TINYINT(1)  NOT NULL DEFAULT 0,
  KEY idx_teacher_surname (surname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Дисциплины, которые может вести преподаватель -----------------------
-- Заполняется в окне «⚡ Условия заполнения расписания» → вкладка
-- «Преподаватели» (кнопка «Дисциплины»). Пустой список = ограничений нет
-- (преподаватель может вести любую дисциплину). Если список НЕ пуст и
-- дисциплины в нём нет — занятие помечается красным с подсказкой.
CREATE TABLE IF NOT EXISTS teacher_discipline (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  teacher_id    INT NOT NULL,
  discipline_id INT NOT NULL,
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_teacher_disc (teacher_id, discipline_id),
  KEY idx_td_teacher (teacher_id),
  CONSTRAINT fk_td_teacher    FOREIGN KEY (teacher_id)    REFERENCES teacher (id),
  CONSTRAINT fk_td_discipline FOREIGN KEY (discipline_id) REFERENCES discipline (id)
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
-- ЕДИНЫЙ ИСТОЧНИК ПРАВДЫ для окна «📚 Учебный план»: виды учебной
-- деятельности (activity_kind) и виды контроля по семестрам (control_type)
-- — это зеркало данного справочника (синхронизация: syncKindsWithLessonTypes /
-- syncControlsWithLessonTypes в backend/modules/curriculum/curriculum.repo.php).
-- В карточках плана выбрать можно ТОЛЬКО тип, добавленный здесь;
-- названия в плане всегда ПОЛНЫЕ (name).
CREATE TABLE IF NOT EXISTS lesson_type (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NULL,                 -- полное название
  short_name VARCHAR(20) NULL,                 -- сокращение (Лек, Прак, Лаб)
  is_deleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Базовые типы занятий (идемпотентно): гарантируют, что даже при запуске
-- без demo_data.sql справочник не пуст и в карточках плана есть что выбрать.
INSERT IGNORE INTO lesson_type (name, short_name) VALUES
 ('Лекция',                'Лек'),
 ('Практическое занятие',  'Прак'),
 ('Лабораторная работа',   'Лаб'),
 ('Семинарское занятие',   'Сем'),
 ('Экзамен',               'Экз'),
 ('Дифференцированный зачет', 'ДифЗач'),
 ('Зачет',                 'Зач'),
 ('Курсовой проект',       'КурПр'),
 ('Курсовая работа',       'КурРа'),
 ('Самостоятельная работа','СР');

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

-- ============================================================================
-- УЧЕБНЫЙ ПЛАН (окно «📚 Учебный план», модуль backend/modules/curriculum)
-- ----------------------------------------------------------------------------
-- Отдельная «база учебного плана»: схема uchetlfpstu_plan на том же MySQL-сервере.
-- Если сервер не разрешает создавать вторую базу (общий хостинг), таблицы
-- автоматически создаются в основной базе с префиксом plan_ (авто-выбор в
-- backend/config/db.php — функция planDatabase()).
--
-- Смысл таблиц:
--   department          — кафедра;
--   discipline_meta     — дисциплина + её привязка к кафедре
--                         (расширяет справочник discipline основной базы);
--   activity_kind       — виды учебной деятельности (экзамен, лекции,
--                         лабораторные, практические, КСР/КРВ);
--   curriculum          — карточка учебного плана: дисциплина + курс + семестр;
--   curriculum_hours    — распределение общей трудоемкости по видам
--                         деятельности для КАЖДОЙ ГРУППЫ (в каждом семестре
--                         каждой группы свои часы);
--   control_type        — справочник видов контроля (Экзамен, Диф. Зачет,
--                         Зачет, Курсовой проект, Курсовая работа);
--   curriculum_control  — вид контроля по семестрам; в одном пункте можно
--                         указать НЕСКОЛЬКО семестров (несколько строк).
-- ============================================================================

USE uchetlfpstu_plan;

-- Кафедры --------------------------------------------------------------
CREATE TABLE IF NOT EXISTS department (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(150) NOT NULL,            -- название кафедры
  short_name VARCHAR(50)  NULL,                -- сокращение
  is_deleted TINYINT(1)   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_department_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Дисциплины учебного плана (с привязкой к кафедре) --------------------
-- discipline_id ссылается на справочник discipline основной базы
-- (в режиме «префикс plan_» — просто хранит id без внешнего ключа).
CREATE TABLE IF NOT EXISTS discipline_meta (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id INT NULL,                      -- id из справочника дисциплин
  department_id INT NULL,                      -- кафедра
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_discipline_meta (discipline_id),
  KEY idx_dm_department (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Виды учебной деятельности (столбцы часов) -----------------------------
-- Порядок вывода задаётся полем sort_order (экзамен, лекции, лабы, практики, КСР/КРВ).
CREATE TABLE IF NOT EXISTS activity_kind (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,            -- «Лекции», «Лабораторные»...
  short_name VARCHAR(30)  NULL,                -- «Лек», «Лаб», «КСР»...
  sort_order INT NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_activity_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Карточка учебного плана: дисциплина на курсе/семестре -----------------
-- term = 1..8 (1-й курс — семестры 1 и 2, ..., 4-й курс — 7 и 8).
-- Номера семестров 9..12 допускаются для специалитетов.
CREATE TABLE IF NOT EXISTS curriculum (
  id               INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id    INT NOT NULL,               -- ссылка на discipline_meta.id
  course           INT NOT NULL,               -- курс 1..4
  term             INT NOT NULL,               -- семестр 1..12
  total_hours      DECIMAL(8,2) NOT NULL DEFAULT 0, -- общая трудоемкость
  comment          VARCHAR(500) NULL,
  is_deleted       TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NULL,
  UNIQUE KEY uq_curriculum (discipline_id, course, term),
  KEY idx_curr_term (term),
  CONSTRAINT fk_curr_disc FOREIGN KEY (discipline_id) REFERENCES discipline_meta (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Распределение общей трудоемкости по видам учебной деятельности ---------
-- Часы указываются отдельно для КАЖДОЙ ГРУППЫ в конкретном семестре
-- (group_id -> student_group.id основной базы; в режиме «префикс» — без FK).
CREATE TABLE IF NOT EXISTS curriculum_hours (
  id              INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curriculum_id   INT NOT NULL,
  group_id        INT NULL,                    -- группа (student_group.id)
  activity_kind_id INT NOT NULL,               -- вид деятельности
  hours           DECIMAL(8,2) NOT NULL DEFAULT 0,
  is_deleted      TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_curr_hours (curriculum_id, group_id, activity_kind_id),
  KEY idx_ch_curr (curriculum_id),
  CONSTRAINT fk_ch_curr FOREIGN KEY (curriculum_id) REFERENCES curriculum (id),
  CONSTRAINT fk_ch_kind FOREIGN KEY (activity_kind_id) REFERENCES activity_kind (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Справочник видов контроля ---------------------------------------------
CREATE TABLE IF NOT EXISTS control_type (
  id         INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,            -- «Экзамен», «Диф. Зачет»...
  sort_order INT NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_control_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Вид контроля по семестрам ----------------------------------------------
-- Одна дисциплина может иметь несколько видов контроля и несколько
-- семестров у каждого вида (уникальность связки curriculum+control+term).
CREATE TABLE IF NOT EXISTS curriculum_control (
  id            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  curriculum_id INT NOT NULL,
  control_type_id INT NOT NULL,
  term          INT NOT NULL,                  -- семестр (1..12)
  is_deleted    TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_curr_control (curriculum_id, control_type_id, term),
  KEY idx_cc_curr (curriculum_id),
  CONSTRAINT fk_cc_curr FOREIGN KEY (curriculum_id)   REFERENCES curriculum (id),
  CONSTRAINT fk_cc_type FOREIGN KEY (control_type_id) REFERENCES control_type (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Наполнение справочников по умолчанию (идемпотентно) -------------------
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
 ('Экзамен',          10),
 ('Дифференцированный зачет', 20),
 ('Зачет',            30),
 ('Курсовой проект',  40),
 ('Курсовая работа',  50);
