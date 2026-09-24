CREATE DATABASE IF NOT EXISTS UchetLFPSTU
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE UchetLFPSTU;

-- Пользователи -------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_AppUser (
  idUser       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  LoginName    VARCHAR(60)  NOT NULL,
  PasswordHash VARCHAR(255) NOT NULL,
  FullName     VARCHAR(150) NULL,
  IsDeleted    TINYINT(1)   NOT NULL DEFAULT 0,
  CreatedAt    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt    DATETIME     NULL,
  UNIQUE KEY uq_appuser_login (LoginName)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Группы -------------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Group (
  idGroup           INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  GroupName         VARCHAR(100) NOT NULL,
  GroupShortName    VARCHAR(30)  NULL,
  GroupYear         INT          NULL,      -- год поступления (нужен для расчёта семестров)
  GroupMaxContrBook INT          NULL,
  GroupDeleted      TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Дисциплины ---------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Discipl (
  idDiscipl        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  DisciplName      VARCHAR(255) NOT NULL,
  DisciplShortName VARCHAR(60)  NULL,
  DisciplDeleted   TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Преподаватели ------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Teacher (
  idTeacher       INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  TeacherSurname  VARCHAR(80) NULL,
  TeacherFirstName VARCHAR(80) NULL,
  TeacherLastName VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Аудитории ----------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Room (
  idRoom     INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  Building   VARCHAR(30)  NULL,
  RoomNumber VARCHAR(30)  NULL,
  Capacity   INT          NULL,
  IsDeleted  TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Типы занятий -------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_TimeType (
  idTimeType        INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  TimeTypeName      VARCHAR(60) NULL,
  TimeTypeShortName VARCHAR(20) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебные недели -----------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Weeks (
  idWeek    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  WeekName  VARCHAR(60) NOT NULL,
  StartDate DATE NOT NULL,
  EndDate   DATE NOT NULL,
  IsDeleted TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Праздничные дни ----------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Holidays (
  idHoliday   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  HolidayDate DATE NOT NULL,
  UNIQUE KEY uq_holiday_date (HolidayDate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебный план: поток (группа + семестр) ------------------------
CREATE TABLE IF NOT EXISTS TB_TcShip (
  idTcShip      INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idGroup       INT NOT NULL,
  TcShipDeleted TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_tcship_group (idGroup),
  CONSTRAINT fk_tcship_group FOREIGN KEY (idGroup) REFERENCES TB_Group (idGroup)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Учебный план: часы по дисциплине/преподавателю/типу -----------
CREATE TABLE IF NOT EXISTS TB_GdTcShip (
  idGdTcShip    INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idTcShip      INT NOT NULL,
  idDiscipl     INT NOT NULL,
  idTeacher     INT NOT NULL,
  idTimeType    INT NOT NULL,
  GdTcShipTerm  INT NOT NULL,          -- номер семестра (1..N)
  GdTcShipBHour DECIMAL(7,2) NOT NULL DEFAULT 0,  -- часов базовой части
  GdTcShipVHour DECIMAL(7,2) NOT NULL DEFAULT 0,  -- часов вариативной части
  KEY idx_gdtcship_plan (idTcShip, idDiscipl, idTeacher, idTimeType),
  CONSTRAINT fk_gdtcship_tcship   FOREIGN KEY (idTcShip)   REFERENCES TB_TcShip (idTcShip),
  CONSTRAINT fk_gdtcship_discipl  FOREIGN KEY (idDiscipl)  REFERENCES TB_Discipl (idDiscipl),
  CONSTRAINT fk_gdtcship_teacher  FOREIGN KEY (idTeacher)  REFERENCES TB_Teacher (idTeacher),
  CONSTRAINT fk_gdtcship_timetype FOREIGN KEY (idTimeType) REFERENCES TB_TimeType (idTimeType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Расписание ---------------------------------------------------
CREATE TABLE IF NOT EXISTS TB_Schedule (
  idSchedule   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idWeek       INT NOT NULL,
  idGroup      INT NOT NULL,
  idDiscipl    INT NULL,
  idTeacher    INT NULL,
  idRoom       INT NULL,
  idLessonType INT NULL,
  DayOfWeek    INT NOT NULL,           -- 1..6
  TimeSlot     INT NOT NULL,           -- номер пары
  Hours        DECIMAL(5,2) NOT NULL DEFAULT 2.00,
  CustomText   VARCHAR(500) NULL,      -- своя запись в ячейке
  IsDeleted    TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_schedule_week (idWeek, DayOfWeek, TimeSlot),
  CONSTRAINT fk_schedule_week  FOREIGN KEY (idWeek)       REFERENCES TB_Weeks (idWeek),
  CONSTRAINT fk_schedule_group FOREIGN KEY (idGroup)      REFERENCES TB_Group (idGroup),
  CONSTRAINT fk_schedule_disc  FOREIGN KEY (idDiscipl)    REFERENCES TB_Discipl (idDiscipl),
  CONSTRAINT fk_schedule_teach FOREIGN KEY (idTeacher)    REFERENCES TB_Teacher (idTeacher),
  CONSTRAINT fk_schedule_room  FOREIGN KEY (idRoom)       REFERENCES TB_Room (idRoom),
  CONSTRAINT fk_schedule_type  FOREIGN KEY (idLessonType) REFERENCES TB_TimeType (idTimeType)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Предпочтения аудиторий по дисциплине -------------------------
CREATE TABLE IF NOT EXISTS TB_RoomPref (
  idRoomPref INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  idDiscipl  INT NOT NULL,
  idRoom     INT NOT NULL,
  Priority   INT NOT NULL DEFAULT 100,
  IsPrimary  TINYINT(1) NOT NULL DEFAULT 0,
  IsDeleted  TINYINT(1) NOT NULL DEFAULT 0,
  CreatedAt  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UpdatedAt  DATETIME NULL,
  KEY idx_roomepref_disc (idDiscipl),
  CONSTRAINT fk_roomepref_disc FOREIGN KEY (idDiscipl) REFERENCES TB_Discipl (idDiscipl),
  CONSTRAINT fk_roomepref_room FOREIGN KEY (idRoom)    REFERENCES TB_Room (idRoom)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
