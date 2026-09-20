-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1
-- Время создания: Сен 21 2026 г., 00:33
-- Версия сервера: 10.4.32-MariaDB
-- Версия PHP: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `uchetlfpstu`
--

-- --------------------------------------------------------

--
-- Структура таблицы `tb_appuser`
--

CREATE TABLE `tb_appuser` (
  `idUser` int(11) NOT NULL,
  `LoginName` varchar(50) NOT NULL,
  `PasswordHash` varchar(255) NOT NULL,
  `FullName` varchar(100) DEFAULT NULL,
  `IsDeleted` tinyint(1) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_appuser`
--

INSERT INTO `tb_appuser` (`idUser`, `LoginName`, `PasswordHash`, `FullName`, `IsDeleted`, `CreatedAt`, `UpdatedAt`) VALUES
(2, 'admin', '$2y$10$Y3cdf5IWVIDvvPC.kBDHTOhR5a0DZGLlWiyt1vAZugOwu/QEMIcIa', 'Администратор', 0, '2026-09-21 02:56:38', '2026-09-21 02:59:51');

-- --------------------------------------------------------

--
-- Структура таблицы `tb_discipl`
--

CREATE TABLE `tb_discipl` (
  `idDiscipl` int(11) NOT NULL,
  `Name` varchar(255) DEFAULT NULL,
  `DisciplName` varchar(200) NOT NULL,
  `DisciplShortName` varchar(50) DEFAULT NULL,
  `DisciplDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_discipl`
--

INSERT INTO `tb_discipl` (`idDiscipl`, `Name`, `DisciplName`, `DisciplShortName`, `DisciplDeleted`) VALUES
(1, 'Высшая математика', 'Высшая математика', 'ВМ', 0),
(2, 'Программирование', 'Программирование', 'Прог', 0),
(3, 'Базы данных', 'Базы данных', 'БД', 0),
(4, 'Веб-разработка', 'Веб-разработка', 'Веб', 0),
(5, 'Физика', 'Физика', 'Физ', 0),
(6, 'История', 'История', 'Ист', 0);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_gdtcship`
--

CREATE TABLE `tb_gdtcship` (
  `idGdTcShip` int(11) NOT NULL,
  `idTcShip` int(11) NOT NULL,
  `idDiscipl` int(11) NOT NULL,
  `idTeacher` int(11) NOT NULL,
  `idTimeType` int(11) NOT NULL,
  `GdTcShipTerm` int(11) NOT NULL,
  `GdTcShipBHour` decimal(5,2) DEFAULT 0.00,
  `GdTcShipVHour` decimal(5,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_gdtcship`
--

INSERT INTO `tb_gdtcship` (`idGdTcShip`, `idTcShip`, `idDiscipl`, `idTeacher`, `idTimeType`, `GdTcShipTerm`, `GdTcShipBHour`, `GdTcShipVHour`) VALUES
(1, 1, 1, 1, 1, 1, 36.00, 0.00),
(2, 1, 1, 1, 2, 1, 18.00, 0.00),
(3, 1, 2, 2, 1, 1, 34.00, 0.00),
(4, 1, 2, 2, 3, 1, 34.00, 0.00),
(5, 1, 3, 3, 1, 1, 17.00, 0.00),
(6, 1, 3, 3, 3, 1, 17.00, 0.00);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_group`
--

CREATE TABLE `tb_group` (
  `idGroup` int(11) NOT NULL,
  `Name` varchar(100) DEFAULT NULL,
  `GroupShortName` varchar(20) NOT NULL,
  `GroupName` varchar(200) DEFAULT NULL,
  `GroupYear` int(11) NOT NULL DEFAULT 1,
  `GroupMaxContrBook` int(11) DEFAULT NULL,
  `GroupDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_group`
--

INSERT INTO `tb_group` (`idGroup`, `Name`, `GroupShortName`, `GroupName`, `GroupYear`, `GroupMaxContrBook`, `GroupDeleted`) VALUES
(1, 'ПИ-21', 'ПИ-21', 'Программная инженерия 21', 2021, 25, 0),
(2, 'ИБ-21', 'ИБ-21', 'Информационная безопасность 21', 2021, 20, 0),
(3, 'ДИ-22', 'ДИ-22', 'Дизайн 22', 2022, 15, 0);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_holidays`
--

CREATE TABLE `tb_holidays` (
  `idHoliday` int(11) NOT NULL,
  `HolidayDate` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_holidays`
--

INSERT INTO `tb_holidays` (`idHoliday`, `HolidayDate`) VALUES
(9, '1970-01-01'),
(1, '2024-11-04'),
(2, '2024-12-31'),
(3, '2025-01-01'),
(4, '2025-01-07'),
(5, '2025-02-23'),
(6, '2025-03-08'),
(7, '2025-05-01'),
(8, '2025-05-09');

-- --------------------------------------------------------

--
-- Структура таблицы `tb_room`
--

CREATE TABLE `tb_room` (
  `idRoom` int(11) NOT NULL,
  `Name` varchar(100) DEFAULT NULL,
  `Building` varchar(20) NOT NULL,
  `RoomNumber` varchar(20) NOT NULL,
  `Capacity` int(11) DEFAULT NULL,
  `IsDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_room`
--

INSERT INTO `tb_room` (`idRoom`, `Name`, `Building`, `RoomNumber`, `Capacity`, `IsDeleted`) VALUES
(1, 'Главный 101', 'Главный', '101', 30, 0),
(2, 'Главный 102', 'Главный', '102', 25, 0),
(3, 'Главный 201', 'Главный', '201', 40, 0),
(4, 'Лабораторный 301', 'Лабораторный', '301', 20, 0),
(5, 'Лабораторный 302', 'Лабораторный', '302', 15, 0);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_roompref`
--

CREATE TABLE `tb_roompref` (
  `idRoomPref` int(11) NOT NULL,
  `idDiscipl` int(11) NOT NULL,
  `idRoom` int(11) NOT NULL,
  `Priority` int(11) NOT NULL DEFAULT 100,
  `IsPrimary` tinyint(1) NOT NULL DEFAULT 0,
  `IsDeleted` tinyint(1) NOT NULL DEFAULT 0,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `UpdatedAt` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `tb_schedule`
--

CREATE TABLE `tb_schedule` (
  `idSchedule` int(11) NOT NULL,
  `idWeek` int(11) NOT NULL,
  `idGroup` int(11) NOT NULL,
  `idDiscipl` int(11) DEFAULT NULL,
  `idTeacher` int(11) DEFAULT NULL,
  `idRoom` int(11) DEFAULT NULL,
  `DayOfWeek` int(11) NOT NULL,
  `TimeSlot` int(11) NOT NULL,
  `idLessonType` int(11) DEFAULT NULL,
  `Hours` decimal(4,2) NOT NULL DEFAULT 2.00,
  `CustomText` varchar(500) DEFAULT NULL,
  `IsDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `tb_tcship`
--

CREATE TABLE `tb_tcship` (
  `idTcShip` int(11) NOT NULL,
  `idGroup` int(11) NOT NULL,
  `TcShipDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_tcship`
--

INSERT INTO `tb_tcship` (`idTcShip`, `idGroup`, `TcShipDeleted`) VALUES
(1, 1, 0),
(2, 2, 0),
(3, 3, 0);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_teacher`
--

CREATE TABLE `tb_teacher` (
  `idTeacher` int(11) NOT NULL,
  `TeacherSurname` varchar(50) NOT NULL,
  `TeacherFirstName` varchar(50) NOT NULL,
  `TeacherLastName` varchar(50) DEFAULT NULL,
  `TeacherDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_teacher`
--

INSERT INTO `tb_teacher` (`idTeacher`, `TeacherSurname`, `TeacherFirstName`, `TeacherLastName`, `TeacherDeleted`) VALUES
(1, 'Иванов', 'Иван', 'Иванович', 0),
(2, 'Петров', 'Петр', 'Петрович', 0),
(3, 'Сидоров', 'Сидор', 'Сидорович', 0),
(4, 'Кузнецова', 'Анна', 'Михайловна', 0);

-- --------------------------------------------------------

--
-- Структура таблицы `tb_timetype`
--

CREATE TABLE `tb_timetype` (
  `idTimeType` int(11) NOT NULL,
  `Name` varchar(100) DEFAULT NULL,
  `TimeTypeName` varchar(50) NOT NULL,
  `TimeTypeShortName` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_timetype`
--

INSERT INTO `tb_timetype` (`idTimeType`, `Name`, `TimeTypeName`, `TimeTypeShortName`) VALUES
(1, 'Лекция', 'Лекция', 'Л'),
(2, 'Практическое занятие', 'Практическое занятие', 'ПЗ'),
(3, 'Лабораторная работа', 'Лабораторная работа', 'ЛР'),
(4, 'Семинар', 'Семинар', 'С'),
(5, 'Консультация', 'Консультация', 'К'),
(6, 'Экзамен', 'Экзамен', 'Э'),
(7, 'Зачет', 'Зачет', 'З');

-- --------------------------------------------------------

--
-- Структура таблицы `tb_weeks`
--

CREATE TABLE `tb_weeks` (
  `idWeek` int(11) NOT NULL,
  `Name` varchar(50) DEFAULT NULL,
  `WeekName` varchar(50) NOT NULL,
  `DateStart` date NOT NULL,
  `DateEnd` date NOT NULL,
  `IsDeleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Дамп данных таблицы `tb_weeks`
--

INSERT INTO `tb_weeks` (`idWeek`, `Name`, `WeekName`, `DateStart`, `DateEnd`, `IsDeleted`) VALUES
(1, 'Неделя 1', 'Неделя 1', '2024-09-02', '2024-09-08', 0),
(2, 'Неделя 2', 'Неделя 2', '2024-09-09', '2024-09-15', 0),
(3, 'Неделя 3', 'Неделя 3', '2024-09-16', '2024-09-22', 0),
(4, 'Неделя 4', 'Неделя 4', '2024-09-23', '2024-09-29', 0),
(5, 'Неделя 5', 'Неделя 5', '2024-09-30', '2024-10-06', 0),
(6, 'Неделя 6', 'Неделя 6', '2024-10-07', '2024-10-13', 0),
(7, 'Неделя 7', 'Неделя 7', '2024-10-14', '2024-10-20', 0),
(8, 'Неделя 8', 'Неделя 8', '2024-10-21', '2024-10-27', 0),
(9, 'Неделя 9', 'Неделя 9', '2024-10-28', '2024-11-03', 0),
(10, 'Неделя 10', 'Неделя 10', '2024-11-04', '2024-11-10', 0),
(11, 'Неделя 11', 'Неделя 11', '2024-11-11', '2024-11-17', 0),
(12, 'Неделя 12', 'Неделя 12', '2024-11-18', '2024-11-24', 0),
(13, 'Неделя 13', 'Неделя 13', '2024-11-25', '2024-12-01', 0),
(14, 'Неделя 14', 'Неделя 14', '2024-12-02', '2024-12-08', 0),
(15, 'Неделя 15', 'Неделя 15', '2024-12-09', '2024-12-15', 0),
(16, 'Неделя 16', 'Неделя 16', '2024-12-16', '2024-12-22', 0),
(17, 'Неделя 17', 'Неделя 17', '2024-12-23', '2024-12-29', 0),
(18, 'Неделя 18', 'Неделя 18', '2024-12-30', '2025-01-05', 0),
(19, 'Неделя 19', 'Неделя 19', '2025-01-06', '2025-01-12', 0),
(20, 'Неделя 20', 'Неделя 20', '2025-01-13', '2025-01-19', 0),
(21, '28.09.2026 - 04.10.2026', '', '2026-09-28', '2026-10-04', 0),
(22, '28.09.2026 - 04.10.2026', '', '2026-09-28', '2026-10-04', 0),
(23, 'еууцфуп', '', '2026-09-28', '2026-10-04', 0),
(24, '28.09.2026 - 04.10.2026', '', '2026-09-28', '2026-10-04', 0);

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `tb_appuser`
--
ALTER TABLE `tb_appuser`
  ADD PRIMARY KEY (`idUser`),
  ADD UNIQUE KEY `UX_LoginName` (`LoginName`);

--
-- Индексы таблицы `tb_discipl`
--
ALTER TABLE `tb_discipl`
  ADD PRIMARY KEY (`idDiscipl`),
  ADD KEY `IX_DisciplName` (`DisciplName`);

--
-- Индексы таблицы `tb_gdtcship`
--
ALTER TABLE `tb_gdtcship`
  ADD PRIMARY KEY (`idGdTcShip`),
  ADD KEY `IX_idTcShip` (`idTcShip`),
  ADD KEY `IX_idDiscipl` (`idDiscipl`),
  ADD KEY `IX_idTeacher` (`idTeacher`),
  ADD KEY `FK_GdTcShip_TimeType` (`idTimeType`);

--
-- Индексы таблицы `tb_group`
--
ALTER TABLE `tb_group`
  ADD PRIMARY KEY (`idGroup`),
  ADD KEY `IX_GroupShortName` (`GroupShortName`);

--
-- Индексы таблицы `tb_holidays`
--
ALTER TABLE `tb_holidays`
  ADD PRIMARY KEY (`idHoliday`),
  ADD UNIQUE KEY `UX_HolidayDate` (`HolidayDate`);

--
-- Индексы таблицы `tb_room`
--
ALTER TABLE `tb_room`
  ADD PRIMARY KEY (`idRoom`),
  ADD KEY `IX_Building_RoomNumber` (`Building`,`RoomNumber`);

--
-- Индексы таблицы `tb_roompref`
--
ALTER TABLE `tb_roompref`
  ADD PRIMARY KEY (`idRoomPref`),
  ADD KEY `IX_idDiscipl` (`idDiscipl`),
  ADD KEY `IX_idRoom` (`idRoom`);

--
-- Индексы таблицы `tb_schedule`
--
ALTER TABLE `tb_schedule`
  ADD PRIMARY KEY (`idSchedule`),
  ADD KEY `IX_idWeek` (`idWeek`),
  ADD KEY `IX_idGroup` (`idGroup`),
  ADD KEY `IX_DayOfWeek_TimeSlot` (`DayOfWeek`,`TimeSlot`),
  ADD KEY `FK_Schedule_Discipl` (`idDiscipl`),
  ADD KEY `FK_Schedule_Teacher` (`idTeacher`),
  ADD KEY `FK_Schedule_Room` (`idRoom`),
  ADD KEY `FK_Schedule_LessonType` (`idLessonType`);

--
-- Индексы таблицы `tb_tcship`
--
ALTER TABLE `tb_tcship`
  ADD PRIMARY KEY (`idTcShip`),
  ADD KEY `IX_idGroup` (`idGroup`);

--
-- Индексы таблицы `tb_teacher`
--
ALTER TABLE `tb_teacher`
  ADD PRIMARY KEY (`idTeacher`),
  ADD KEY `IX_TeacherSurname` (`TeacherSurname`);

--
-- Индексы таблицы `tb_timetype`
--
ALTER TABLE `tb_timetype`
  ADD PRIMARY KEY (`idTimeType`);

--
-- Индексы таблицы `tb_weeks`
--
ALTER TABLE `tb_weeks`
  ADD PRIMARY KEY (`idWeek`),
  ADD KEY `IX_StartDate` (`DateStart`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `tb_appuser`
--
ALTER TABLE `tb_appuser`
  MODIFY `idUser` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT для таблицы `tb_discipl`
--
ALTER TABLE `tb_discipl`
  MODIFY `idDiscipl` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT для таблицы `tb_gdtcship`
--
ALTER TABLE `tb_gdtcship`
  MODIFY `idGdTcShip` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT для таблицы `tb_group`
--
ALTER TABLE `tb_group`
  MODIFY `idGroup` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `tb_holidays`
--
ALTER TABLE `tb_holidays`
  MODIFY `idHoliday` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT для таблицы `tb_room`
--
ALTER TABLE `tb_room`
  MODIFY `idRoom` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT для таблицы `tb_roompref`
--
ALTER TABLE `tb_roompref`
  MODIFY `idRoomPref` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tb_schedule`
--
ALTER TABLE `tb_schedule`
  MODIFY `idSchedule` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `tb_tcship`
--
ALTER TABLE `tb_tcship`
  MODIFY `idTcShip` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT для таблицы `tb_teacher`
--
ALTER TABLE `tb_teacher`
  MODIFY `idTeacher` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT для таблицы `tb_timetype`
--
ALTER TABLE `tb_timetype`
  MODIFY `idTimeType` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `tb_weeks`
--
ALTER TABLE `tb_weeks`
  MODIFY `idWeek` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `tb_gdtcship`
--
ALTER TABLE `tb_gdtcship`
  ADD CONSTRAINT `FK_GdTcShip_Discipl` FOREIGN KEY (`idDiscipl`) REFERENCES `tb_discipl` (`idDiscipl`),
  ADD CONSTRAINT `FK_GdTcShip_TcShip` FOREIGN KEY (`idTcShip`) REFERENCES `tb_tcship` (`idTcShip`) ON DELETE CASCADE,
  ADD CONSTRAINT `FK_GdTcShip_Teacher` FOREIGN KEY (`idTeacher`) REFERENCES `tb_teacher` (`idTeacher`),
  ADD CONSTRAINT `FK_GdTcShip_TimeType` FOREIGN KEY (`idTimeType`) REFERENCES `tb_timetype` (`idTimeType`);

--
-- Ограничения внешнего ключа таблицы `tb_roompref`
--
ALTER TABLE `tb_roompref`
  ADD CONSTRAINT `FK_RoomPref_Discipl` FOREIGN KEY (`idDiscipl`) REFERENCES `tb_discipl` (`idDiscipl`),
  ADD CONSTRAINT `FK_RoomPref_Room` FOREIGN KEY (`idRoom`) REFERENCES `tb_room` (`idRoom`);

--
-- Ограничения внешнего ключа таблицы `tb_schedule`
--
ALTER TABLE `tb_schedule`
  ADD CONSTRAINT `FK_Schedule_Discipl` FOREIGN KEY (`idDiscipl`) REFERENCES `tb_discipl` (`idDiscipl`),
  ADD CONSTRAINT `FK_Schedule_Group` FOREIGN KEY (`idGroup`) REFERENCES `tb_group` (`idGroup`),
  ADD CONSTRAINT `FK_Schedule_LessonType` FOREIGN KEY (`idLessonType`) REFERENCES `tb_timetype` (`idTimeType`),
  ADD CONSTRAINT `FK_Schedule_Room` FOREIGN KEY (`idRoom`) REFERENCES `tb_room` (`idRoom`),
  ADD CONSTRAINT `FK_Schedule_Teacher` FOREIGN KEY (`idTeacher`) REFERENCES `tb_teacher` (`idTeacher`),
  ADD CONSTRAINT `FK_Schedule_Week` FOREIGN KEY (`idWeek`) REFERENCES `tb_weeks` (`idWeek`);

--
-- Ограничения внешнего ключа таблицы `tb_tcship`
--
ALTER TABLE `tb_tcship`
  ADD CONSTRAINT `FK_TcShip_Group` FOREIGN KEY (`idGroup`) REFERENCES `tb_group` (`idGroup`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
