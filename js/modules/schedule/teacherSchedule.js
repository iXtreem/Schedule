/* ---------------------------------------------------------------------------
 * Единая проверка графика работы преподавателя (окно
 * «⚡ Условия заполнения расписания» → вкладка «Преподаватели»).
 *
 * Данные приходят из таблицы teacher (см. База данных/schema.sql):
 *   working_days — строка из 7 символов '1'/'0' (Пн..Вс), '1' — может работать;
 *   work_start / work_end — рабочее время («со скольки до скольки»),
 *                          NULL/пусто — ограничение не задано;
 *   allowed_disciplines — список id дисциплин, которые преподаватель МОЖЕТ
 *                          вести (таблица teacher_discipline). По умолчанию
 *                          список пуст → преподаватель НЕ ведёт ни одну
 *                          дисциплину («Дисциплины: none»); нужные предметы
 *                          включаются кнопкой «Дисциплины» во вкладке
 *                          «Преподаватели» окна «⚡ Условия заполнения».
 *   hasDisciplineList(teacher) — есть ли у преподавателя непустой набор
 *                          дисциплин (для фильтрации списков в модалке).
 *
 * Модуль используют:
 *   • renderTeacherTable.js — красит занятую ячейку красным, если занятие
 *     попало в нерабочий день/время или ведёт недозволенную дисциплину
 *     (подсказка title суммирует ВСЕ найденные нарушения);
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
// options.subjectId — id дисциплины занятия (если известен): проверяется
//   список разрешённых дисциплин преподавателя (teacher_discipline).
// Возвращает { ok, problems: [тексты подсказок] } — ВСЕ нарушения сразу
// (подсказки суммируются через "; ").
export function checkTeacherSchedule(teacherId, dayOfWeek, timeSlot, date, options = {}) {
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

  // 3) Разрешена ли преподавателю эта дисциплина
  //    (окно «⚡ Условия заполнения» → вкладка «Преподаватели» → «Дисциплины»,
  //     таблица teacher_discipline). По умолчанию список пуст — преподаватель
  //     НЕ ведёт ни одну дисциплину, поэтому любая выбранная дисциплина
  //     помечается красным, пока нужные предметы не включены.
  const subjectId = Number(options?.subjectId ?? 0);
  if (subjectId > 0) {
    const allowed = getAllowedDisciplines(teacher);
    if (!allowed.includes(subjectId)) {
      const subj = (state.subjects || []).find(
        (s) => Number(s.id) === subjectId,
      );
      const name = String(subj?.name || subj?.short_name || `№${subjectId}`);
      problems.push(`не ведёт дисциплину «${name}»`);
    }
  }

  return { ok: problems.length === 0, problems };
}

// Список id дисциплин, которые может вести преподаватель.
// По умолчанию список пуст — значит преподаватель НЕ ведёт ни одну
// дисциплину (все предметы «выключены», включаются кнопкой «Дисциплины»
// во вкладке «Преподаватели» окна «⚡ Условия заполнения расписания»).
// Поддерживаются оба формата из БД: массив чисел (allowed_disciplines)
// и строка "5,7,12" (discipline_ids) — на случай прямого чтения таблицы.
export function getAllowedDisciplines(teacherOrId) {
  const teacher =
    typeof teacherOrId === "object" && teacherOrId !== null
      ? teacherOrId
      : teacherById(teacherOrId);
  if (!teacher) return [];

  const raw =
    teacher.allowed_disciplines ?? teacher.discipline_ids ?? null;

  if (Array.isArray(raw)) {
    return raw.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0);
  }
  if (typeof raw === "string" && raw.trim() !== "") {
    return raw
      .split(/[,;\s]+/)
      .map((v) => Number(v))
      .filter((v) => Number.isFinite(v) && v > 0);
  }
  return [];
}

// Есть ли у преподавателя включённые дисциплины (непустой набор).
// false — «Дисциплины: none», преподаватель не ведёт ни один предмет;
// используется модалкой занятия, чтобы показывать в списке только тех,
// кто ведёт выбранную дисциплину.
export function hasDisciplineList(teacherOrId) {
  return getAllowedDisciplines(teacherOrId).length > 0;
}

// Можно ли преподавателю вести данную дисциплину.
// По умолчанию у преподавателя НИ ОДНА дисциплина не включена (кнопка
// «Дисциплины» во вкладке «Преподаватели» окна «⚡ Условия заполнения»),
// поэтому пустой список = вести нельзя ничего. Дисциплина с неизвестным
// id (0/пусто) не проверяется — возврат true, чтобы не мешать форме.
export function canTeachDiscipline(teacherOrId, subjectId) {
  const sid = Number(subjectId);
  if (!sid || sid <= 0) return true; // дисциплина неизвестна — не мешаем
  return getAllowedDisciplines(teacherOrId).includes(sid);
}

function fmtHM(minutes) {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

// Текст подсказки для ячейки/option'а (пустая строка, если всё в порядке).
// kind — тип нарушения: "schedule" (график работы) или "discipline"
// (разрешённые дисциплины); влияет только на заголовок подсказки.
export function scheduleProblemText(teacherName, res, kind = "schedule") {
  if (!res || res.ok) return "";
  const label =
    kind === "discipline"
      ? "преподаватель не ведёт эту дисциплину"
      : "нарушение графика работы";
  return `⚠ ${teacherName}: ${label} — ${res.problems.join("; ")}`;
}

// --- Проверки для модалки занятия (ручное редактирование) ------------------
// Все проверки суммируются: вызывающий код собирает массив problems из
// checkTeacherSchedule + checkDisciplineForTeacher и показывает один текст.

// Проверка «может ли преподаватель вести дисциплину» без привязки ко дню.
// По умолчанию ни одна дисциплина не включена → преподаватель не ведёт ничего.
// Возвращает { ok, problems: [тексты] } — как checkTeacherSchedule.
export function checkDisciplineForTeacher(teacherId, subjectId) {
  const teacher = teacherById(teacherId);
  const sid = Number(subjectId);
  if (!teacher || !sid || sid <= 0) return { ok: true, problems: [] };
  const allowed = getAllowedDisciplines(teacher);
  if (allowed.includes(sid)) return { ok: true, problems: [] };
  const subj = (state.subjects || []).find((s) => Number(s.id) === sid);
  const name = String(subj?.name || subj?.short_name || `№${sid}`);
  return { ok: false, problems: [`не ведёт дисциплину «${name}»`] };
}
