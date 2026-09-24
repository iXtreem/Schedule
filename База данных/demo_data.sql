-- ============================================================================
-- ДЕМОНСТРАЦИОННЫЕ ДАННЫЕ для базы uchetlfpstu (новая схема)
-- Выполнять ПОСЛЕ schema.sql. Все таблицы — с маленькой буквы, snake_case.
-- ============================================================================
USE uchetlfpstu;

-- Учебные группы (бывшая TB_Group)
INSERT INTO student_group (name, short_name, admission_year, max_students) VALUES
 ('Информационные системы и программирование, 1 курс', 'ИС-21', 2021, 25),
 ('Экономика предприятия, 2 курс',                     'ЭК-22', 2022, 30);

-- Дисциплины (бывшая TB_Discipl)
INSERT INTO discipline (name, short_name) VALUES
 ('Математика', 'Мат'),
 ('Программирование', 'Прог'),
 ('Базы данных', 'БД');

-- Преподаватели (бывшая TB_Teacher)
INSERT INTO teacher (surname, first_name, patronymic) VALUES
 ('Иванов', 'Иван', 'Иванович'),
 ('Петрова', 'Мария', 'Сергеевна');

-- Аудитории (бывшая TB_Room)
INSERT INTO room (building, room_number, capacity) VALUES
 ('Главный корпус', '101', 30),
 ('Главный корпус', '205', 24),
 ('Корпус Б', '12', 18);

-- Типы занятий (бывшая TB_TimeType)
INSERT INTO lesson_type (name, short_name) VALUES
 ('Лекция', 'Лек'),
 ('Практическое занятие', 'Прак'),
 ('Лабораторная работа', 'Лаб');

-- Потоки: по одному на каждую группу (бывшая TB_TcShip)
INSERT INTO study_stream (group_id)
SELECT id FROM student_group WHERE is_deleted = 0;

-- Учебный план (бывшая TB_GdTcShip):
-- каждой группе — минимальная дисциплина x минимальный преподаватель x минимальный тип,
-- 34 часа базовой части и 18 часов вариативной части в 1-м семестре
INSERT INTO plan_hours (stream_id, discipline_id, teacher_id, lesson_type_id, term, base_hours, var_hours)
SELECT st.id,
       (SELECT MIN(id) FROM discipline),
       (SELECT MIN(id) FROM teacher),
       (SELECT MIN(id) FROM lesson_type),
       1, 34, 18
FROM study_stream st;

-- Учебные недели (бывшая TB_Weeks)
INSERT INTO week (name, start_date, end_date) VALUES
 ('1 неделя', '2026-09-01', '2026-09-07'),
 ('2 неделя', '2026-09-08', '2026-09-14');

-- Праздничные дни (бывшая TB_Holidays)
INSERT INTO holiday (holiday_date) VALUES ('2026-06-12');