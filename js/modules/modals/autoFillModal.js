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

let isBound = false;
let activeTab = "teachers";

// Локальные правки часов: { [teacherId]: number }. Пока значения нет —
// используется то, что пришло из БД (или 36 по умолчанию).
let hoursEdits = {};

const TABS = [
  { key: "teachers", title: "Преподаватели" },
  { key: "soon", title: "Группы (скоро)" },
  { key: "soon2", title: "Кабинеты (скоро)" },
];

function normHours(value) {
  let n = Number(value);
  if (!Number.isFinite(n)) return DEFAULT_MAX_HOURS;
  n = Math.round(n * 10) / 10; // как в DECIMAL(5,1) на сервере
  if (n < 0) n = 0;
  if (n > 999) n = 999;
  return n;
}

function getHours(teacher) {
  const id = Number(teacher.id);
  if (id in hoursEdits) return hoursEdits[id];
  return normHours(teacher.max_hours ?? DEFAULT_MAX_HOURS);
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
      </tr>
    </thead>`;

  if (!rows.length) {
    tableEl.innerHTML = `${head}<tbody><tr><td colspan="2" class="muted">
        Преподаватели не найдены. Добавьте их в справочнике «Преподаватели».</td></tr></tbody>`;
    return;
  }

  const body = rows
    .map((t) => {
      const edited = Number(t.id) in hoursEdits;
      return `
      <tr>
        <td>${escapeHtml(t.name) || "(без фамилии)"}</td>
        <td>
          <input
            class="select autofill-hours-input ${edited ? "is-edited" : ""}"
            type="number" min="0" max="999" step="0.5"
            data-teacher-id="${Number(t.id)}"
            value="${getHours(t)}"
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
  const items = Object.entries(hoursEdits).map(([id, max_hours]) => ({
    id: Number(id),
    max_hours,
  }));

  if (!items.length) {
    setStatus("Изменений нет — сохранять нечего.");
    return;
  }

  try {
    setStatus("Сохранение…");
    await api.saveTeacherHours(items);
    hoursEdits = {}; // все правки записаны в БД
    await refreshTeachersFromDb();
    renderTeacherTable();
    setStatus(`Сохранено изменений: ${items.length}.`);
  } catch (err) {
    console.error("Не удалось сохранить часы преподавателей:", err);
    setStatus(`Ошибка сохранения: ${err?.message || err}`, true);
  }
}

function applyBulkHours() {
  const value = normHours(bulkHoursInput?.value);
  const rows = filteredTeachers();
  for (const t of rows) hoursEdits[Number(t.id)] = value;
  renderTeacherTable();
  setStatus(
    `В поле «${value} ч» установлено ${rows.length} преподав. (видимых). Не забудьте нажать «Сохранить».`
  );
}

function openAutoFillModal() {
  if (!overlay) return;
  hoursEdits = {};
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

  searchInput?.addEventListener("input", renderTeacherTable);

  tabsWrap?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-tab]");
    if (btn) switchTab(btn.dataset.tab);
  });

  // Правки часов прямо в таблице
  tableEl?.addEventListener("change", (e) => {
    const input = e.target.closest("input[data-teacher-id]");
    if (!input) return;
    hoursEdits[Number(input.dataset.teacherId)] = normHours(input.value);
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