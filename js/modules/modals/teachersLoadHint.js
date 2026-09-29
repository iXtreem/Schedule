/* ---------------------------------------------------------------------------
 * Подсветка преподавателя в модалке занятия по недельной нагрузке.
 *
 * Зелёный  — часов за неделю НЕ больше лимита (teacher.max_hours, по умолч. 36)
 * Красный  — лимит превышен.
 * В выпадающем списке <select id="teacherSelect"> каждый option тоже
 * подсвечивается: зелёный — преподаватель может взять текущую пару
 * (с учётом выбранных «Часы»: 1 или 2), красный — уже не может.
 * У курсора показывается маленькая подсказка с цифрами.
 *
 * Данные: GET ?entity=teachers&load=week&week_id=N (backend/modules/teachers).
 * В расчёт берётся текущая пара (часы из select «Часы»), чтобы видеть прогноз:
 * «уже поставлено + эта пара» против лимита.
 * ------------------------------------------------------------------------- */
import { api } from "../../LoadFromBD/api.js";
import { state } from "../../../app.js";
import {
  checkTeacherSchedule,
  scheduleProblemText,
} from "../schedule/teacherSchedule.js";

const OK_CLASS = "teacher-ok";
const OVER_CLASS = "teacher-over";
const OPT_OK_CLASS = "teacher-opt-ok";
const OPT_OVER_CLASS = "teacher-opt-over";
// Отдельный класс/подсказка для нарушения графика работы (дни/часы из окна
// «⚡ Условия заполнения расписания» → вкладка «Преподаватели»)
const SCHED_WARN_CLASS = "teacher-sched-warn";
const OPT_SCHED_WARN_CLASS = "teacher-opt-sched-warn";

let hintEl = null;
let teacherSelectEl = null;
let hoursSelectEl = null;

// id -> { max_hours, week_hours }; обновляется при каждом открытии модалки
let loadByTeacherId = new Map();

function fmt(n) {
  const v = Number(n || 0);
  return String(Math.round(v * 10) / 10).replace(".", ",");
}

function currentExtraHours() {
  const h = Number(hoursSelectEl?.value || 2);
  return Number.isFinite(h) && h > 0 ? h : 0;
}

// Состояние для конкретного преподавателя и конкретной добавляемой нагрузки.
// extraHours — часы текущей пары (1 или 2 из select «Часы»).
function computeStateFor(teacherId, extraHours) {
  const info = loadByTeacherId.get(Number(teacherId));
  if (!info) return null;
  const total = Number(info.week_hours || 0) + extraHours;
  const limit = Number(info.max_hours || 0);
  return { total, limit, over: total > limit + 1e-9 };
}

function computeState(teacherId) {
  return computeStateFor(teacherId, currentExtraHours());
}

// Текущая редактируемая пара (день недели 1..7, слот 1..N) из state.currentEdit.
// openModal записывает { groupId, dayIndex, pairIndex } — конвертируем здесь,
// чтобы проверка графика работала при ручном выборе преподавателя.
function currentPairSlot() {
  const edit = state?.currentEdit;
  if (!edit) return null;
  const dayIndex = Number(edit.dayIndex);
  const pairIndex = Number(edit.pairIndex);
  if (!Number.isFinite(dayIndex) || !Number.isFinite(pairIndex)) return null;

  // Фактический день недели берём по дате (как в таблице): неделя может
  // начинаться не с понедельника.
  let dow = dayIndex + 1;
  if (state.weekStart) {
    const d = new Date(state.weekStart);
    if (!Number.isNaN(d.getTime())) {
      d.setDate(d.getDate() + dayIndex);
      const jsDow = d.getDay(); // 0=вс … 6=сб
      dow = jsDow === 0 ? 7 : jsDow;
    }
  }
  return { dayOfWeek: dow, timeSlot: pairIndex + 1 };
}

// Проверка графика работы для преподавателя и текущей пары модалки.
function scheduleCheckFor(teacherId) {
  const slot = currentPairSlot();
  if (!slot || !teacherId) return null;
  const res = checkTeacherSchedule(teacherId, slot.dayOfWeek, slot.timeSlot);
  if (res.ok) return null;
  const name =
    teacherSelectEl?.options[teacherSelectEl?.selectedIndex]?.textContent || "";
  return scheduleProblemText(name, res);
}

function scheduleCheckForOption(teacherId) {
  const slot = currentPairSlot();
  if (!slot || !teacherId) return null;
  const res = checkTeacherSchedule(teacherId, slot.dayOfWeek, slot.timeSlot);
  if (res.ok) return null;
  const t = (state.teachers || []).find((x) => Number(x.id) === Number(teacherId));
  return scheduleProblemText(String(t?.name || ""), res);
}

// Подсветка всех option в списке преподавателей: зелёный — можно назначить
// текущую пару в пределах лимита, красный — нельзя (превышение часов
// ИЛИ нарушение графика работы — дни/часы из «⚡ Условия заполнения»).
function paintTeacherOptions() {
  if (!teacherSelectEl) return;
  const extra = currentExtraHours();
  for (const opt of teacherSelectEl.options) {
    opt.classList.remove(OPT_OK_CLASS, OPT_OVER_CLASS, OPT_SCHED_WARN_CLASS);
    if (!opt.value) continue; // плейсхолдер «— выбери преподавателя —»
    const schedWarn = scheduleCheckForOption(opt.value);
    if (schedWarn) {
      opt.classList.add(OPT_SCHED_WARN_CLASS);
      opt.title = schedWarn;
      continue;
    }
    const st = computeStateFor(opt.value, extra);
    if (!st) continue; // нет данных о нагрузке — не красим
    opt.classList.add(st.over ? OPT_OVER_CLASS : OPT_OK_CLASS);
    opt.title = st.over
      ? `${opt.textContent}: ${fmt(st.total)} ч > лимита ${fmt(st.limit)} ч`
      : `${opt.textContent}: ${fmt(st.total)} ч из ${fmt(st.limit)} ч`;
  }
}

function applyHighlight() {
  if (!teacherSelectEl) return;
  const st = computeState(teacherSelectEl.value);
  teacherSelectEl.classList.remove(OK_CLASS, OVER_CLASS, SCHED_WARN_CLASS);
  const schedWarn = scheduleCheckFor(teacherSelectEl.value);
  if (schedWarn) {
    // нарушение графика работы — всегда красным, даже если часов хватает
    teacherSelectEl.classList.add(SCHED_WARN_CLASS);
    teacherSelectEl.title = schedWarn;
  } else {
    teacherSelectEl.title = "";
    if (st) teacherSelectEl.classList.add(st.over ? OVER_CLASS : OK_CLASS);
  }
  paintTeacherOptions();
}

function hintText() {
  // Нарушение графика работы показываем в приоритете (оно жёстче часов)
  const schedWarn = scheduleCheckFor(teacherSelectEl?.value);
  if (schedWarn) return schedWarn;

  const st = computeState(teacherSelectEl?.value);
  if (!st) return "";
  const name =
    teacherSelectEl.options[teacherSelectEl.selectedIndex]?.textContent || "";
  const extra = currentExtraHours();
  const forecast =
    extra > 0
      ? ` + ${fmt(extra)} ч (эта пара) = ${fmt(st.total)} ч`
      : ` (${fmt(st.total)} ч)`;
  if (st.over) {
    return `${name}: за неделю ${fmt(st.total - extra)} ч${forecast} — превышение лимита ${fmt(st.limit)} ч на ${fmt(st.total - st.limit)} ч`;
  }
  return `${name}: за неделю ${fmt(st.total - extra)} ч${forecast} — лимит ${fmt(st.limit)} ч, остаток ${fmt(st.limit - st.total)} ч`;
}

function showHintAt(x, y) {
  if (!hintEl) return;
  const text = hintText();
  if (!text) {
    hideHint();
    return;
  }
  hintEl.textContent = text;
  hintEl.classList.remove("hidden");
  hintEl.classList.toggle(
    "teacher-load-hint-over",
    Boolean(scheduleCheckFor(teacherSelectEl?.value)) ||
      Boolean(computeState(teacherSelectEl?.value)?.over),
  );

  // не даём подсказке вылезти за правый/нижний край окна
  const pad = 14;
  const rect = hintEl.getBoundingClientRect();
  let left = x + pad;
  let top = y + pad;
  if (left + rect.width > window.innerWidth - 8)
    left = Math.max(8, x - rect.width - pad);
  if (top + rect.height > window.innerHeight - 8)
    top = Math.max(8, y - rect.height - pad);
  hintEl.style.left = `${left}px`;
  hintEl.style.top = `${top}px`;
}

function hideHint() {
  if (!hintEl) return;
  hintEl.classList.add("hidden");
}

export function initTeacherLoadHint({ teacherSelect, hoursSelect, hint }) {
  teacherSelectEl = teacherSelect || null;
  hoursSelectEl = hoursSelect || null;
  hintEl = hint || document.getElementById("teacherLoadHint");
  if (!teacherSelectEl || !hintEl || teacherSelectEl.dataset.loadHintBound) {
    if (teacherSelectEl) teacherSelectEl.dataset.loadHintBound = "1";
    return;
  }
  teacherSelectEl.dataset.loadHintBound = "1";

  teacherSelectEl.addEventListener("change", applyHighlight);
  teacherSelectEl.addEventListener("mousemove", (e) => {
    if (teacherSelectEl.value) showHintAt(e.clientX, e.clientY);
  });
  teacherSelectEl.addEventListener("mouseleave", hideHint);
  // Смена «Часы» (1 или 2) — пересчитываем прогноз и перекрашиваем option.
  hoursSelectEl?.addEventListener("change", () => {
    applyHighlight();
    if (hintEl && !hintEl.classList.contains("hidden") && teacherSelectEl.value) {
      const r = teacherSelectEl.getBoundingClientRect();
      showHintAt(r.right - 40, r.bottom);
    }
  });

  // Открыли выпадающий список — раскрашиваем option'ы по текущим «Часы».
  teacherSelectEl.addEventListener("mousedown", paintTeacherOptions);
  teacherSelectEl.addEventListener("focus", paintTeacherOptions);
}

// Обновить карту нагрузки (вызывается при открытии модалки занятия).
export async function refreshTeacherLoad(weekId) {
  let rows = [];
  try {
    rows = await api.teacherWeeklyLoad(weekId);
  } catch (e) {
    console.error("Не удалось загрузить нагрузку преподавателей:", e);
    rows = [];
  }
  loadByTeacherId = new Map(
    (rows || []).map((r) => [Number(r.id), r]),
  );
  applyHighlight();
}

// После fillSelect()/смены списка — пересветить выбранное значение и все
// option'ы (список мог быть пересобран поиском/сменой дисциплины).
export function syncTeacherLoadHighlight() {
  applyHighlight();
  hideHint();
}

// Вызывается из fillSelect() сразу после пересборки <option> в списке
// преподавателей: перекрашиваем option'ы по текущей нагрузке и «Часы».
export function paintTeacherOptionsAfterFill(selectEl) {
  if (!teacherSelectEl || !selectEl) return;
  if (selectEl !== teacherSelectEl) return;
  paintTeacherOptions();
}