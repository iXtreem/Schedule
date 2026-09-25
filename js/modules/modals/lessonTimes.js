

// Модуль «Время пар» — вкладка в окне справочников (dictModal).
// Позволяет задать время начала каждой пары и длительность перемены между парами.
// Данные хранятся в том же справочнике bell_schedule, что и прежнее «время пар»,
// но представлены в более удобном виде:
//   slots[i] = { startMin: 540, durationMin: 90 }        — i-я пара (начало + длительность)
//   breaksMin[i] = 10                                    — перемена ПОСЛЕ i-й пары (между парами)
//   innerBreakMin = 5                                    — перерыв ВНУТРИ одной пары:
//     пара 8:00–9:30 с innerBreakMin=5 отображается как «8:00-8:45<br>8:50-9:30»
// Сохранённый формат колоколок: "HH:MM-HH:MM<br>HH:MM-HH:MM" на пару
// (одна строка на пару — backend менять не нужно).
//
// Гарантии целостности (реализованы в normalize()):
//   1. Нельзя сделать так, чтобы первая пара была в 10:00, а вторая в 9:00 —
//      начало каждой следующей пары всегда >= окончание предыдущей + перемена.
//   2. Перемена учитывается автоматически: конец пары = начало + длительность,
//      начало следующей = конец предыдущей + перемена.

import { api } from "../../LoadFromBD/api.js";
import { setBellSchedules } from "./bellStore.js";
import { normalizeBellText } from "../schedule/bellUtils.js";

// ---------- Настройки по умолчанию ----------
const DEFAULT_START_MIN = 9 * 60; // 9:00 — начало первой пары по умолчанию
const DEFAULT_DURATION = 90; // 90 минут — стандартная пара
const DEFAULT_BREAK = 10; // 10 минут — стандартная перемена
const DEFAULT_INNER_BREAK = 5; // 5 минут — стандартный перерыв внутри пары
const MIN_GAP = 5; // минимальная длительность перемены, мин
const MIN_INNER_GAP = 0; // внутренний перерыв может быть и 0 (без перерыва)
const MAX_LESSONS = 12; // максимальное количество пар в дне
const MAX_MIN = 23 * 60 + 59; // верхняя граница времени внутри суток

// Типы дней, для которых настраивается время звонков (как в старом bell_schedule)
const DAY_TYPES = [
  { key: "workday", title: "Рабочие дни" },
  { key: "holiday", title: "Праздничные / сокращённые" },
];

// ---------- Утилиты времени ----------
const clamp = (v, min, max) => Math.min(Math.max(v, min), max);

// "9:00" -> 540, "" -> null
function parseHM(str) {
  const m = /^(\d{1,2}):(\d{2})/.exec(String(str || "").trim());
  if (!m) return null;
  const h = Number(m[1]);
  const mm = Number(m[2]);
  if (h > 23 || mm > 59) return null;
  return h * 60 + mm;
}

// 540 -> "09:00"
function fmtHM(min) {
  const h = Math.floor(min / 60) % 24;
  const m = min % 60;
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}`;
}

// Разбор строки слота в { startMin, durationMin }.
// Поддерживаются форматы:
//   "9:00-9:45"                       — старый формат без перемены внутри пары;
//   "8:00-8:45<br>8:50-9:30"          — пара с внутренним перерывом:
//       начало = 8:00, длительность = вся пара до конца (9:30), т.е. 90 мин,
//       а внутренний перерыв (5 мин) восстанавливается из разрыва между блоками.
function parseSlotStr(str) {
  const parts = String(str || "")
    .split(/<br\s*\/?>|\n/i)
    .map((s) => s.trim())
    .filter(Boolean);
  const ranges = [];
  for (const p of parts) {
    const r = parseSlotRange(p);
    if (r) ranges.push(r);
  }
  if (!ranges.length) return null;
  const first = ranges[0];
  const last = ranges[ranges.length - 1];
  const end = Math.max(first.end, last.end);
  const duration = end > first.start ? end - first.start : DEFAULT_DURATION;
  // Внутренний перерыв = разрыв между концом 1-го блока и началом 2-го
  let innerBreak = null;
  if (ranges.length >= 2) {
    innerBreak = Math.max(0, ranges[1].start - first.end);
  }
  return {
    startMin: first.start,
    durationMin: duration,
    innerBreakMin: innerBreak,
    firstBlockMin: ranges.length >= 2 ? first.end - first.start : duration,
  };
}

// Один диапазон "H:MM-H:MM" -> { start, end } либо null
function parseSlotRange(str) {
  const m = /^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})/.exec(String(str || "").trim());
  if (!m) return null;
  const start = parseHM(m[1] + ":" + m[2]);
  const end = parseHM(m[3] + ":" + m[4]);
  if (start === null) return null;
  return { start, end: end !== null && end > start ? end : start + DEFAULT_DURATION };
}

// ---------- Нормализация: главный страж логической целостности ----------
// Проходит по парам сверху вниз и ПРИНУДИТЕЛЬНО выстраивает цепочку:
//   начало каждой следующей пары = конец предыдущей + перемена.
// Поэтому невозможно ввести, например, 1-ю пару 8:00–8:45 с переменой 15,
// а 2-ю пару 19:00 — вторая начнётся ровно в 9:00.
// Начало пары можно изменить только у ПЕРВОЙ пары (и то через поле «Начало»):
// поля «Начало» у остальных пар заблокированы и пересчитываются автоматически.
function normalize(state) {
  let prevEnd = null; // окончание предыдущей пары (в минутах)
  state.lessons.forEach((les, i) => {
    les.durationMin = clamp(les.durationMin, 15, 240); // пара не может быть абсурдно короткой/длинной
    if (i === 0) {
      // первая пара: начало задаётся вручную, но не позже конца суток
      les.startMin = clamp(les.startMin, 0, MAX_MIN - les.durationMin);
    } else {
      // все последующие пары — строго после предыдущей с учётом перемены
      const minStart = prevEnd + (state.breaks[i - 1] ?? DEFAULT_BREAK);
      les.startMin = Math.min(minStart, MAX_MIN);
    }
    prevEnd = Math.min(les.startMin + les.durationMin, MAX_MIN);
    les.endMin = prevEnd;
  });
  // Обрезаем лишние пустые пары в конце (последняя пара должна быть заполнена временем)
  while (state.lessons.length > 1 && !state.lessons[state.lessons.length - 1].active) {
    state.lessons.pop();
    state.breaks.pop();
  }
}

// ---------- Загрузка / сохранение через api.bellSchedule ----------

// Из ответа backend ({ workday: {1:"9:00-9:45",...} }) собираем локальное состояние
function bellToState(bellData, typeKey) {
  const slots = bellData?.[typeKey] || {};
  const nums = Object.keys(slots)
    .map(Number)
    .filter((n) => n >= 1 && n <= MAX_LESSONS)
    .sort((a, b) => a - b);
  const lessons = [];
  const breaks = [];
  let innerBreak = null; // значение из первой встреченной пары с <br>
  let firstBlockMin = null; // длительность 1-го блока первой пары (для точного round-trip)
  nums.forEach((n, idx) => {
    const parsed = parseSlotStr(slots[n]) || { startMin: DEFAULT_START_MIN, durationMin: DEFAULT_DURATION };
    if (innerBreak === null && parsed.innerBreakMin !== null && parsed.innerBreakMin !== undefined) {
      innerBreak = clamp(parsed.innerBreakMin, MIN_INNER_GAP, 60);
      firstBlockMin = parsed.firstBlockMin;
    }
    lessons.push({ startMin: parsed.startMin, durationMin: parsed.durationMin, active: true });
    // Длительность перемены выводим из разрыва между парами
    const next = nums[idx + 1] !== undefined ? parseSlotStr(slots[nums[idx + 1]]) : null;
    const gap = next ? clamp(next.startMin - parsed.startMin - parsed.durationMin, MIN_GAP, 120) : DEFAULT_BREAK;
    breaks.push(gap);
  });
  if (!lessons.length) {
    lessons.push({ startMin: DEFAULT_START_MIN, durationMin: DEFAULT_DURATION, active: true });
    breaks.push(DEFAULT_BREAK);
  }
  const state = { lessons, breaks, innerBreakMin: innerBreak ?? DEFAULT_INNER_BREAK };
  // Если в базе первый блок пары был не строго половиной (например,
  // «8:00-8:45<br>8:50-9:30» при паре 90 мин), запоминаем это смещение,
  // чтобы при сохранении не «съехали» минуты.
  if (innerBreak !== null && Number.isFinite(firstBlockMin)) {
    const total = lessons[0].durationMin;
    const natural = Math.floor((total - innerBreak) / 2);
    const offset = firstBlockMin - natural;
    if (offset > 0 && natural + offset >= 15 && natural + offset + innerBreak < total) {
      state.innerOffsetMin = offset;
    }
  }
  normalize(state);
  return state;
}

// Локальное состояние -> payload для saveBellSchedule.
// Каждая пара — одна строка; при innerBreakMin > 0 она разбивается на два
// блока через <br>: «8:00-8:45<br>8:50-9:30» (внутренний перерыв заданного
// размера). При innerBreakMin = 0 сохраняется старый вид «8:00-9:30».
function stateToBell(state) {
  const out = {};
  state.lessons.forEach((les, i) => {
    out[i + 1] = lessonSlotText(state, les);
  });
  return out;
}

// Текст одного слота с учётом внутреннего перерыва (общий для сохранения
// и предпросмотра в таблице настроек).
// Точка деления: половина пары + смещение innerOffsetMin (если оно было
// задано в сохранённых данных), но так, чтобы второй блок не был меньше 5 мин.
function lessonSlotText(state, les) {
  const inner = clamp(state.innerBreakMin ?? 0, MIN_INNER_GAP, 60);
  const end = les.endMin ?? les.startMin + les.durationMin;
  if (inner > 0 && end - les.startMin > inner + 10) {
    let half = Math.floor((end - les.startMin - inner) / 2) + (state.innerOffsetMin ?? 0);
    half = clamp(half, 15, end - les.startMin - inner - 5);
    const midEnd = les.startMin + half;
    return `${fmtHM(les.startMin)}-${fmtHM(midEnd)}<br>${fmtHM(midEnd + inner)}-${fmtHM(end)}`;
  }
  return `${fmtHM(les.startMin)}-${fmtHM(end)}`;
}

// Предпросмотр вида ячейки «Время» в основном расписании (с той же логикой
// нормализации, что применяет dateUtils.getTimeSlots при отрисовке таблицы).
function previewCell(st, les) {
  const text = normalizeBellText(lessonSlotText(st, les));
  if (text === `${fmtHM(les.startMin)}-${fmtHM(les.endMin ?? les.startMin + les.durationMin)}`) return "";
  return `<div class="muted lt-preview">${text}</div>`;
}

// ---------- Рендер вкладки ----------

let rootEl = null; // контейнер вкладки (передаётся из dictModal)
let loadedBell = null; // исходный ответ backend (для merge при сохранении)
let states = {}; // { workday: {...}, sunday: {...}, holiday: {...} }
let dirty = false; // были ли изменения с момента последнего сохранения

function renderAll() {
  if (!rootEl) return;
  rootEl.innerHTML = `<div class="lt-hint muted">Начало задаётся только у первой пары. Все остальные пары
     выстраиваются автоматически: начало следующей = конец предыдущей + перемена.</div>` +
    DAY_TYPES.map((dt) => renderDayType(dt)).join("");
}

// Одно общее поле «Перерыв внутри пары» для типа дня: значение применяется
// ко всем парам этого типа (пара 8:00–9:30 → «8:00-8:45<br>8:50-9:30»).
function renderInnerBreakControl(dt, st) {
  const v = clamp(st.innerBreakMin ?? DEFAULT_INNER_BREAK, MIN_INNER_GAP, 60);
  return `
    <div class="lt-inner-break" style="display:flex;align-items:center;gap:6px;margin:6px 0;">
      <label>Перерыв внутри каждой пары:</label>
      <input class="select dict-input lt-num-inp" type="number" min="${MIN_INNER_GAP}" max="60" step="1"
             data-lt="innerbreak" data-lt-type="${dt.key}" value="${v}" /> мин
      <span class="muted">(например: пара 8:00–9:30 покажется как «8:00-8:45&nbsp;&nbsp;8:50-9:30»; 0 — без перерыва)</span>
    </div>`;
}

function renderDayType(dt) {
  const st = states[dt.key];
  const rows = st.lessons
    .map((les, i) => {
      const isLast = i === st.lessons.length - 1;
      const isFirst = i === 0;
      const brk = st.breaks[i] ?? DEFAULT_BREAK;
      return `
      <tr data-lt-row="${dt.key}:${i}">
        <td class="lt-num">${i + 1} пара</td>
        <td><input class="select dict-input lt-time" type="time" data-lt="start" value="${fmtHM(les.startMin)}"
             ${isFirst ? "" : 'disabled title="Начало следующей пары считается автоматически: конец предыдущей + перемена"'} /></td>
        <td><input class="select dict-input lt-num-inp" type="number" min="15" max="240" step="5"
                   data-lt="duration" value="${les.durationMin}" /> мин</td>
        <td class="lt-end">${fmtHM(les.endMin ?? les.startMin + les.durationMin)}${previewCell(st, les)}</td>
        <td>${isLast ? '<span class="muted">—</span>' : `
          <input class="select dict-input lt-num-inp" type="number" min="${MIN_GAP}" max="120" step="5"
                 data-lt="break" value="${brk}" /> мин перерыв`}</td>
      </tr>`;
    })
    .join("");

  return `
    <table class="dict-table lt-table">
      <thead>
        <tr>
          <th colspan="6">${dt.title}</th>
        </tr>
        <tr class="lt-head-sub">
          <th>№</th><th>Начало</th><th>Длительность</th><th>Конец</th><th>Перемена после</th><th></th>
        </tr>
      </thead>
      <tbody>${rows}</tbody>
      <tfoot>
        <tr><td colspan="6">${renderInnerBreakControl(dt, st)}</td></tr>
      </tfoot>
    </table>`;
}

// ---------- Обработчики изменений ----------

function markDirty() {
  dirty = true;
}

// Чтение значений из DOM в состояние (перед нормализацией)
function syncStateFromDom(typeKey) {
  const st = states[typeKey];
  const table = rootEl.querySelector(`table.lt-table thead th[colspan="6"]`)
    ? [...rootEl.querySelectorAll("tbody tr")].filter((tr) => tr.dataset.ltRow?.startsWith(typeKey + ":"))
    : [];
  table.forEach((tr) => {
    const i = Number(tr.dataset.ltRow.split(":")[1]);
    const les = st.lessons[i];
    if (!les) return;
    // Начало читаем только у первой пары (у остальных поле disabled и считается автоматически)
    if (i === 0) {
      const startVal = parseHM(tr.querySelector('[data-lt="start"]')?.value);
      if (startVal !== null) les.startMin = clamp(startVal, 0, MAX_MIN);
    }
    const durVal = Number(tr.querySelector('[data-lt="duration"]')?.value);
    if (Number.isFinite(durVal)) les.durationMin = clamp(durVal, 15, 240);
    const brkVal = Number(tr.querySelector('[data-lt="break"]')?.value);
    if (Number.isFinite(brkVal) && st.breaks[i] !== undefined) st.breaks[i] = clamp(brkVal, MIN_GAP, 120);
  });
}

function onInput(e) {
  const tr = e.target.closest("tr[data-lt-row]");
  if (!tr) return;
  const [typeKey, idxStr] = tr.dataset.ltRow.split(":");
  const idx = Number(idxStr);
  const st = states[typeKey];

  if (e.target.matches('[data-lt="start"]')) {
    // Начало можно менять только у первой пары — остальные считаются автоматически
    if (idx !== 0) return;
    const v = parseHM(e.target.value);
    if (v === null) return;
    st.lessons[idx].startMin = clamp(v, 0, MAX_MIN);
  } else if (e.target.matches('[data-lt="duration"]')) {
    const v = Number(e.target.value);
    if (!Number.isFinite(v)) return;
    st.lessons[idx].durationMin = clamp(v, 15, 240);
  } else if (e.target.matches('[data-lt="break"]')) {
    const v = Number(e.target.value);
    if (!Number.isFinite(v)) return;
    st.breaks[idx] = clamp(v, MIN_GAP, 120);
  } else if (e.target.matches('[data-lt="innerbreak"]')) {
    // Общее поле «перерыв внутри пары» — у input нет строки таблицы,
    // тип дня берём из data-lt-type
    const key = e.target.dataset.ltType;
    const s = states[key];
    if (!s) return;
    const v = Number(e.target.value);
    if (!Number.isFinite(v)) return;
    s.innerBreakMin = clamp(v, MIN_INNER_GAP, 60);
    markDirty();
    renderAll(); // пересобираем разметку (в т.ч. это же поле с новым значением)
    return;
  } else {
    return;
  }
  markDirty();
  // Пересчёт цепочки: нормализуем и перерисовываем только этот тип дня
  normalize(st);
  renderAll();
}

function onClick(e) {
  const addBtn = e.target.closest("[data-lt-add]");
  const removeBtn = e.target.closest("[data-lt-remove]");
  if (addBtn) {
    const key = addBtn.dataset.ltAdd;
    const st = states[key];
    if (st.lessons.length >= MAX_LESSONS) return;
    const last = st.lessons[st.lessons.length - 1];
    const lastIdx = st.lessons.length - 1;
    const start = (last.endMin ?? last.startMin + last.durationMin) + (st.breaks[lastIdx] ?? DEFAULT_BREAK);
    st.lessons.push({ startMin: clamp(start, 0, MAX_MIN), durationMin: DEFAULT_DURATION, active: true });
    st.breaks.push(DEFAULT_BREAK);
    markDirty();
    normalize(st);
    renderAll();
    return;
  }
  if (removeBtn) {
    const [key, idxStr] = removeBtn.dataset.ltRemove.split(":");
    const st = states[key];
    const idx = Number(idxStr);
    if (st.lessons.length <= 1) return; // хотя бы одна пара должна остаться
    st.lessons.splice(idx, 1);
    st.breaks.splice(Math.min(idx, st.breaks.length - 1), 1);
    markDirty();
    normalize(st);
    renderAll();
  }
}

// ---------- Публичный API для dictModal ----------

export async function showLessonTimes(container) {
  rootEl = container;
  if (!rootEl) return;
  rootEl.innerHTML = `<div class="muted">Загрузка расписания звонков…</div>`;
  try {
    loadedBell = await api.bellSchedule();
  } catch (e) {
    loadedBell = null;
    alert(`Не удалось загрузить время пар: ${e.message || e}`);
  }
  states = {};
  DAY_TYPES.forEach((dt) => (states[dt.key] = bellToState(loadedBell, dt.key)));
  dirty = false;
  renderAll();

  rootEl.addEventListener("input", onInput);
  rootEl.addEventListener("click", onClick);
}

export function hideLessonTimes() {
  if (rootEl) {
    rootEl.removeEventListener("input", onInput);
    rootEl.removeEventListener("click", onClick);
    rootEl.innerHTML = "";
  }
  rootEl = null;
}

// Сохранение: собираем все типы дней в формат bell_schedule и отправляем на backend.
// Возвращает true при успехе.
export async function saveLessonTimes() {
  if (!rootEl) return false;
  const payload = {};
  DAY_TYPES.forEach((dt) => {
    // Сначала подтягиваем актуальные значения из полей ввода
    syncStateFromDom(dt.key);
    normalize(states[dt.key]);
    payload[dt.key] = stateToBell(states[dt.key]);
  });
  // Сохраняем неизменённые типы дней (например, если они были в базе, но скрыты)
  if (loadedBell) {
    Object.keys(loadedBell).forEach((k) => {
      if (!payload[k]) payload[k] = loadedBell[k];
    });
  }
  try {
    const res = await api.saveBellSchedule(payload);
    loadedBell = res?.data || payload;
    // Обновляем общее хранилище, чтобы основная таблица расписания
    // сразу показала новые времена пар.
    setBellSchedules(loadedBell);
    dirty = false;
    return true;
  } catch (e) {
    alert(`Не удалось сохранить время пар: ${e.message || e}`);
    return false;
  }
}
