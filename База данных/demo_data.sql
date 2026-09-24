USE UchetLFPSTU;

INSERT INTO TB_Group (GroupName, GroupShortName, GroupYear, GroupMaxContrBook) VALUES
 ('Информационные системы и программирование, 1 курс', 'ИС-21', 2021, 25),
 ('Экономика предприятия, 2 курс',                     'ЭК-22', 2022, 30);

INSERT INTO TB_Discipl (DisciplName, DisciplShortName) VALUES
 ('Математика', 'Мат'),
 ('Программирование', 'Прог'),
 ('Базы данных', 'БД');

INSERT INTO TB_Teacher (TeacherSurname, TeacherFirstName, TeacherLastName) VALUES
 ('Иванов', 'Иван', 'Иванович'),
 ('Петрова', 'Мария', 'Сергеевна');

INSERT INTO TB_Room (Building, RoomNumber, Capacity) VALUES
 ('Главный корпус', '101', 30),
 ('Главный корпус', '205', 24),
 ('Корпус Б', '12', 18);

INSERT INTO TB_TimeType (TimeTypeName, TimeTypeShortName) VALUES
 ('Лекция', 'Лек'),
 ('Практическое занятие', 'Прак'),
 ('Лабораторная работа', 'Лаб');

INSERT INTO TB_TcShip (idGroup) SELECT idGroup FROM TB_Group;

-- Учебный план: у каждой группы свои дисциплины/преподаватели/типы
INSERT INTO TB_GdTcShip (idTcShip, idDiscipl, idTeacher, idTimeType, GdTcShipTerm, GdTcShipBHour, GdTcShipVHour)
SELECT tc.idTcShip, x.idDiscipl, x.idTeacher, x.idTimeType, 1, 34, 18
FROM TB_TcShip tc
JOIN (
  SELECT idGroup, MIN(idDiscipl) AS idDiscipl, MIN(idTeacher) AS idTeacher, MIN(idTimeType) AS idTimeType
  FROM TB_Group
  CROSS JOIN TB_Discipl
  CROSS JOIN TB_Teacher
  CROSS JOIN TB_TimeType
  GROUP BY idGroup
) x ON x.idGroup = tc.idGroup;

INSERT INTO TB_Weeks (WeekName, StartDate, EndDate) VALUES
 ('1 неделя', '2026-09-01', '2026-09-07'),
 ('2 неделя', '2026-09-08', '2026-09-14');

INSERT INTO TB_Holidays (HolidayDate) VALUES ('2026-06-12');
