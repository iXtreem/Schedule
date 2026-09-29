// Модальное окно «Автозаполнение расписания».
// ----------------------------------------------------------------------------
// Гигантский скрипт автозаполнения расписания на год вперёд разбивается на
// мелкие понятные блоки; этот модуль — оболочка окна с вкладками-требованиями.
//
// Вкладка 1: «Преподаватели» — максимальная недельная нагрузка каждого
// преподавателя (таблица teacher, колонка max_hours; по умолчанию 36),
// рабочие дни Пн–Вс (teacher.working_days), рабочее время «с/до»
// (teacher.work_start / work_end) и разрешённые дисциплины
// (кнопка «Дисциплины», таблица teacher_discipline).
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

// Локальные правки: { [teacherId]: { max_hours?, working_days?, work_start?,
//                                     work_end?, discipline_ids? } }.
// Пока поля нет — используется то, что пришло из БД (или значения по умолчанию).
let scheduleEdits = {};

// Права на редактирование дисциплин (кнопка «Дисциплины»): пока попап открыт,
// чекбоксы пишутся прямо в scheduleEdits[id].discipline_ids.
let discPopupTeacherId = null;

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

// "HH:MM" или "" (без ограничения).
// Важно: MySQL TIME в JSON приходит как "08:30:00" (с секундами), а input
// type="time" step="60" понимает только "HH:MM". Без отсечения секунд поле
// после сохранения сбрасывалось к пустому значению («--:-- по --:--»).
function normTime(value) {
  const v = String(value ?? "").trim();
  const m = v.match(/^(\d{1,2}):(\d{2})(:\d{2})?$/);
  if (!m) return "";
  const h = Number(m[1]);
  const min = Number(m[2]);
  if (!Number.isFinite(h) || !Number.isFinite(min) || h > 23 || min > 59) {
    return "";
  }
  return `${String(h).padStart(2, "0")}:${String(min).padStart(2, "0")}`;
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

// Разрешённые дисциплины преподавателя: список id. По умолчанию список пуст —
// преподаватель НЕ ведёт ни одну дисциплину («Дисциплины: none»); предметы
// включаются чекбоксами в попапе кнопки «Дисциплины». Таблица teacher_discipline.
function getAllowedDisc(teacher) {
  const id = Number(teacher.id);
  if (Array.isArray(scheduleEdits[id]?.discipline_ids))
    return scheduleEdits[id].discipline_ids;
  const raw = teacher.allowed_disciplines ?? teacher.discipline_ids ?? [];
  if (Array.isArray(raw)) {
    return raw.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0);
  }
  if (typeof raw === "string" && raw.trim()) {
    return raw
      .split(/[,;\s]+/)
      .map((v) => Number(v))
      .filter((v) => Number.isFinite(v) && v > 0);
  }
  return [];
}

function isRowEdited(t) {
  const e = scheduleEdits[Number(t.id)];
  if (!e) return false;
  return (
    (e.max_hours !== undefined && e.max_hours !== normHours(t.max_hours ?? DEFAULT_MAX_HOURS)) ||
    (e.working_days !== undefined && e.working_days !== normDays(t.working_days)) ||
    (e.work_start !== undefined && e.work_start !== normTime(t.work_start)) ||
    (e.work_end !== undefined && e.work_end !== normTime(t.work_end)) ||
    (e.discipline_ids !== undefined &&
      e.discipline_ids.join(",") !== getAllowedDisc(t).join(","))
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
        <th style="width: 150px;">Дисциплины</th>
      </tr>
    </thead>`;

  if (!rows.length) {
    tableEl.innerHTML = `${head}<tbody><tr><td colspan="6" class="muted">
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
        <td>
          ${renderDiscCell(t)}
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

// --- Дисциплины преподавателя (таблица teacher_discipline) -------------------
// По умолчанию НИ ОДНА дисциплина не включена: преподаватель «не ведёт»
// ничего. Если в модалке занятия выбрана дисциплина, которой нет у препода-
// вателя, — option подсвечивается красным с подсказкой «не ведёт дисциплину»
// (js/modules/schedule/teacherSchedule.js).

function subjectNameById(id) {
  const s = (state.subjects || []).find((x) => Number(x.id) === Number(id));
  return String(s?.name || s?.short_name || `№${id}`);
}

// Кнопка-счётчик в таблице: «Дисциплины: none» / «Дисциплины: N из M»
function renderDiscCell(t) {
  const allowed = getAllowedDisc(t);
  const total = (state.subjects || []).length;
  const edited = Array.isArray(scheduleEdits[Number(t.id)]?.discipline_ids);
  const label = allowed.length
    ? `Дисциплины: ${allowed.length} из ${total}`
    : "Дисциплины: none";
  const title = allowed.length
    ? `Ведёт: ${allowed.map(subjectNameById).join(", ")}. Нажми, чтобы изменить.`
    : "Ни один предмет не включён — преподаватель «не ведёт» ни одну дисциплину. Нажми и отметьте нужные.";
  return `
    <button type="button"
            class="btn btn-outline autofill-disc-btn ${allowed.length ? "" : "autofill-disc-none"} ${edited ? "is-edited" : ""}"
            data-disc-open="${Number(t.id)}"
            title="${escapeHtml(title)}">${escapeHtml(label)}</button>
  `;
}

function discPopupEl() {
  let el = document.getElementById("autoFillDiscPopup");
  if (!el) {
    el = document.createElement("div");
    el.id = "autoFillDiscPopup";
    el.className = "autofill-disc-popup hidden";
    document.body.appendChild(el);
  }
  return el;
}

function closeDiscPopup() {
  discPopupTeacherId = null;
  const el = discPopupEl();
  el.classList.add("hidden");
  el.innerHTML = "";
}

function openDiscPopup(teacherId, anchorBtn) {
  const teacher = (state.teachers || []).find(
    (t) => Number(t.id) === Number(teacherId),
  );
  if (!teacher) return;
  discPopupTeacherId = Number(teacherId);

  const allowed = new Set(getAllowedDisc(teacher));
  const subjects = [...(state.subjects || [])].sort((a, b) =>
    String(a.name || "").localeCompare(String(b.name || ""), "ru"),
  );

  const items = subjects.length
    ? subjects
        .map(
          (s) => `
        <label class="autofill-disc-item">
          <input type="checkbox" data-disc-id="${Number(s.id)}"
                 data-disc-name="${escapeHtml(String(s.name || s.short_name || "").toLowerCase())}"
                 ${allowed.has(Number(s.id)) ? "checked" : ""} />
          <span>${escapeHtml(s.name || s.short_name || "")}</span>
        </label>`,
        )
        .join("")
    : `<div class="muted">Список дисциплин пуст — добавьте дисциплины в справочнике.</div>`;

  const el = discPopupEl();
  el.innerHTML = `
    <div class="autofill-disc-head">
      <b>${escapeHtml(teacher.name || "")}</b> — какие дисциплины ведёт
      <button type="button" class="autofill-disc-close" title="Закрыть">×</button>
    </div>
    <div class="autofill-disc-hint muted">
      По умолчанию все предметы выключены: преподаватель «не ведёт» ничего.
      Отметьте нужные дисциплины — остальные в расписании подсветятся красным
      с подсказкой «не ведёт дисциплину».
    </div>
    <input type="search" class="autofill-disc-search" placeholder="Поиск дисциплины…" />
    <div class="autofill-disc-list">${items}</div>
    <div class="autofill-disc-actions">
      <button type="button" class="btn btn-outline" data-disc-clear>Снять все</button>
      <button type="button" class="btn btn-outline" data-disc-all>Отметить все</button>
    </div>
  `;
  el.classList.remove("hidden");

  // Поиск по названию дисциплины внутри попапа
  const searchEl = el.querySelector(".autofill-disc-search");
  searchEl?.addEventListener("input", () => {
    const q = String(searchEl.value || "").trim().toLowerCase();
    for (const label of el.querySelectorAll(".autofill-disc-item")) {
      const cb = label.querySelector("input[data-disc-id]");
      const name = String(cb?.dataset.discName || "");
      label.style.display = !q || name.includes(q) ? "" : "none";
    }
  });

  // Позиционируем рядом с кнопкой
  const r = anchorBtn.getBoundingClientRect();
  const w = 340;
  let left = Math.min(r.left, window.innerWidth - w - 12);
  left = Math.max(8, left);
  el.style.left = `${left}px`;
  el.style.top = `${Math.min(r.bottom + 6, window.innerHeight - 240)}px`;
  el.style.width = `${w}px`;
}

function syncDiscEditFromPopup() {
  if (discPopupTeacherId == null) return;
  const el = discPopupEl();
  const ids = [...el.querySelectorAll("input[data-disc-id]")]
    .filter((c) => c.checked)
    .map((c) => Number(c.dataset.discId))
    .filter((v) => Number.isFinite(v) && v > 0);
  const edit = scheduleEdits[discPopupTeacherId] || (scheduleEdits[discPopupTeacherId] = {});
  edit.discipline_ids = ids;
  setStatus("Есть несохранённые изменения.");
  // Обновляем кнопку-счётчик в строке, не перерисовывая всю таблицу
  const total = (state.subjects || []).length;
  const btn = tableEl?.querySelector(`[data-disc-open="${discPopupTeacherId}"]`);
  if (btn) {
    btn.textContent = ids.length ? `Дисциплины: ${ids.length} из ${total}` : "Дисциплины: none";
    btn.classList.toggle("autofill-disc-none", !ids.length);
    btn.classList.add("is-edited");
  }
}

async function refreshTeachersFromDb() {
  // Освежаем список преподавателей из таблицы teacher (на случай, если
  // справочник меняли, пока окно было открыто).
  const teachers = await api.teachers();
  state.teachers = (teachers || []).map((x) => ({ ...x, id: Number(x.id) }));
}

async function save() {
  const items = Object.entries(scheduleEdits)
    .filter(
      ([, e]) =>
        e.max_hours !== undefined ||
        e.working_days !== undefined ||
        e.work_start !== undefined ||
        e.work_end !== undefined
    )
    .map(([id, e]) => ({
      id: Number(id),
      ...(e.max_hours !== undefined ? { max_hours: e.max_hours } : {}),
      ...(e.working_days !== undefined ? { working_days: e.working_days } : {}),
      ...(e.work_start !== undefined ? { work_start: e.work_start } : {}),
      ...(e.work_end !== undefined ? { work_end: e.work_end } : {}),
    }));

  // Дисциплины преподавателя (таблица teacher_discipline): отдельный endpoint.
  // Пустой список = преподаватель не ведёт ни одну дисциплину (по умолчанию).
  const discItems = Object.entries(scheduleEdits)
    .filter(([, e]) => Array.isArray(e.discipline_ids))
    .map(([id, e]) => ({ teacher_id: Number(id), discipline_ids: e.discipline_ids }));

  if (!items.length && !discItems.length) {
    setStatus("Изменений нет — сохранять нечего.");
    return;
  }

  try {
    setStatus("Сохранение…");
    if (items.length) await api.saveTeacherHours(items);
    if (discItems.length) await api.saveTeacherDisciplines(discItems);
    scheduleEdits = {}; // все правки записаны в БД
    closeDiscPopup();
    await refreshTeachersFromDb();
    renderTeacherTable();
    setStatus(
      `Сохранено изменений: ${items.length + discItems.length}.`
    );
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

  // Дисциплины — из справочника дисциплин (state.subjects грузится при старте);
  // если он пуст — подтягиваем в фоне, чтобы попап «Дисциплины» не был пустым.
  if (!state.subjects?.length) {
    api
      .subjects()
      .then((subjects) => {
        state.subjects = (subjects || []).map((x) => ({ ...x, id: Number(x.id) }));
        renderTeacherTable();
      })
      .catch((err) => console.error("Не удалось загрузить дисциплины:", err));
  }

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
  closeBtn?.addEventListener("click", () => {
    closeDiscPopup();
    closeAutoFillModal();
  });
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

  // Массовые операции над дисциплинами (попап «Дисциплины»): открытие,
  // чекбоксы, «Снять все» / «Отметить все», закрытие. Без этих обработчиков
  // кнопка «Дисциплины: none» ничего не делала.
  tableEl?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-disc-open]");
    if (!btn) return;
    const id = Number(btn.dataset.discOpen);
    if (discPopupTeacherId === id && !discPopupEl().classList.contains("hidden")) {
      closeDiscPopup();
      return;
    }
    openDiscPopup(id, btn);
  });

  const discPopup = discPopupEl();

  discPopup.addEventListener("change", (e) => {
    if (e.target.closest("input[data-disc-id]")) syncDiscEditFromPopup();
  });

  discPopup.addEventListener("click", (e) => {
    if (e.target.closest(".autofill-disc-close")) {
      closeDiscPopup();
      return;
    }
    const clear = e.target.closest("[data-disc-clear]");
    const all = e.target.closest("[data-disc-all]");
    if (!clear && !all) return;
    for (const cb of discPopup.querySelectorAll("input[data-disc-id]")) {
      // «Отметить все» включает только видимые (не отфильтрованные поиском)
      if (all && cb.closest(".autofill-disc-item")?.style.display === "none") continue;
      cb.checked = Boolean(all);
    }
    syncDiscEditFromPopup();
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