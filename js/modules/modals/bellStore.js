
// Общее хранилище «Время пар» (расписание звонков).
// Единый источник истины для вкладки «Время пар» (lessonTimes.js)
// и основной таблицы расписания (renderTable.js / renderTeacherTable.js):
// после сохранения настроек таблица сразу показывает новые времена.
//
// Формат данных — как в backend (TB_BellSchedule):
//   { workday: {1:"08:00-08:45", 2:"09:00-09:45", ...}, sunday: {...}, holiday: {...} }
// Значения по умолчанию — из js/LoadFromBD/bd.js (BELL_SCHEDULES), пока с сервера
// ничего не загружено/сохранено.

import { BELL_SCHEDULES } from "../../LoadFromBD/bd.js";
import { api } from "../../LoadFromBD/api.js";

let current = { ...BELL_SCHEDULES }; // актуальные данные (серверные поверх дефолтов)
let loaded = false; // была ли успешная загрузка с сервера в этой сессии

export function getBellSchedules() {
  return current;
}

export function setBellSchedules(data) {
  if (data && typeof data === "object") current = data;
}

// Загружает время пар с сервера (один раз за сессию; force=true — принудительно).
// Ошибку не выбрасывает: при недоступном API остаёмся на значениях по умолчанию.
export async function ensureBellLoaded(force = false) {
  if (loaded && !force) return current;
  try {
    const data = await api.bellSchedule();
    if (data && typeof data === "object") {
      current = data;
      loaded = true;
    }
  } catch (e) {
    console.error("Не удалось загрузить время пар:", e);
  }
  return current;
}
