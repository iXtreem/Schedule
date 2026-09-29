/* ---------------------------------------------------------------------------
 * Единая проверка графика работы преподавателя (окно
 * «⚡ Условия заполнения расписания» → вкладка «Преподаватели»).
 *
 * Данные приходят из таблицы teacher (см. База данных/schema.sql):
 *   working_days — строка из 7 символов '1'/'0' (Пн..Вс), '1' — может работать;
 *   work_start / work_end — рабочее время («со скольки до скольки»),
 *                          NULL/пусто — ограничение не задано.
 *
 * Модуль используют:
 *   • renderTeacherTable.js — красит занятую ячейку красным, если занятие
 *     попало в нерабочий день/время (с подсказкой title);
 *   • teachersLoadHint.js  — красит option'ы и поле выбора преподавателя
 *     в модалке занятия при ручном редактировании;
 *   • будущий генератор автозаполнения — как жёсткий фильтр слотов.
 * ------------------------------------------------------------------------- */
import { state } from "../../../app.js";
import { DAY_NAMES } from "../../LoadFromBD/bd.js";
import { getBellSchedules } from "../modals/bellStore.js";
import { getDayType } from "./dateUtils.js";

// "8:30" | "08:30" | "08:30:00" -> минуты от полуночи; null если не распознано
export function parseTimeToMinutes(value) {
  const m = String(value ?? "").match(/^(\d{1,2}):(\d{2})/);
  if (!m) return null;
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (!Number.isFinite(h) || !Number.isFinite(min) || h > 23 || min > 59) {
    return null;
  }
  return h * 60 + min;
}

// Интервал пары ("8:00-8:45<br>8:50-9:30") -> [{start,end}, ...] в минутах.
function bellIntervals(bellText) {
  const out = [];
  for (const part of String(bellText ?? "").split(/<br\s*\/?>/i)) {
    const m = part.match(/(\d{1,2}):(\d{2})\s*[-–—]\s*(\d{1,2}):(\d{2})/);
    if (!m) continue;
    out.push({
      start: Number(m[1]) * 60 + Number(m[2]),
      end: Number(m[3]) * 60 + Number(m[4]),
    });
  }
  return out;
}

// Границы занятия по времени: берём из «Времени пар» (bell_schedule) для
// типа дня текущей даты; если данных нет — грубая оценка по номеру пары.
export function getPairTimeBounds(dayType, timeSlot, date) {
  const bells = getBellSchedules();
  const slots = bells?.[dayType] || bells?.workday || {};
  const text = slots[timeSlot] || slots[String(timeSlot)];
  const intervals = bellIntervals(text);
  if (intervals.length) {
    return {
      start: Math.min(...intervals.map((i) => i.start)),
      end: Math.max(...intervals.map((i) => i.end)),
    };
  }
  // Запасной вариант: урок №N ≈ (N-1)*60 .. N*60 минут (неделя обычно
  // начинается около 8:00). Используется только если расписание звонков
  // ещё не загружено.
  const base = 8 * 60;
  return { start: base + (Number(timeSlot) - 1) * 60, end: base + Number(timeSlot) * 60 };
}

function teacherById(id) {
  return (state.teachers || []).find((t) => Number(t.id) === Number(id)) || null;
}

function normDays(workingDays) {
  const s = String(workingDays ?? "1111111").replace(/[^01]/g, "");
  return s.length >= 7 ? s.slice(0, 7) : s.padEnd(7, "1");
}

// Основной API: можно ли преподавателю вести пару в этот день/слот?
// dayOfWeek: 1=Пн … 7=Вс; timeSlot: номер пары (1..N).
// Возвращает { ok, problems: [тексты подсказок] }.
export function checkTeacherSchedule(teacherId, dayOfWeek, timeSlot, date) {
  const teacher = teacherById(teacherId);
  if (!teacher) return { ok: true, problems: [] }; // нет данных — не мешаем

  const problems = [];
  const idx = Number(dayOfWeek) - 1;
  const days = normDays(teacher.working_days);

  // 1) Рабочий ли день недели (Пн..Вс)
  if (idx >= 0 && idx < 7 && days[idx] === "0") {
    problems.push(`нерабочий день (${DAY_NAMES[idx]})`);
  }

  // 2) Рабочее время «со скольки до скольки»
  const ws = parseTimeToMinutes(teacher.work_start);
  const we = parseTimeToMinutes(teacher.work_end);
  if (ws !== null || we !== null) {
    const d =
      date instanceof Date && !Number.isNaN(date.getTime())
        ? date
        : new Date(state.weekStart || Date.now());
    // тип дня (workday/sunday/holiday) — так же, как его считает таблица
    const dayType = getDayType(d);
    const bounds = getPairTimeBounds(dayType, timeSlot, d);
    if (ws !== null && bounds.start < ws) {
      problems.push(`занятие раньше начала рабочего дня (${fmtHM(ws)})`);
    }
    if (we !== null && bounds.end > we) {
      problems.push(`занятие позже конца рабочего дня (${fmtHM(we)})`);
    }
  }

  return { ok: problems.length === 0, problems };
}

function fmtHM(minutes) {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

// Текст подсказки для ячейки/option'а (пустая строка, если всё в порядке)
export function scheduleProblemText(teacherName, res) {
  if (!res || res.ok) return "";
  return `⚠ ${teacherName}: нарушение графика работы — ${res.problems.join("; ")}`;
}
