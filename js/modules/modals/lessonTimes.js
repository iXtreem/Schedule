
// Модуль «Время пар» — вкладка в окне справочников (dictModal).
// Позволяет задать время начала каждой пары и длительность перемены между парами.
// Данные хранятся в том же справочнике bell_schedule, что и прежнее «время пар»,
// но представлены в более удобном виде:
//   slots[i] = { startMin: 540, durationMin: 90 }        — i-я пара (начало + длительность)
//   breaksMin[i] = 10                                    — перемена после i-й пары
// Сохранённый формат колоколок: "HH:MM-HH:MM" на пару, чтобы не менять backend.
//
// Гарантии целостности (реализованы в normalize()):
//   1. Нельзя сделать так, чтобы первая пара была в 10:00, а вторая в 9:00 —
//      начало каждой следующей пары всегда >= окончание предыдущей + перемена.
//   2. Перемена учитывается автоматически: конец пары = начало + длительность,
//      начало следующей = конец предыдущей + перемена.

import { api } from "../../LoadFromBD/api.js";

// ---------- Настройки по умолчанию ----------
const DEFAULT_START_MIN = 9 * 60; // 9:00 — начало первой пары по умолчанию
const DEFAULT_DURATION = 90; // 90 минут — стандартная пара
const DEFAULT_BREAK = 10; // 10 минут — стандартная перемена
const MIN_GAP = 5; // минимальная длительность перемены, мин
const MAX_LESSONS = 12; // максимальное количество пар в дне
const MAX_MIN = 23 * 60 + 59; // верхняя граница времени внутри суток

// Типы дней, для которых настраивается время звонков (как в старом bell_schedule)
const DAY_TYPES = [
  { key: "workday", title: "Рабочие дни" },
  { key: "sunday", title: "Воскресенье" },
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

// Разбор строки старого формата "9:00-9:45" в { startMin, durationMin }
function parseSlotStr(str) {
  const parts = String(str || "").split("-");
  const start = parseHM(parts[0]);
  const end = parseHM(parts[1]);
  if (start === null) return null;
  const duration = end !== null && end > start ? end - start : DEFAULT_DURATION;
  return { startMin: start, durationMin: duration };
}

// ---------- Нормализация: главный страж логической целостности ----------
// Проходит по парам сверху вниз и при необходимости сдвигает начало
// каждой пары на «окончание предыдущей + перемена». Так невозможно
// получить уменьшение времени от пары к паре.
function normalize(state) {
  let prevEnd = null; // окончание предыдущей пары (в минутах)
  state.lessons.forEach((les, i) => {
    const minStart = prevEnd === null ? 0 : prevEnd + (state.breaks[i - 1] ?? DEFAULT_BREAK);
    if (les.startMin < minStart) les.startMin = minStart;
    les.durationMin = clamp(les.durationMin, 15, 240); // пара не может быть абсурдно короткой/длинной
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
  nums.forEach((n, idx) => {
    const parsed = parseSlotStr(slots[n]) || { startMin: DEFAULT_START_MIN, durationMin: DEFAULT_DURATION };
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
  const state = { lessons, breaks };
  normalize(state);
  return state;
}

// Локальное состояние -> payload для saveBellSchedule (строки "HH:MM-HH:MM")
function stateToBell(state) {
  const out = {};
  state.lessons.forEach((les, i) => {
    out[i + 1] = `${fmtHM(les.startMin)}-${fmtHM(les.endMin ?? les.startMin + les.durationMin)}`;
  });
  return out;
}

// ---------- Рендер вкладки ----------

let rootEl = null; // контейнер вкладки (передаётся из dictModal)
let loadedBell = null; // исходный ответ backend (для merge при сохранении)
let states = {}; // { workday: {...}, sunday: {...}, holiday: {...} }
let dirty = false; // были ли изменения с момента последнего сохранения

function renderAll() {
  if (!rootEl) return;
  rootEl.innerHTML = DAY_TYPES.map((dt) => renderDayType(dt)).join("") +
    `<div class="lt-hint muted">Начало каждой следующей пары не может быть раньше конца
     предыдущей с учётом перемены — при недопустимом значении соседние пары сдвигаются автоматически.</div>`;
}

function renderDayType(dt) {
  const st = states[dt.key];
  const rows = st.lessons
    .map((les, i) => {
      const isLast = i === st.lessons.length - 1;
      const brk = st.breaks[i] ?? DEFAULT_BREAK;
      return `
      <tr data-lt-row="${dt.key}:${i}">
        <td class="lt-num">${i + 1} пара</td>
        <td><input class="select dict-input lt-time" type="time" data-lt="start" value="${fmtHM(les.startMin)}" /></td>
        <td><input class="select dict-input lt-num-inp" type="number" min="15" max="240" step="5"
                   data-lt="duration" value="${les.durationMin}" /> мин</td>
        <td class="lt-end">${fmtHM(les.endMin ?? les.startMin + les.durationMin)}</td>
        <td>${isLast ? '<span class="muted">—</span>' : `
          <input class="select dict-input lt-num-inp" type="number" min="${MIN_GAP}" max="120" step="5"
                 data-lt="break" value="${brk}" /> мин перерыв`}</td>
        <td class="dict-actions">
          <button type="button" class="icon-btn" data-lt-remove="${dt.key}:${i}" title="Удалить пару">➖</button>
        </td>
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
        <tr>
          <td colspan="6">
            <button type="button" class="btn lt-add" data-lt-add="${dt.key}"
              ${st.lessons.length >= MAX_LESSONS ? "disabled" : ""}>➕ Добавить пару</button>
          </td>
        </tr>
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
    const startVal = parseHM(tr.querySelector('[data-lt="start"]')?.value);
    const durVal = Number(tr.querySelector('[data-lt="duration"]')?.value);
    if (startVal !== null) les.startMin = clamp(startVal, 0, MAX_MIN);
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
    dirty = false;
    return true;
  } catch (e) {
    alert(`Не удалось сохранить время пар: ${e.message || e}`);
    return false;
  }
}
