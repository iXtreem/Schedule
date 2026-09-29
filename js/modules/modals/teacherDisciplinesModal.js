// Модальное окно «Дисциплины преподавателя».
// ----------------------------------------------------------------------------
// Открывается по кнопке «Дисциплины: …» во вкладке «Преподаватели» окна
// «⚡ Условия заполнения расписания» (js/modules/modals/autoFillModal.js).
// Раньше выбор был маленьким попапом, который появлялся в углу экрана без
// стилей — теперь это отдельная полноэкранная модалка поверх основного
// расписания: заголовок, поиск, счётчик, массовые кнопки, список чекбоксов.
//
// Хранилище: таблица teacher_discipline (backend/modules/teachers).
// По умолчанию НИ ОДНА дисциплина не включена: преподаватель «не ведёт»
// ничего, и в окне занятия такие преподаватели подсвечиваются красным с
// подсказкой «не ведёт дисциплину» (js/modules/schedule/teacherSchedule.js).
import { state } from "../../../app.js";
import { api } from "../../LoadFromBD/api.js";

const overlay = document.getElementById("teacherDiscOverlay");
const subtitleEl = document.getElementById("teacherDiscSubtitle");
const listEl = document.getElementById("teacherDiscList");
const searchEl = document.getElementById("teacherDiscSearch");
const countEl = document.getElementById("teacherDiscCount");
const statusEl = document.getElementById("teacherDiscStatus");
const closeBtn = document.getElementById("teacherDiscClose");
const cancelBtn = document.getElementById("teacherDiscCancelBtn");
const saveBtn = document.getElementById("teacherDiscSaveBtn");
const allBtn = document.getElementById("teacherDiscAllBtn");
const noneBtn = document.getElementById("teacherDiscNoneBtn");

let isBound = false;
let currentTeacherId = null; // преподаватель, чьи дисциплины редактируем
let selectedIds = new Set(); // id отмеченных дисциплин (локальные правки)

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

function teacherById(id) {
  return (state.teachers || []).find((t) => Number(t.id) === Number(id));
}

// Дисциплины из БД + несохранённые правки окна «Условия заполнения»
// (scheduleEdits[id].discipline_ids передаются через onPick).
function getInitialIds(onPick) {
  const raw = onPick?.(currentTeacherId);
  if (Array.isArray(raw)) {
    return new Set(raw.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0));
  }
  const t = teacherById(currentTeacherId);
  const arr = Array.isArray(t?.allowed_disciplines) ? t.allowed_disciplines : [];
  return new Set(arr.map((v) => Number(v)).filter((v) => Number.isFinite(v) && v > 0));
}

function visibleSubjects() {
  const q = String(searchEl?.value || "").trim().toLowerCase();
  const list = [...(state.subjects || [])].sort((a, b) =>
    String(a.name || "").localeCompare(String(b.name || ""), "ru"),
  );
  if (!q) return list;
  return list.filter((s) =>
    `${s.name || ""} ${s.short_name || ""}`.toLowerCase().includes(q),
  );
}

function renderList() {
  if (!listEl) return;
  const subjects = visibleSubjects();

  if (!subjects.length) {
    listEl.innerHTML = `<div class="muted teacher-disc-empty">
        Список дисциплин пуст — добавьте предметы в справочнике «Дисциплины».</div>`;
    updateCount();
    return;
  }

  listEl.innerHTML = subjects
    .map((s) => {
      const id = Number(s.id);
      const checked = selectedIds.has(id) ? "checked" : "";
      const name = s.name || s.short_name || `Дисциплина №${id}`;
      const short = s.short_name && s.short_name !== name ? s.short_name : "";
      return `
      <label class="teacher-disc-item ${checked ? "is-on" : ""}" title="${escapeHtml(name)}">
        <input type="checkbox" data-disc-id="${id}" ${checked} />
        <span class="teacher-disc-name">${escapeHtml(name)}</span>
        ${short ? `<span class="teacher-disc-short muted">${escapeHtml(short)}</span>` : ""}
      </label>`;
    })
    .join("");
  updateCount();
}

function updateCount() {
  const total = (state.subjects || []).length;
  if (countEl) {
    countEl.textContent = `Отмечено: ${selectedIds.size} из ${total}`;
  }
  if (subtitleEl) {
    const t = teacherById(currentTeacherId);
    subtitleEl.textContent = t
      ? `${t.name} — отмечаете, какие дисциплины он ведёт (по умолчанию все выключены)`
      : "";
  }
}

function toggleRowClass(input) {
  input.closest(".teacher-disc-item")?.classList.toggle("is-on", input.checked);
}

function applyToAll(on) {
  // «Отметить все» включает только видимые (не отфильтрованные поиском) строки
  for (const input of listEl?.querySelectorAll("input[data-disc-id]") || []) {
    const id = Number(input.dataset.discId);
    if (!Number.isFinite(id) || id <= 0) continue;
    const visible = input.closest(".teacher-disc-item")?.style.display !== "none";
    if (on && !visible) continue;
    input.checked = on;
    if (on) selectedIds.add(id);
    else selectedIds.delete(id);
    toggleRowClass(input);
  }
  updateCount();
  setStatus("Есть несохранённые изменения — нажмите «Сохранить».");
}

async function ensureSubjects() {
  if (state.subjects?.length) return;
  try {
    const subjects = await api.subjects();
    state.subjects = (subjects || []).map((x) => ({ ...x, id: Number(x.id) }));
  } catch (err) {
    console.error("Не удалось загрузить дисциплины:", err);
  }
}

/**
 * Открыть окно выбора дисциплин для преподавателя.
 * @param {number} teacherId
 * @param {(fn?: (id:number)=>void) => void} [callbacks] — { onSave, onCancel, onPick }
 */
export function openTeacherDisciplinesModal(teacherId, callbacks = {}) {
  if (!overlay) return;
  currentTeacherId = Number(teacherId);
  selectedIds = getInitialIds(callbacks.onPick);
  if (searchEl) searchEl.value = "";
  setStatus("");
  renderList();
  overlay.classList.remove("hidden");
  searchEl?.focus();

  // Дисциплины могли ещё не загрузиться — подтягиваем и перерисовываем.
  ensureSubjects().then(() => renderList());

  // Запоминаем обработчики, чтобы «Сохранить» писал результат обратно
  // в окно «Условия заполнения» (и оно обновляло кнопку-счётчик).
  pendingOnSave = callbacks.onSave || null;
  pendingOnCancel = callbacks.onCancel || null;
}

let pendingOnSave = null;
let pendingOnCancel = null;

function close() {
  if (overlay) overlay.classList.add("hidden");
  currentTeacherId = null;
  pendingOnSave = null;
  pendingOnCancel = null;
}

async function save() {
  if (currentTeacherId == null) return;
  const ids = [...selectedIds];
  try {
    setStatus("Сохранение…");
    await api.saveTeacherDisciplines([
      { teacher_id: Number(currentTeacherId), discipline_ids: ids },
    ]);
    // Обновляем локальное состояние, чтобы подсветка в других окнах
    // («не ведёт дисциплину») сразу учитывала новый набор.
    const t = teacherById(currentTeacherId);
    if (t) t.allowed_disciplines = ids;
    setStatus(`Сохранено: ${ids.length ? `ведёт ${ids.length} диск.` : "не ведёт ни одну дисциплину"}.`);
    pendingOnSave?.(Number(currentTeacherId), ids);
    setTimeout(close, 350);
  } catch (err) {
    console.error("Не удалось сохранить дисциплины преподавателя:", err);
    setStatus(`Ошибка сохранения: ${err?.message || err}`, true);
  }
}

function bindOnce() {
  if (isBound) return;
  isBound = true;

  listEl?.addEventListener("change", (e) => {
    const input = e.target.closest("input[data-disc-id]");
    if (!input) return;
    const id = Number(input.dataset.discId);
    if (!Number.isFinite(id) || id <= 0) return;
    if (input.checked) selectedIds.add(id);
    else selectedIds.delete(id);
    toggleRowClass(input);
    updateCount();
    setStatus("Есть несохранённые изменения — нажмите «Сохранить».");
  });

  searchEl?.addEventListener("input", renderList);
  allBtn?.addEventListener("click", () => applyToAll(true));
  noneBtn?.addEventListener("click", () => applyToAll(false));

  closeBtn?.addEventListener("click", () => {
    pendingOnCancel?.();
    close();
  });
  cancelBtn?.addEventListener("click", () => {
    pendingOnCancel?.();
    close();
  });
  saveBtn?.addEventListener("click", save);

  overlay?.addEventListener("mousedown", (e) => {
    if (e.target === overlay) {
      pendingOnCancel?.();
      close();
    }
  });

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && overlay && !overlay.classList.contains("hidden")) {
      pendingOnCancel?.();
      close();
    }
  });
}

export function initTeacherDisciplinesModal() {
  bindOnce();
}
