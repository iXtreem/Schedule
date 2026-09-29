// Модальное окно «Автозаполнение расписания».
// ----------------------------------------------------------------------------
// Гигантский скрипт автозаполнения расписания на год вперёд разбивается на
// мелкие понятные блоки; этот модуль — оболочка окна с вкладками-требованиями.
//
// Вкладка 1: «Преподаватели» — максимальная недельная нагрузка каждого
// преподавателя (таблица teacher, колонка max_hours; по умолчанию 36).
// Список преподавателей берётся из базы данных через api.teachers().
//
// Следующие вкладки (Группы, Кабинеты, Дни и т.д.) будут добавляться сюда
// отдельными небольшими модулями.
import { state } from "../../../app.js";
import { api } from "../../LoadFromBD/api.js";

const DEFAULT_MAX_HOURS = 36; // значение по умолчанию для всех преподавателей

const openBtn = document.getElementById("autoFillBtn");
const overlay = document.getElementById("autoFillOverlay");
const closeBtn = document.getElementById("autoFillClose");
const tabsWrap = document.getElementById("autoFillTabs");
const teachersPane = document.getElementById("autoFillTabTeachers");
const soonPane = document.getElementById("autoFillTabSoon");
const searchInput = document.getElementById("autoFillTeacherSearch");
const tableEl = document.getElementById("autoFillTeacherTable");
const saveBtn = document.getElementById("autoFillSaveBtn");
const statusEl = document.getElementById("autoFillStatus");
const bulkHoursInput = document.getElementById("autoFillBulkHours");
const bulkApplyBtn = document.getElementById("autoFillApplyBulkBtn");
const bulkAllDaysOnBtn = document.getElementById("autoFillAllDaysOnBtn");
const bulkAllDaysOffBtn = document.getElementById("autoFillAllDaysOffBtn");

let isBound = false;
let activeTab = "teachers";

// Локальные правки: { [teacherId]: { max_hours?, working_days?, work_start?, work_end? } }.
// Пока поля нет — используется то, что пришло из БД (или значения по умолчанию).
let scheduleEdits = {};

const TABS = [
  { key: "teachers", title: "Преподаватели" },
  { key: "soon", title: "Группы (скоро)" },
  { key: "soon2", title: "Кабинеты (скоро)" },
];

// Сокращённые названия дней для шапки таблицы (Пн..Вс)
const DAY_SHORT = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

// Полные названия — для подсказок title у чекбоксов
const DAY_FULL = [
  "Понедельник",
  "Вторник",
  "Среда",
  "Четверг",
  "Пятница",
  "Суббота",
  "Воскресенье",
];

function normHours(value) {
  let n = Number(value);
  if (!Number.isFinite(n)) return DEFAULT_MAX_HOURS;
  n = Math.round(n * 10) / 10; // как в DECIMAL(5,1) на сервере
  if (n < 0) n = 0;
  if (n > 999) n = 999;
  return n;
}

// Строка из 7 символов '0'/'1' (Пн..Вс). Пустое/битое значение → все рабочие.
function normDays(value) {
  const s = String(value ?? "").replace(/[^01]/g, "");
  return s.length === 7 ? s : "1111111";
}

// "HH:MM" или "" (без ограничения)
function normTime(value) {
  const v = String(value ?? "").trim();
  return /^\d{1,2}:\d{2}$/.test(v) ? v : "";
}

function getHours(teacher) {
  const id = Number(teacher.id);
  if (scheduleEdits[id]?.max_hours !== undefined) return scheduleEdits[id].max_hours;
  return normHours(teacher.max_hours ?? DEFAULT_MAX_HOURS);
}

function getDays(teacher) {
  const id = Number(teacher.id);
  if (scheduleEdits[id]?.working_days !== undefined)
    return scheduleEdits[id].working_days;
  return normDays(teacher.working_days);
}

function getWorkStart(teacher) {
  const id = Number(teacher.id);
  if (scheduleEdits[id]?.work_start !== undefined)
    return scheduleEdits[id].work_start;
  return normTime(teacher.work_start);
}

function getWorkEnd(teacher) {
  const id = Number(teacher.id);
  if (scheduleEdits[id]?.work_end !== undefined)
    return scheduleEdits[id].work_end;
  return normTime(teacher.work_end);
}

function isRowEdited(t) {
  const e = scheduleEdits[Number(t.id)];
  if (!e) return false;
  return (
    (e.max_hours !== undefined && e.max_hours !== normHours(t.max_hours ?? DEFAULT_MAX_HOURS)) ||
    (e.working_days !== undefined && e.working_days !== normDays(t.working_days)) ||
    (e.work_start !== undefined && e.work_start !== normTime(t.work_start)) ||
    (e.work_end !== undefined && e.work_end !== normTime(t.work_end))
  );
}

function escapeHtml(s) {
  return String(s ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

function setStatus(text, isError = false) {
  if (!statusEl) return;
  statusEl.textContent = text || "";
  statusEl.style.color = isError ? "#c62828" : "";
}

function renderTabs() {
  if (!tabsWrap) return;
  tabsWrap.innerHTML = TABS.map(
    (t) =>
      `<button class="dict-tab ${t.key === activeTab ? "active" : ""}" data-tab="${t.key}" type="button">${t.title}</button>`
  ).join("");
}

function filteredTeachers() {
  const q = String(searchInput?.value || "")
    .trim()
    .toLowerCase();
  const list = [...(state.teachers || [])].sort((a, b) =>
    String(a.name || "").localeCompare(String(b.name || ""), "ru")
  );
  if (!q) return list;
  return list.filter((t) =>
    String(t.name || "")
      .toLowerCase()
      .includes(q)
  );
}

function renderTeacherTable() {
  if (!tableEl) return;
  const rows = filteredTeachers();

  const head = `
    <thead>
      <tr>
        <th>Преподаватель</th>
        <th style="width: 140px;">Часов в неделю</th>
        <th class="autofill-days-head">Рабочие дни (Пн–Вс)</th>
        <th style="width: 110px;">С</th>
        <th style="width: 110px;">До</th>
      </tr>
    </thead>`;

  if (!rows.length) {
    tableEl.innerHTML = `${head}<tbody><tr><td colspan="5" class="muted">
        Преподаватели не найдены. Добавьте их в справочнике «Преподаватели».</td></tr></tbody>`;
    return;
  }

  const body = rows
    .map((t) => {
      const edited = isRowEdited(t);
      const days = getDays(t);
      const dayBoxes = DAY_SHORT.map(
        (label, i) => `
        <label class="autofill-day" title="${DAY_FULL[i]}">
          <input type="checkbox"
                 data-teacher-id="${Number(t.id)}"
                 data-field="working_days"
                 data-day-index="${i}"
                 ${days[i] === "1" ? "checked" : ""} />
          <span>${label}</span>
        </label>`
      ).join("");

      return `
      <tr>
        <td>${escapeHtml(t.name) || "(без фамилии)"}</td>
        <td>
          <input
            class="select autofill-hours-input ${edited ? "is-edited" : ""}"
            type="number" min="0" max="999" step="0.5"
            data-teacher-id="${Number(t.id)}"
            data-field="max_hours"
            value="${getHours(t)}"
          />
        </td>
        <td class="autofill-days-cell">${dayBoxes}</td>
        <td>
          <input
            class="select autofill-time-input ${edited ? "is-edited" : ""}"
            type="time" step="60"
            data-teacher-id="${Number(t.id)}"
            data-field="work_start"
            value="${getWorkStart(t)}"
          />
        </td>
        <td>
          <input
            class="select autofill-time-input ${edited ? "is-edited" : ""}"
            type="time" step="60"
            data-teacher-id="${Number(t.id)}"
            data-field="work_end"
            value="${getWorkEnd(t)}"
          />
        </td>
      </tr>`;
    })
    .join("");

  tableEl.innerHTML = `${head}<tbody>${body}</tbody>`;
}

function switchTab(key) {
  activeTab = key;
  renderTabs();
  const teachersActive = key === "teachers";
  if (teachersPane) teachersPane.classList.toggle("hidden", !teachersActive);
  if (soonPane) soonPane.classList.toggle("hidden", teachersActive);
}

async function refreshTeachersFromDb() {
  // Освежаем список преподавателей из таблицы teacher (на случай, если
  // справочник меняли, пока окно было открыто).
  const teachers = await api.teachers();
  state.teachers = (teachers || []).map((x) => ({ ...x, id: Number(x.id) }));
}

async function save() {
  const items = Object.entries(scheduleEdits).map(([id, e]) => ({
    id: Number(id),
    ...(e.max_hours !== undefined ? { max_hours: e.max_hours } : {}),
    ...(e.working_days !== undefined ? { working_days: e.working_days } : {}),
    ...(e.work_start !== undefined ? { work_start: e.work_start } : {}),
    ...(e.work_end !== undefined ? { work_end: e.work_end } : {}),
  }));

  if (!items.length) {
    setStatus("Изменений нет — сохранять нечего.");
    return;
  }

  try {
    setStatus("Сохранение…");
    await api.saveTeacherHours(items);
    scheduleEdits = {}; // все правки записаны в БД
    await refreshTeachersFromDb();
    renderTeacherTable();
    setStatus(`Сохранено изменений: ${items.length}.`);
  } catch (err) {
    console.error("Не удалось сохранить настройки преподавателей:", err);
    setStatus(`Ошибка сохранения: ${err?.message || err}`, true);
  }
}

function applyBulkHours() {
  const value = normHours(bulkHoursInput?.value);
  const rows = filteredTeachers();
  for (const t of rows) {
    const id = Number(t.id);
    scheduleEdits[id] = { ...(scheduleEdits[id] || {}), max_hours: value };
  }
  renderTeacherTable();
  setStatus(
    `В поле «${value} ч» установлено ${rows.length} преподав. (видимых). Не забудьте нажать «Сохранить».`
  );
}

// Массовая установка рабочих дней для всех видимых строк
function applyBulkDays(on) {
  const days = on ? "1111111" : "0000000";
  const rows = filteredTeachers();
  for (const t of rows) {
    const id = Number(t.id);
    scheduleEdits[id] = { ...(scheduleEdits[id] || {}), working_days: days };
  }
  renderTeacherTable();
  setStatus(
    `${on ? "Все" : "Ни один"} день недели установлен для ${rows.length} преподав. (видимых). Не забудьте нажать «Сохранить».`
  );
}

function openAutoFillModal() {
  if (!overlay) return;
  scheduleEdits = {};
  setStatus("");
  if (searchInput) searchInput.value = "";
  switchTab("teachers");
  renderTeacherTable();
  overlay.classList.remove("hidden");

  // Данные подтягиваем из БД в фоне, чтобы показать актуальный список.
  refreshTeachersFromDb()
    .then(() => renderTeacherTable())
    .catch((err) => {
      console.error("Не удалось загрузить преподавателей:", err);
      setStatus("Не удалось загрузить преподавателей из базы данных.", true);
    });
}

function closeAutoFillModal() {
  if (overlay) overlay.classList.add("hidden");
}

function bindOnce() {
  if (isBound) return;
  isBound = true;

  openBtn?.addEventListener("click", openAutoFillModal);
  closeBtn?.addEventListener("click", closeAutoFillModal);
  saveBtn?.addEventListener("click", save);
  bulkApplyBtn?.addEventListener("click", applyBulkHours);
  bulkAllDaysOnBtn?.addEventListener("click", () => applyBulkDays(true));
  bulkAllDaysOffBtn?.addEventListener("click", () => applyBulkDays(false));

  searchInput?.addEventListener("input", renderTeacherTable);

  tabsWrap?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-tab]");
    if (btn) switchTab(btn.dataset.tab);
  });

  // Правки прямо в таблице: часы, рабочие дни (чекбоксы Пн..Вс), время С/До
  tableEl?.addEventListener("change", (e) => {
    const input = e.target.closest("input[data-teacher-id]");
    if (!input) return;
    const id = Number(input.dataset.teacherId);
    const field = input.dataset.field;
    const edit = scheduleEdits[id] || (scheduleEdits[id] = {});

    if (field === "working_days") {
      // чекбокс одного дня: обновляем соответствующий символ строки '0'/'1'
      const teacher = (state.teachers || []).find((t) => Number(t.id) === id);
      const days = Array.from(
        edit.working_days !== undefined
          ? edit.working_days
          : normDays(teacher?.working_days)
      );
      days[Number(input.dataset.dayIndex)] = input.checked ? "1" : "0";
      edit.working_days = days.join("");
    } else if (field === "work_start" || field === "work_end") {
      edit[field] = normTime(input.value);
    } else {
      edit.max_hours = normHours(input.value);
    }

    // подсветка изменённой строки
    input.classList.add("is-edited");
    setStatus("Есть несохранённые изменения.");
  });

  overlay?.addEventListener("mousedown", (e) => {
    if (e.target === overlay) closeAutoFillModal();
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && overlay && !overlay.classList.contains("hidden")) {
      closeAutoFillModal();
    }
  });
}

export function initAutoFillModal() {
  bindOnce();
}