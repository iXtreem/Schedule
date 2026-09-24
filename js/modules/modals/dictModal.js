
// Модальное окно «Справочники»: добавление/редактирование/удаление
// групп, преподавателей, предметов, аудиторий, типов занятий,
// учебных недель и времени пар — без доступа к базе данных.
import { api } from "../../LoadFromBD/api.js";
import { showDaysOffCalendar, hideDaysOffCalendar } from "./daysOffCalendar.js";
// Модуль новой вкладки «Время пар»: настройка начала пар и длительности перемен
import { showLessonTimes, hideLessonTimes, saveLessonTimes } from "./lessonTimes.js";

const openBtn = document.getElementById("dictBtn");
const overlay = document.getElementById("dictModalOverlay");
const closeBtn = document.getElementById("dictModalClose");
const tabsWrap = document.getElementById("dictTabs");
const formWrap = document.getElementById("dictFormWrap");
const searchInput = document.getElementById("dictSearch");
const editHint = document.getElementById("dictEditHint");
const cancelEditBtn = document.getElementById("dictCancelEditBtn");
const tableEl = document.getElementById("dictTable");
const saveBtn = document.getElementById("dictSaveBtn");

let isBound = false;
let activeTab = "groups";
let editingId = null; // id редактируемой записи (null = создание новой)
let rowsCache = []; // текущий список записей активной вкладки
let bellData = null; // справочник времени пар { workday: {1: "...", ...}, ... }

// ---- Описание вкладок и полей форм (ключи JSON совпадают с backend/lib/crud.php) ----
const TABS = [
  {
    key: "groups",
    title: "Группы",
    columns: [
      { json: "name", label: "Название" },
      { json: "short_name", label: "Кратко" },
      { json: "year", label: "Курс" },
      { json: "size", label: "Размер" },
    ],
    fields: [
      { json: "name", label: "Название группы *", required: true, placeholder: "БП-24-1" },
      { json: "short_name", label: "Краткое название", placeholder: "БП-24-1" },
      { json: "year", label: "Курс", type: "number", min: 1, max: 6 },
      { json: "size", label: "Макс. кол-во зачётных книжек", type: "number", min: 0 },
    ],
  },
  {
    key: "teachers",
    title: "Преподаватели",
    columns: [
      { json: "surname", label: "Фамилия" },
      { json: "first_name", label: "Имя" },
      { json: "last_name", label: "Отчество" },
    ],
    fields: [
      { json: "surname", label: "Фамилия *", required: true, placeholder: "Иванов" },
      { json: "first_name", label: "Имя", placeholder: "Иван" },
      { json: "last_name", label: "Отчество", placeholder: "Иванович" },
    ],
  },
  {
    key: "subjects",
    title: "Предметы",
    columns: [
      { json: "name", label: "Название" },
      { json: "short_name", label: "Кратко" },
    ],
    fields: [
      { json: "name", label: "Название дисциплины *", required: true, placeholder: "Программирование" },
      { json: "short_name", label: "Краткое название", placeholder: "Прог" },
    ],
  },
  {
    key: "rooms",
    title: "Аудитории",
    columns: [
      { json: "building", label: "Корпус" },
      { json: "room_number", label: "Номер" },
      { json: "capacity", label: "Мест" },
    ],
    fields: [
      { json: "building", label: "Корпус *", required: true, placeholder: "Главный" },
      { json: "room_number", label: "Номер аудитории *", required: true, placeholder: "305" },
      { json: "capacity", label: "Вместимость", type: "number", min: 0 },
    ],
  },
  {
    key: "lesson_types",
    title: "Типы занятий",
    columns: [
      { json: "name", label: "Название" },
      { json: "short_name", label: "Кратко" },
    ],
    fields: [
      { json: "name", label: "Название типа *", required: true, placeholder: "Лекция" },
      { json: "short_name", label: "Краткое название", placeholder: "Лек" },
    ],
  },
  {
    key: "weeks",
    title: "Недели",
    columns: [
      { json: "name", label: "Название" },
      { json: "start_date", label: "Начало" },
      { json: "end_date", label: "Конец" },
    ],
    fields: [
      { json: "name", label: "Название недели *", required: true, placeholder: "1 неделя" },
      { json: "start_date", label: "Дата начала *", type: "date", required: true },
      { json: "end_date", label: "Дата окончания *", type: "date", required: true },
    ],
  },
];

// Вкладка «Выходные дни» — календарь, отрисовываемый модулем daysOffCalendar.js
const DAYSOFF_TAB = { key: "daysoff", title: "Выходные дни" };
// Новая вкладка «Время пар» (после «Выходные дни») — рендерится модулем lessonTimes.js
const LESSON_TIMES_TAB = { key: "lesson_times", title: "Время пар" };
// Старая вкладка со строками «9:00-9:45» переименована, чтобы не было двух «Время пар»
const BELL_TAB = { key: "bell", title: "Звонки" };
const BELL_TYPES = [
  { key: "workday", title: "Рабочие дни", maxSlots: 10 },
  { key: "holiday", title: "Праздничные / сокращённые", maxSlots: 10 },
];

function tabByKey(key) {
  return TABS.find((t) => t.key === key) || null;
}

function escapeHtml(value) {
  return String(value ?? "").replace(
    /[&<>"']/g,
    (ch) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[ch])
  );
}

// ---------- Каркас окна ----------

function renderTabs() {
  if (!tabsWrap) return;
  const all = [...TABS, DAYSOFF_TAB, LESSON_TIMES_TAB, BELL_TAB];
  tabsWrap.innerHTML = all
    .map(
      (t) =>
        `<button class="dict-tab ${t.key === activeTab ? "active" : ""}" data-tab="${t.key}" type="button">${t.title}</button>`
    )
    .join("");
}

function renderForm(tab) {
  if (!formWrap) return;
  formWrap.innerHTML = tab.fields
    .map((f) => {
      const type = f.type || "text";
      const attrs = [
        `id="dictField_${f.json}"`,
        `data-field="${f.json}"`,
        `type="${type}"`,
        type === "text" || type === "date" || type === "number" ? 'class="select dict-input"' : "",
        f.required ? "required" : "",
        f.min !== undefined ? `min="${f.min}"` : "",
        f.max !== undefined ? `max="${f.max}"` : "",
        f.placeholder ? `placeholder="${escapeHtml(f.placeholder)}"` : "",
      ]
        .filter(Boolean)
        .join(" ");
      return `<label class="dict-field"><span>${escapeHtml(f.label)}</span><input ${attrs} /></label>`;
    })
    .join("");
}

function clearForm(tab) {
  if (!tab || !formWrap) return;
  formWrap.querySelectorAll("input[data-field]").forEach((inp) => (inp.value = ""));
}

function setEditing(tab, row) {
  editingId = row ? Number(row.id) : null;
  clearForm(tab);
  if (row) {
    tab.fields.forEach((f) => {
      const inp = formWrap.querySelector(`input[data-field="${f.json}"]`);
      if (inp && row[f.json] !== null && row[f.json] !== undefined) inp.value = row[f.json];
    });
    if (editHint) {
      editHint.textContent = `Редактирование: ${rowLabel(tab, row)}`;
      editHint.classList.remove("hidden");
    }
    if (cancelEditBtn) cancelEditBtn.classList.remove("hidden");
    if (saveBtn) saveBtn.textContent = "Сохранить изменения";
  } else {
    if (editHint) editHint.classList.add("hidden");
    if (cancelEditBtn) cancelEditBtn.classList.add("hidden");
    if (saveBtn) saveBtn.textContent = tab.key === "weeks" ? "Добавить неделю" : "Добавить";
  }
}

function rowLabel(tab, row) {
  const parts = tab.columns.map((c) => row[c.json]).filter((v) => v !== null && v !== undefined && v !== "");
  return parts.join(" ") || `#${row.id}`;
}

// ---------- Таблица записей ----------

function renderRows(filterText = "") {
  const tab = tabByKey(activeTab);
  if (!tab || !tableEl) return;

  const q = filterText.trim().toLowerCase();
  const rows = q
    ? rowsCache.filter((r) => rowLabel(tab, r).toLowerCase().includes(q))
    : rowsCache;

  const head = `<thead><tr>${tab.columns
    .map((c) => `<th>${escapeHtml(c.label)}</th>`)
    .join("")}<th>Действия</th></tr></thead>`;

  const body = rows.length
    ? rows
        .map(
          (r) => `<tr data-id="${r.id}" class="${Number(r.id) === editingId ? "dict-row-active" : ""}">
            ${tab.columns
              .map((c) => `<td>${escapeHtml(r[c.json] ?? "")}</td>`)
              .join("")}
            <td class="dict-actions">
              <button type="button" class="icon-btn" data-action="edit" title="Изменить">✏️</button>
              <button type="button" class="icon-btn" data-action="delete" title="Удалить">🗑️</button>
            </td>
          </tr>`
        )
        .join("")
    : `<tr><td colspan="${tab.columns.length + 1}" class="muted">Записей нет</td></tr>`;

  tableEl.innerHTML = `${head}<tbody>${body}</tbody>`;
}

async function loadRows(showError = true) {
  const tab = tabByKey(activeTab);
  if (!tab) return;
  try {
    rowsCache = await api.dictList(tab.key);
  } catch (e) {
    rowsCache = [];
    if (showError) alert(`Не удалось загрузить список «${tab.title}»: ${e.message || e}`);
  }
  renderRows(searchInput?.value || "");
}

// ---------- Вкладка «Время пар» ----------

function renderBellTable() {
  if (!tableEl) return;
  if (!bellData) {
    tableEl.innerHTML = `<thead><tr><th class="muted">Загрузка…</th></tr></thead>`;
    return;
  }
  tableEl.innerHTML = BELL_TYPES.map((bt) => {
    const slots = bellData[bt.key] || {};
    const nums = [...new Set([...Object.keys(slots).map(Number), ...Array.from({ length: bt.maxSlots }, (_, i) => i + 1)])]
      .filter((n) => n >= 1 && n <= bt.maxSlots)
      .sort((a, b) => a - b);
    const rows = nums
      .map(
        (n) => `<tr>
          <td style="width:70px">${n} пара</td>
          <td><input class="select dict-input" data-bell-type="${bt.key}" data-bell-slot="${n}"
               value="${escapeHtml(slots[n] ?? "")}" placeholder="9:00-9:45<br>9:50-10:30" /></td>
          <td class="dict-actions">
            <button type="button" class="icon-btn" data-bell-reset="${bt.key}:${n}" title="Вернуть значение по умолчанию">↩️</button>
          </td>
        </tr>`
      )
      .join("");
    return `<thead><tr><th colspan="3">${bt.title}</th></tr></thead><tbody>${rows}</tbody>`;
  }).join("");
}

async function loadBell() {
  try {
    bellData = await api.bellSchedule();
  } catch (e) {
    bellData = null;
    alert(`Не удалось загрузить расписание звонков: ${e.message || e}`);
  }
  renderBellTable();
}

async function saveBell() {
  const payload = {};
  tableEl.querySelectorAll("input[data-bell-type]").forEach((inp) => {
    const type = inp.dataset.bellType;
    const slot = Number(inp.dataset.bellSlot);
    (payload[type] ||= {})[slot] = inp.value;
  });
  try {
    const res = await api.saveBellSchedule(payload);
    bellData = res?.data || bellData;
    renderBellTable();
    alert("Время пар сохранено.");
  } catch (e) {
    alert(`Не удалось сохранить время пар: ${e.message || e}`);
  }
}

// ---------- Переключение вкладок ----------

function switchTab(key) {
  activeTab = key;
  renderTabs();
  if (key === BELL_TAB.key) {
    hideDaysOffCalendar(); // уходим с календаря, если были на нём
    if (formWrap) formWrap.innerHTML = "";
    if (editHint) editHint.classList.add("hidden");
    if (cancelEditBtn) cancelEditBtn.classList.add("hidden");
    if (searchInput) searchInput.classList.add("hidden");
    if (saveBtn) saveBtn.textContent = "Сохранить время пар";
    tableEl?.classList.add("dict-table-bell");
    loadBell();
    return;
  }
  // Вкладка «Выходные дни»: вместо таблицы — календарь из отдельного модуля
  if (key === DAYSOFF_TAB.key) {
    if (formWrap) formWrap.innerHTML = "";
    if (editHint) editHint.classList.add("hidden");
    if (cancelEditBtn) cancelEditBtn.classList.add("hidden");
    if (searchInput) searchInput.classList.add("hidden");
    if (saveBtn) saveBtn.classList.add("hidden"); // день сохраняется по клику
    tableEl?.classList.remove("dict-table-bell");
    hideLessonTimes(); // уходим с «Времени пар», если были на нём
    showDaysOffCalendar(tableEl);
    return;
  }
  // Вкладка «Время пар»: редактор начала пар и перемен (модуль lessonTimes.js)
  if (key === LESSON_TIMES_TAB.key) {
    hideDaysOffCalendar();
    if (formWrap) formWrap.innerHTML = "";
    if (editHint) editHint.classList.add("hidden");
    if (cancelEditBtn) cancelEditBtn.classList.add("hidden");
    if (searchInput) searchInput.classList.add("hidden");
    if (saveBtn) {
      saveBtn.classList.remove("hidden");
      saveBtn.textContent = "Сохранить время пар";
    }
    tableEl?.classList.remove("dict-table-bell");
    showLessonTimes(tableEl);
    return;
  }
  const tab = tabByKey(key);
  if (!tab) return;
  editingId = null;
  hideDaysOffCalendar();
  hideLessonTimes();
  tableEl?.classList.remove("dict-table-bell");
  if (searchInput) searchInput.classList.remove("hidden");
  if (saveBtn) saveBtn.classList.remove("hidden");
  renderForm(tab);
  setEditing(tab, null);
  loadRows();
}

// ---------- Сохранение / удаление ----------

function collectForm(tab) {
  const body = {};
  formWrap.querySelectorAll("input[data-field]").forEach((inp) => {
    body[inp.dataset.field] = inp.value.trim();
  });
  return body;
}

async function saveCurrent() {
  if (activeTab === BELL_TAB.key) return saveBell();
  // Новая вкладка «Время пар»: сохранение через модуль lessonTimes.js
  if (activeTab === LESSON_TIMES_TAB.key) {
    const ok = await saveLessonTimes();
    if (ok) {
      alert("Время пар сохранено.");
      window.dispatchEvent(new CustomEvent("dict-changed", { detail: { kind: "bell" } }));
    }
    return;
  }
  if (activeTab === DAYSOFF_TAB.key) return; // выходные дни сохраняются по клику в календаре
  const tab = tabByKey(activeTab);
  if (!tab) return;

  const body = collectForm(tab);
  for (const f of tab.fields) {
    if (f.required && !body[f.json]) {
      alert(`Заполните поле «${f.label.replace(" *", "")}»`);
      return;
    }
  }

  try {
    if (editingId) {
      await api.dictUpdate(tab.key, { id: editingId, ...body });
    } else {
      await api.dictCreate(tab.key, body);
    }
    setEditing(tab, null);
    await loadRows();
    refreshStateAfterDictChange(tab.key);
  } catch (e) {
    alert(`Не удалось сохранить: ${e.message || e}`);
  }
}

async function deleteRow(id) {
  const tab = tabByKey(activeTab);
  if (!tab) return;
  const row = rowsCache.find((r) => Number(r.id) === Number(id));
  const label = row ? rowLabel(tab, row) : `#${id}`;
  if (!confirm(`Удалить запись «${label}»? Если она используется в расписании, база не позволит.`)) return;
  try {
    await api.dictDelete(tab.key, id);
    if (Number(editingId) === Number(id)) setEditing(tab, null);
    await loadRows();
    refreshStateAfterDictChange(tab.key);
  } catch (e) {
    alert(`Не удалось удалить: ${e.message || e}`);
  }
}

// После изменений справочников обновляем данные главного экрана,
// чтобы новые группы/предметы/преподаватели сразу появились в списках.
function refreshStateAfterDictChange(key) {
  const map = {
    groups: ["groups", "loadGroups"],
    teachers: ["teachers", "loadTeachers"],
    subjects: ["subjects", "loadSubjects"],
    rooms: ["rooms", "loadRooms"],
    lesson_types: ["lessonTypes", "loadLessonTypes"],
    weeks: ["weeks", "loadWeeks"],
  };
  const entry = map[key];
  if (!entry) return;
  setTimeout(() => {
    window.dispatchEvent(new CustomEvent("dict-changed", { detail: { kind: key } }));
  }, 0);
}

// ---------- Открытие / закрытие ----------

function openDictModal() {
  if (!overlay) return;
  overlay.classList.remove("hidden");
  switchTab(activeTab);
}

function closeDictModal() {
  overlay?.classList.add("hidden");
}

export function initDictModal() {
  if (isBound) return;
  isBound = true;

  openBtn?.addEventListener("click", openDictModal);
  closeBtn?.addEventListener("click", closeDictModal);
  overlay?.addEventListener("mousedown", (e) => {
    if (e.target === overlay) closeDictModal();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && overlay && !overlay.classList.contains("hidden")) closeDictModal();
  });

  tabsWrap?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-tab]");
    if (btn) switchTab(btn.dataset.tab);
  });

  saveBtn?.addEventListener("click", saveCurrent);

  cancelEditBtn?.addEventListener("click", () => {
    const tab = tabByKey(activeTab);
    if (tab) setEditing(tab, null);
  });

  searchInput?.addEventListener("input", () => renderRows(searchInput.value));

  tableEl?.addEventListener("click", (e) => {
    const reset = e.target.closest("[data-bell-reset]");
    if (reset) {
      const [type, slot] = reset.dataset.bellReset.split(":");
      const inp = tableEl.querySelector(`input[data-bell-type="${type}"][data-bell-slot="${slot}"]`);
      if (inp) inp.value = "";
      return;
    }
    const actionBtn = e.target.closest("[data-action]");
    const tr = e.target.closest("tr[data-id]");
    if (!tr) return;
    const id = Number(tr.dataset.id);
    const tab = tabByKey(activeTab);
    if (!tab) return;
    const row = rowsCache.find((r) => Number(r.id) === id);
    if (actionBtn?.dataset.action === "delete") return deleteRow(id);
    if (row) setEditing(tab, row);
  });

  // Enter в полях формы = сохранить
  formWrap?.addEventListener("keydown", (e) => {
    if (e.key === "Enter") {
      e.preventDefault();
      saveCurrent();
    }
  });
}
