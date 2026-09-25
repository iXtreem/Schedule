

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

  planLessonTypes: (groupId, term, subjectId, teacherId) =>
    requestJson(
      `${API}?entity=plan_lesson_types&group_id=${groupId}&term=${term}&subject_id=${subjectId}&teacher_id=${teacherId}`,
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
