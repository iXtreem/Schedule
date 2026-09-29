const API = new URL(
  "./backend/public/index.php",
  window.location.href,
).toString();

async function requestJson(url, options = {}, defaultValue = null) {
  const { throwOnError = false, ...fetchOptions } = options;
  if (!fetchOptions.credentials) fetchOptions.credentials = "same-origin";

  try {
    const res = await fetch(url, fetchOptions);
    const text = await res.text();

    const looksLikeHtml = text.trim().startsWith("<");
    if (looksLikeHtml) {
      console.error("SERVER RETURNED HTML (not JSON):", text);
      const err = new Error("Сервер вернул HTML вместо JSON (см. консоль).");
      if (throwOnError) throw err;
      return defaultValue;
    }

    if (!text.trim()) return defaultValue;

    const data = JSON.parse(text);

    if (
      res.status === 401 &&
      !String(url).includes("entity=auth_") &&
      !window.location.pathname.toLowerCase().endsWith("/login.html")
    ) {
      window.location.href = "./login.html";
    }

    if (!res.ok) {
      console.error("API HTTP error:", res.status, data);
      const err = new Error(data?.error || `HTTP ${res.status}`);
      err.status = res.status;
      err.data = data;
      if (throwOnError) throw err;
      return defaultValue;
    }

    return data;
  } catch (e) {
    console.error("API error:", e);
    if (throwOnError) throw e;
    return defaultValue;
  }
}

export const api = {
  authMe: () => requestJson(`${API}?entity=auth_me`, {}, null),

  authLogin: (payload) =>
    requestJson(`${API}?entity=auth_login`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  authLogout: () =>
    requestJson(`${API}?entity=auth_logout`, {
      method: "POST",
      throwOnError: true,
    }),

  authRegisterFirst: (payload) =>
    requestJson(`${API}?entity=auth_register_first`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),
  groups: () => requestJson(`${API}?entity=groups`, {}, []),
  weeks: () => requestJson(`${API}?entity=weeks`, {}, []),
  subjects: () => requestJson(`${API}?entity=subjects`, {}, []),
  teachers: () => requestJson(`${API}?entity=teachers`, {}, []),
  // Недельная нагрузка преподавателей для подсветки в модалке занятия:
  // [{ id, max_hours, week_hours }, ...] по выбранной (или актуальной) неделе.
  teacherWeeklyLoad: (weekId) =>
    requestJson(
      `${API}?entity=teachers&load=week${weekId ? `&week_id=${Number(weekId)}` : ""}`,
      {},
      [],
    ),
  // Сохранение лимитов часов и графика работы преподавателей (окно
  // «Автозаполнение», вкладка «Преподаватели»).
  // items: [{ id, max_hours?, working_days?, work_start?, work_end? }]
  // working_days — строка из 7 символов '0'/'1' (Пн..Вс),
  // work_start / work_end — "HH:MM" или "" (без ограничения).
  saveTeacherHours: (items) =>
    requestJson(`${API}?entity=teachers`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        items: (Array.isArray(items) ? items : []).map((x) => {
          const item = { id: Number(x?.id) };
          if (x?.max_hours !== undefined) item.max_hours = x.max_hours;
          if (x?.working_days !== undefined) item.working_days = x.working_days;
          if (x?.work_start !== undefined) item.work_start = x.work_start;
          if (x?.work_end !== undefined) item.work_end = x.work_end;
          return item;
        }),
      }),
      throwOnError: true,
    }),
  rooms: () => requestJson(`${API}?entity=rooms`, {}, []),
  lessonTypes: () => requestJson(`${API}?entity=lesson_types`, {}, []),

  scheduleForWeek: (weekId) =>
    requestJson(`${API}?entity=schedule_for_week&week_id=${weekId}`, {}, []),
  createLesson: (payload) =>
    requestJson(`${API}?entity=schedule_lessons`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  createWeek: (payload) =>
    requestJson(`${API}?entity=weeks`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  // Автосоздание недель семестра в одну кнопку (с учётом выходных/праздников).
  // payload: { start_date: "YYYY-MM-DD", end_date: "YYYY-MM-DD" }
  generateWeeks: (payload) =>
    requestJson(`${API}?entity=weeks_generate`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  updateLesson: (id, payload) =>
    requestJson(`${API}?entity=schedule_lessons&id=${id}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  deleteLesson: (id) =>
    requestJson(`${API}?entity=schedule_lessons&id=${id}`, {
      method: "DELETE",
      throwOnError: true,
    }),
  planSubjects: (groupId, term) =>
    requestJson(
      `${API}?entity=plan_subjects&group_id=${groupId}&term=${term}`,
      {},
      [],
    ),

  planTeachers: (groupId, term, subjectId) =>
    requestJson(
      `${API}?entity=plan_teachers&group_id=${groupId}&term=${term}&subject_id=${subjectId}`,
      {},
      [],
    ),

  // Преподаватели дисциплины для модалки занятия: [] — если план не задал
  // единственного преподавателя (тогда фронт показывает справочник teacher).
  planSubjectTeachers: (groupId, term, subjectId) =>
    requestJson(
      `${API}?entity=plan_subject_teachers&group_id=${groupId}&term=${term}&subject_id=${subjectId}`,
      {},
      [],
    ),

  planLessonTypes: (groupId, term, subjectId, teacherId) =>
    requestJson(
      `${API}?entity=plan_lesson_types&group_id=${groupId}&term=${term}&subject_id=${subjectId}&teacher_id=${teacherId}`,
      {},
      [],
    ),

  // Типы занятий преподавателя без дисциплины (когда в модалке выбран только
  // преподаватель). planned_hours = null -> счётчика «(0/0)» нет, тип доступен.
  planTeacherLessonTypes: (groupId, term, teacherId) =>
    requestJson(
      `${API}?entity=plan_teacher_lesson_types&group_id=${groupId || 0}&term=${term || 0}&teacher_id=${teacherId}`,
      {},
      [],
    ),

  planTeachersBase: (groupId, term) =>
    requestJson(
      `${API}?entity=plan_teachers_base&group_id=${groupId}&term=${term}`,
      {},
      [],
    ),

  planSubjectsByTeacher: (groupId, term, teacherId) =>
    requestJson(
      `${API}?entity=plan_subjects_by_teacher&group_id=${groupId}&term=${term}&teacher_id=${teacherId}`,
      {},
      [],
    ),
  planRoomPrefs: (subjectId) =>
    requestJson(
      `${API}?entity=room_prefs&subject_id=${encodeURIComponent(String(subjectId))}`,
      {},
      [],
    ),

  planRoomPrefsAll: () => requestJson(`${API}?entity=room_prefs_all`, {}, []),

  saveRoomPrefs: (subjectId, prefs) =>
    requestJson(`${API}?entity=room_prefs`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        subject_id: Number(subjectId),
        prefs: Array.isArray(prefs) ? prefs : [],
      }),
      throwOnError: true,
    }),
  // kind: "off" — красный день (полный выходной, исключается из расписания),
  //       "reduced" — жёлтый день (праздник с альтернативным расписанием).
  addHoliday: (dateStr, kind = "off") =>
    requestJson(`${API}?entity=holiday`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ date: dateStr, kind }),
      throwOnError: true,
    }),

  removeHoliday: (dateStr) =>
    requestJson(`${API}?entity=holiday&date=${encodeURIComponent(dateStr)}`, {
      method: "DELETE",
      throwOnError: true,
    }),

  holidaysRange: (start, end) =>
    requestJson(
      `${API}?entity=holidays_range&start=${start}&end=${end}`,
      {},
      [],
    ),

      dictList: (name) => requestJson(`${API}?entity=dict_${name}`, {}, []),

  dictCreate: (name, payload) =>
    requestJson(`${API}?entity=dict_${name}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  dictUpdate: (name, payload) =>
    requestJson(`${API}?entity=dict_${name}`, {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),

  dictDelete: (name, id) =>
    requestJson(
      `${API}?entity=dict_${name}&id=${encodeURIComponent(String(id))}`,
      { method: "DELETE", throwOnError: true },
    ),

  // ===== Расписание звонков (время пар) =====
  bellSchedule: () => requestJson(`${API}?entity=bell_schedule`, {}, null),

  saveBellSchedule: (payload) =>
    requestJson(`${API}?entity=bell_schedule`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
      throwOnError: true,
    }),
};