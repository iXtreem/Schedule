// Модальное окно «📚 Учебный план» (отдельная база uchetlfpstu_plan).
// ----------------------------------------------------------------------------
// Здесь вручную задаются ВСЕ параметры учебного плана:
//   * кафедры;
//   * дисциплины плана (+ закрепление за кафедрой);
//   * виды учебной деятельности (лекции, лабораторные, практические,
//     КСР/КРВ, ...) — колонки часов в карточке;
//   * карточки плана: дисциплина + курс + семестр (1..8 для 1–4 курса),
//     распределение общей трудоемкости по видам деятельности
//     ДЛЯ КАЖДОЙ ГРУППЫ (или «Все группы» одной строкой);
//   * виды контроля по семестрам (Экзамен, Диф. зачет, Зачет, Курсовой
//     проект, Курсовая работа — у одного пункта можно отметить несколько
//     семестров).
//
// Часы из этого окна — ограничение при ручном редактировании расписания
// (модалка занятия показывает счётчик «выполнено/план» и сервер не даёт
// поставить сверх плана) и источник требований для автозаполнения.
//
// Backend: backend/modules/curriculum/* (entity=curr_ui_meta, curr_cards,
// curr_card, curr_discipline, curriculum_department, curriculum_activity,
// curriculum_control_type).
import { api } from "../../LoadFromBD/api.js";

const openBtn = document.getElementById("currBtn");
const overlay = document.getElementById("currOverlay");
const closeBtn = document.getElementById("currClose");
const tabsWrap = document.getElementById("currTabs");
const statusEl = document.getElementById("currStatus");

const panes = {
  cards: document.getElementById("currPaneCards"),
  departments: document.getElementById("currPaneDepartments"),
  kinds: document.getElementById("currPaneKinds"),
  disciplines: document.getElementById("currPaneDisciplines"),
};

// Фильтры списка карточек
const filterCourse = document.getElementById("currFilterCourse");
const filterTerm = document.getElementById("currFilterTerm");
const filterDept = document.getElementById("currFilterDept");
const searchInput = document.getElementById("currSearch");
const addCardBtn = document.getElementById("currAddCardBtn");
const cardsTable = document.getElementById("currCardsTable");

// Кафедры
const depNameInput = document.getElementById("currDepName");
const depShortInput = document.getElementById("currDepShort");
const depAddBtn = document.getElementById("currDepAddBtn");
const depTable = document.getElementById("currDepTable");

// Виды деятельности / виды контроля
const akNameInput = document.getElementById("currAkName");
const akShortInput = document.getElementById("currAkShort");
const akAddBtn = document.getElementById("currAkAddBtn");
const akTable = document.getElementById("currAkTable");
const ctNameInput = document.getElementById("currCtName");
const ctAddBtn = document.getElementById("currCtAddBtn");
const ctTable = document.getElementById("currCtTable");

// Дисциплины плана
const newDiscNameInput = document.getElementById("currNewDiscName");
const newDiscDeptSelect = document.getElementById("currNewDiscDept");
const newDiscAddBtn = document.getElementById("currNewDiscAddBtn");
const discTable = document.getElementById("currDiscTable");

// Форма карточки
const cardForm = document.getElementById("currCardForm");
const cardFormTitle = document.getElementById("currCardFormTitle");
const cardCancelBtn = document.getElementById("currCardCancelBtn");
const saveCardBtn = document.getElementById("currSaveCardBtn");
const cardDisciplineSelect = document.getElementById("currCardDiscipline");
const cardCourseSelect = document.getElementById("currCardCourse");
const cardTermSelect = document.getElementById("currCardTerm");
const cardTotalInput = document.getElementById("currCardTotal");
const cardCommentInput = document.getElementById("currCardComment");
const hoursTable = document.getElementById("currHoursTable");
const addHourRowBtn = document.getElementById("currAddHourRowBtn");
const controlsWrap = document.getElementById("currControlsWrap");

let isBound = false;
let activeTab = "cards";

// Данные окна (подгружаются один раз при открытии, обновляются после CRUD)
let meta = null; // { departments, activityKinds, controlTypes, disciplines, groups }
let cardsCache = [];
// Состояние формы карточки
let editingCardId = null; // null = новая карточка
let hourRows = []; // [{ groupId|null, activityKindId, hours }]
let controlState = {}; // { [controlTypeId]: Set(terms) }

const TABS = [
  { key: "cards", title: "Карточки плана" },
  { key: "departments", title: "Кафедры" },
  { key: "kinds", title: "Виды деятельности и контроля" },
  { key: "disciplines", title: "Дисциплины" },
];

function esc(s) {
  return String(s ?? "")
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;");
}

function fmtH(v) {
  const n = Number(v ?? 0);
  return String(Math.round(n * 100) / 100);
}

function setStatus(msg) {
  if (statusEl) statusEl.textContent = msg || "";
}

async function guard(fn) {
  try {
    await fn();
  } catch (e) {
    console.error(e);
    setStatus("");
    alert(e?.message || "Ошибка учебного плана");
  }
}

// ------------------------------------------------------------------ вкладки

function renderTabs() {
  if (!tabsWrap) return;
  tabsWrap.innerHTML = TABS.map(
    (t) =>
      `<button class="dict-tab${t.key === activeTab ? " active" : ""}"` +
      ` data-curr-tab="${t.key}" type="button">${esc(t.title)}</button>`
  ).join("");
  for (const [key, pane] of Object.entries(panes)) {
    pane?.classList.toggle("hidden", key !== activeTab);
  }
  // Кнопка сохранения карточки видна только на вкладке карточек с открытой формой
  cardForm?.classList.toggle("hidden", activeTab !== "cards" || !isFormOpen());
  saveCardBtn?.classList.toggle("hidden", activeTab !== "cards" || !isFormOpen());
}

function isFormOpen() {
  return Boolean(cardForm && !cardForm.classList.contains("hidden"));
}

// --------------------------------------------------------------------- meta

async function loadMeta(force = false) {
  if (meta && !force) return;
  const m = await api.currUiMeta();
  if (!m) throw new Error("Не удалось загрузить данные учебного плана");
  meta = m;
  fillStaticSelects();
}

function fillStaticSelects() {
  if (!meta) return;

  // Фильтры: курсы 1..4, семестры 1..8, кафедры
  if (filterCourse && filterCourse.options.length <= 1) {
    for (let c = 1; c <= 4; c++) {
      filterCourse.insertAdjacentHTML(
        "beforeend",
        `<option value="${c}">Курс ${c}</option>`
      );
    }
  }
  if (filterTerm && filterTerm.options.length <= 1) {
    for (let t = 1; t <= 8; t++) {
      filterTerm.insertAdjacentHTML(
        "beforeend",
        `<option value="${t}">Семестр ${t}</option>`
      );
    }
  }
  if (filterDept && filterDept.dataset.filled !== "1") {
    filterDept.innerHTML =
      `<option value="">Все кафедры</option>` +
      meta.departments
        .map((d) => `<option value="${d.id}">${esc(d.name)}</option>`)
        .join("");
    filterDept.dataset.filled = "1";
  }
  if (newDiscDeptSelect && newDiscDeptSelect.dataset.filled !== "1") {
    newDiscDeptSelect.innerHTML =
      `<option value="">Без кафедры</option>` +
      meta.departments
        .map((d) => `<option value="${d.id}">${esc(d.name)}</option>`)
        .join("");
    newDiscDeptSelect.dataset.filled = "1";
  }
}

// ------------------------------------------------------- карточки (вкладка 1)

async function reloadCards() {
  if (!meta) return;
  cardsCache = await api.currCards({
    course: filterCourse?.value || "",
    term: filterTerm?.value || "",
    departmentId: filterDept?.value || "",
    search: searchInput?.value.trim() || "",
  });
  renderCardsTable();
}

function renderCardsTable() {
  if (!cardsTable) return;
  const head = `<tr>
    <th>Дисциплина</th><th>Кафедра</th><th>Курс</th><th>Сем.</th>
    <th>Трудоемкость</th><th>Часы по видам деятельности</th>
    <th>Контроль</th><th></th></tr>`;
  const rows = cardsCache.map((c) => {
    const hours = (c.hoursSummary || [])
      .map((h) => `${esc(h.shortName || h.kindName)}: ${fmtH(h.hours)}`)
      .join(", ");
    const ctrl = (c.controlsSummary || [])
      .map((x) => `${esc(x.name)} (${x.terms.join(", ")})`)
      .join("; ");
    return `<tr data-card-id="${c.id}">
      <td>${esc(c.disciplineName)}</td>
      <td>${esc(c.departmentName || "—")}</td>
      <td>${c.course}</td>
      <td>${c.term}</td>
      <td>${fmtH(c.totalHours)}</td>
      <td>${hours || '<span class="muted">не заданы</span>'}</td>
      <td>${ctrl || '<span class="muted">—</span>'}</td>
      <td class="curr-row-actions">
        <button class="btn btn-outline" data-edit-card="${c.id}" type="button">Изменить</button>
        <button class="btn btn-outline curr-danger-btn" data-del-card="${c.id}" type="button">✕</button>
      </td>
    </tr>`;
  });
  cardsTable.innerHTML =
    head +
    (rows.length
      ? rows.join("")
      : `<tr><td colspan="8" class="muted">План пуст — нажмите «＋ Дисциплина в план».</td></tr>`);
}

// ------------------------------------------------------------- форма карточки

async function openCardForm(cardId) {
  await loadMeta();
  editingCardId = cardId || null;

  // Дисциплины для выбора (из мета; metaId = id discipline_meta)
  cardDisciplineSelect.innerHTML =
    `<option value="">— выбери дисциплину —</option>` +
    meta.disciplines
      .map(
        (d) =>
          `<option value="${d.metaId ?? ""}" data-disc-id="${d.id}"` +
          `${d.metaId ? "" : " disabled"}>${esc(d.name)}${
            d.metaId ? "" : " (сначала добавьте её во вкладке «Дисциплины»)"
          }</option>`
      )
      .join("");

  cardCourseSelect.innerHTML = [1, 2, 3, 4]
    .map((c) => `<option value="${c}">Курс ${c}</option>`)
    .join("");
  cardTermSelect.innerHTML = Array.from({ length: 8 }, (_, i) => i + 1)
    .map((t) => `<option value="${t}">Семестр ${t}</option>`)
    .join("");

  let card = null;
  if (editingCardId) {
    card = await api.currCard(editingCardId);
    if (!card) return;
  }

  cardFormTitle.textContent = card
    ? `Карточка плана: ${card.disciplineName}`
    : "Новая дисциплина в плане";
  cardDisciplineSelect.value = card
    ? String(
        meta.disciplines.find((d) => d.metaId === card.disciplineMetaId)?.metaId ??
          card.disciplineMetaId ??
          ""
      )
    : "";
  cardCourseSelect.value = String(card?.course ?? 1);
  cardTermSelect.value = String(card?.term ?? 1);
  cardTotalInput.value = card?.totalHours ?? "";
  cardCommentInput.value = card?.comment ?? "";

  hourRows = card
    ? (card.hours || []).map((h) => ({ ...h }))
    : [{ groupId: null, activityKindId: meta.activityKinds[0]?.id ?? "", hours: 0 }];
  renderHoursTable();

  controlState = {};
  for (const c of card?.controls || []) {
    controlState[c.controlTypeId] = new Set(c.terms || []);
  }
  renderControls();

  cardForm.classList.remove("hidden");
  saveCardBtn.classList.remove("hidden");
  setStatus(card ? "Редактирование карточки." : "Новая карточка плана.");
  cardForm.scrollIntoView({ behavior: "smooth", block: "start" });
}

function closeCardForm() {
  cardForm?.classList.add("hidden");
  saveCardBtn?.classList.add("hidden");
  editingCardId = null;
}

// Таблица часов: группа × вид деятельности × часы
function renderHoursTable() {
  if (!hoursTable) return;
  const groupOptions = (selected) =>
    `<option value=""${!selected ? " selected" : ""}>Все группы</option>` +
    meta.groups
      .map(
        (g) =>
          `<option value="${g.id}"${Number(selected) === g.id ? " selected" : ""}>` +
          `${esc(g.name)}${g.course ? ` (курс ${g.course})` : ""}</option>`
      )
      .join("");

  const kindOptions = (selected) =>
    meta.activityKinds
      .map(
        (k) =>
          `<option value="${k.id}"${Number(selected) === k.id ? " selected" : ""}>` +
          `${esc(k.name)}</option>`
      )
      .join("");

  hoursTable.innerHTML =
    `<tr><th>Группа</th><th>Вид деятельности</th><th>Часы</th><th></th></tr>` +
    hourRows
      .map(
        (r, i) => `<tr data-hour-row="${i}">
          <td><select data-hour-field="groupId">${groupOptions(r.groupId)}</select></td>
          <td><select data-hour-field="activityKindId">${kindOptions(r.activityKindId)}</select></td>
          <td><input data-hour-field="hours" type="number" min="0" step="0.5" value="${fmtH(r.hours)}" /></td>
          <td><button class="btn btn-outline curr-danger-btn" data-del-hour="${i}" type="button">✕</button></td>
        </tr>`
      )
      .join("") +
    `<tr class="curr-hours-total"><td colspan="2">Итого по карточке</td>` +
    `<td><b id="currHoursSum">${fmtH(hourRows.reduce((s, r) => s + Number(r.hours || 0), 0))}</b> ч</td><td></td></tr>`;
}

// Блоки видов контроля: чекбоксы семестров 1..8
function renderControls() {
  if (!controlsWrap) return;
  controlsWrap.innerHTML = meta.controlTypes
    .map((ct) => {
      const set = controlState[ct.id] || new Set();
      const boxes = Array.from({ length: 8 }, (_, i) => i + 1)
        .map(
          (t) =>
            `<label class="curr-ctrl-term"><input type="checkbox"` +
            ` data-ctrl-type="${ct.id}" data-ctrl-term="${t}"` +
            `${set.has(t) ? " checked" : ""}/> ${t}</label>`
        )
        .join("");
      return `<div class="curr-ctrl-row"><span class="curr-ctrl-name">${esc(ct.name)}</span>${boxes}</div>`;
    })
    .join("");
}

async function saveCard() {
  await guard(async () => {
    const metaId = Number(cardDisciplineSelect.value);
    if (!metaId) throw new Error("Выберите дисциплину (во вкладке «Дисциплины» она должна быть привязана к кафедре)");
    const payload = {
      id: editingCardId || 0,
      disciplineMetaId: metaId,
      course: Number(cardCourseSelect.value) || 1,
      term: Number(cardTermSelect.value) || 1,
      totalHours: Number(cardTotalInput.value) || 0,
      comment: cardCommentInput.value.trim(),
      hours: hourRows
        .filter((r) => Number(r.activityKindId) > 0)
        .map((r) => ({
          groupId: r.groupId ? Number(r.groupId) : null,
          activityKindId: Number(r.activityKindId),
          hours: Number(r.hours) || 0,
        })),
      controls: Object.entries(controlState)
        .filter(([, set]) => set.size > 0)
        .map(([ctId, set]) => ({
          controlTypeId: Number(ctId),
          terms: [...set].sort((a, b) => a - b),
        })),
    };
    const res = await api.saveCurrCard(payload);
    editingCardId = res?.id || editingCardId;
    setStatus("Карточка сохранена.");
    await Promise.all([reloadCards(), loadMeta(true)]);
  });
}

// ------------------------------------------------------ кафедры (вкладка 2)

async function reloadDepartments() {
  if (!meta) return;
  depTable.innerHTML =
    `<tr><th>Название</th><th>Кратко</th><th></th></tr>` +
    meta.departments
      .map(
        (d) => `<tr>
          <td><input data-dep-field="name" data-dep-id="${d.id}" value="${esc(d.name)}" /></td>
          <td><input data-dep-field="shortName" data-dep-id="${d.id}" value="${esc(d.shortName || "")}" /></td>
          <td class="curr-row-actions">
            <button class="btn btn-outline" data-save-dep="${d.id}" type="button">Сохранить</button>
            <button class="btn btn-outline curr-danger-btn" data-del-dep="${d.id}" type="button">✕</button>
          </td></tr>`
      )
      .join("");
}

// ---------------------------- виды деятельности / контроля (вкладка 3)

async function reloadKinds() {
  if (!meta) return;
  akTable.innerHTML =
    `<tr><th>Название</th><th>Кратко</th><th></th></tr>` +
    meta.activityKinds
      .map(
        (k) => `<tr>
          <td><input data-ak-field="name" data-ak-id="${k.id}" value="${esc(k.name)}" /></td>
          <td><input data-ak-field="shortName" data-ak-id="${k.id}" value="${esc(k.shortName || "")}" /></td>
          <td class="curr-row-actions">
            <button class="btn btn-outline" data-save-ak="${k.id}" type="button">Сохранить</button>
            <button class="btn btn-outline curr-danger-btn" data-del-ak="${k.id}" type="button">✕</button>
          </td></tr>`
      )
      .join("");

  ctTable.innerHTML =
    `<tr><th>Название</th><th></th></tr>` +
    meta.controlTypes
      .map(
        (c) => `<tr>
          <td><input data-ct-field="name" data-ct-id="${c.id}" value="${esc(c.name)}" /></td>
          <td class="curr-row-actions">
            <button class="btn btn-outline" data-save-ct="${c.id}" type="button">Сохранить</button>
            <button class="btn btn-outline curr-danger-btn" data-del-ct="${c.id}" type="button">✕</button>
          </td></tr>`
      )
      .join("");
}

// --------------------------------------------------- дисциплины (вкладка 4)

async function reloadDisciplines() {
  if (!meta) return;
  const deptOptions = (sel) =>
    `<option value="">— без кафедры —</option>` +
    meta.departments
      .map((d) => `<option value="${d.id}"${Number(sel) === d.id ? " selected" : ""}>${esc(d.name)}</option>`)
      .join("");

  discTable.innerHTML =
    `<tr><th>Дисциплина</th><th>Кафедра</th><th></th></tr>` +
    meta.disciplines
      .map(
        (d) => `<tr>
          <td>${esc(d.name)}</td>
          <td><select data-disc-dept="${d.id}" data-meta-id="${d.metaId ?? ""}">${deptOptions(d.departmentId)}</select></td>
          <td class="curr-row-actions">
            <button class="btn btn-outline" data-save-disc="${d.id}" data-meta-id="${d.metaId ?? ""}" type="button">Сохранить</button>
            ${d.metaId ? `<button class="btn btn-outline curr-danger-btn" data-del-disc="${d.metaId}" type="button">Убрать из плана</button>` : ""}
          </td></tr>`
      )
      .join("");
}

// -------------------------------------------------------------------- bind

function bindOnce() {
  if (isBound || !openBtn || !overlay) return;
  isBound = true;

  openBtn.addEventListener("click", async () => {
    overlay.classList.remove("hidden");
    setStatus("Загрузка учебного плана...");
    await guard(async () => {
      await loadMeta(true);
      await Promise.all([
        reloadCards(),
        reloadDepartments(),
        reloadKinds(),
        reloadDisciplines(),
      ]);
      renderTabs();
      setStatus("");
    });
  });

  const close = () => {
    overlay.classList.add("hidden");
    closeCardForm();
  };
  closeBtn?.addEventListener("click", close);
  overlay.addEventListener("mousedown", (e) => {
    if (e.target === overlay) close();
  });
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && !overlay.classList.contains("hidden")) close();
  });

  tabsWrap?.addEventListener("click", (e) => {
    const btn = e.target.closest("[data-curr-tab]");
    if (!btn) return;
    activeTab = btn.dataset.currTab;
    renderTabs();
  });

  // ---- карточки: фильтры, кнопки, форма
  for (const el of [filterCourse, filterTerm, filterDept]) {
    el?.addEventListener("change", () => guard(reloadCards));
  }
  let searchTimer = null;
  searchInput?.addEventListener("input", () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => guard(reloadCards), 300);
  });
  addCardBtn?.addEventListener("click", () => guard(() => openCardForm(null)));

  cardsTable?.addEventListener("click", (e) => {
    const edit = e.target.closest("[data-edit-card]");
    if (edit) return guard(() => openCardForm(Number(edit.dataset.editCard)));
    const del = e.target.closest("[data-del-card]");
    if (del) {
      if (!confirm("Удалить карточку учебного плана вместе с часами?")) return;
      return guard(async () => {
        await api.deleteCurrCard(Number(del.dataset.delCard));
        if (editingCardId === Number(del.dataset.delCard)) closeCardForm();
        await reloadCards();
        setStatus("Карточка удалена.");
      });
    }
  });

  cardCancelBtn?.addEventListener("click", closeCardForm);
  saveCardBtn?.addEventListener("click", () => guard(saveCard));

  // редактор часов
  addHourRowBtn?.addEventListener("click", () => {
    hourRows.push({
      groupId: null,
      activityKindId: meta?.activityKinds[0]?.id ?? "",
      hours: 0,
    });
    renderHoursTable();
  });

  hoursTable?.addEventListener("input", (e) => {
    const tr = e.target.closest("[data-hour-row]");
    if (!tr) return;
    const idx = Number(tr.dataset.hourRow);
    const field = e.target.dataset.hourField;
    if (!field || hourRows[idx] === undefined) return;
    if (field === "hours") hourRows[idx].hours = Number(e.target.value) || 0;
    else hourRows[idx][field] = e.target.value || null;
    const sumEl = document.getElementById("currHoursSum");
    if (sumEl) {
      sumEl.textContent = fmtH(hourRows.reduce((s, r) => s + Number(r.hours || 0), 0));
    }
    setStatus("Есть несохранённые изменения в часах.");
  });

  hoursTable?.addEventListener("click", (e) => {
    const del = e.target.closest("[data-del-hour]");
    if (!del) return;
    hourRows.splice(Number(del.dataset.delHour), 1);
    renderHoursTable();
  });

  // редактор контроля
  controlsWrap?.addEventListener("change", (e) => {
    const cb = e.target.closest("[data-ctrl-type]");
    if (!cb) return;
    const ctId = Number(cb.dataset.ctrlType);
    const term = Number(cb.dataset.ctrlTerm);
    if (!controlState[ctId]) controlState[ctId] = new Set();
    if (cb.checked) controlState[ctId].add(term);
    else controlState[ctId].delete(term);
    setStatus("Есть несохранённые изменения в контроле.");
  });

  // ---- кафедры
  depAddBtn?.addEventListener("click", () =>
    guard(async () => {
      const name = depNameInput.value.trim();
      if (!name) throw new Error("Укажите название кафедры");
      await api.saveCurrDepartment({ name, short_name: depShortInput.value.trim() });
      depNameInput.value = "";
      depShortInput.value = "";
      await Promise.all([loadMeta(true), reloadDepartments(), reloadCards()]);
      fillStaticSelects();
      setStatus("Кафедра добавлена.");
    })
  );

  depTable?.addEventListener("click", (e) => {
    const save = e.target.closest("[data-save-dep]");
    if (save) {
      const id = Number(save.dataset.saveDep);
      const name = depTable.querySelector(`[data-dep-field="name"][data-dep-id="${id}"]`)?.value.trim();
      const shortName = depTable.querySelector(`[data-dep-field="shortName"][data-dep-id="${id}"]`)?.value.trim();
      if (!name) return alert("Название не может быть пустым");
      return guard(async () => {
        await api.saveCurrDepartment({ id, name, short_name: shortName });
        await Promise.all([loadMeta(true), reloadDepartments()]);
        setStatus("Кафедра сохранена.");
      });
    }
    const del = e.target.closest("[data-del-dep]");
    if (del) {
      if (!confirm("Удалить кафедру?")) return;
      return guard(async () => {
        await api.deleteCurrDepartment(Number(del.dataset.delDep));
        await Promise.all([loadMeta(true), reloadDepartments()]);
        fillStaticSelects();
        setStatus("Кафедра удалена.");
      });
    }
  });

  // ---- виды деятельности
  akAddBtn?.addEventListener("click", () =>
    guard(async () => {
      const name = akNameInput.value.trim();
      if (!name) throw new Error("Укажите название вида деятельности");
      await api.saveCurrActivityKind({ name, short_name: akShortInput.value.trim() });
      akNameInput.value = "";
      akShortInput.value = "";
      await Promise.all([loadMeta(true), reloadKinds()]);
      setStatus("Вид деятельности добавлен.");
    })
  );

  akTable?.addEventListener("click", (e) => {
    const save = e.target.closest("[data-save-ak]");
    if (save) {
      const id = Number(save.dataset.saveAk);
      const name = akTable.querySelector(`[data-ak-field="name"][data-ak-id="${id}"]`)?.value.trim();
      const shortName = akTable.querySelector(`[data-ak-field="shortName"][data-ak-id="${id}"]`)?.value.trim();
      if (!name) return alert("Название не может быть пустым");
      return guard(async () => {
        await api.saveCurrActivityKind({ id, name, short_name: shortName });
        await Promise.all([loadMeta(true), reloadKinds()]);
        setStatus("Сохранено.");
      });
    }
    const del = e.target.closest("[data-del-ak]");
    if (del) {
      if (!confirm("Удалить вид деятельности? Часы по нему исчезнут из плана.")) return;
      return guard(async () => {
        await api.deleteCurrActivityKind(Number(del.dataset.delAk));
        await Promise.all([loadMeta(true), reloadKinds()]);
        setStatus("Удалено.");
      });
    }
  });

  // ---- виды контроля
  ctAddBtn?.addEventListener("click", () =>
    guard(async () => {
      const name = ctNameInput.value.trim();
      if (!name) throw new Error("Укажите название вида контроля");
      await api.saveCurrControlType({ name });
      ctNameInput.value = "";
      await Promise.all([loadMeta(true), reloadKinds()]);
      setStatus("Вид контроля добавлен.");
    })
  );

  ctTable?.addEventListener("click", (e) => {
    const save = e.target.closest("[data-save-ct]");
    if (save) {
      const id = Number(save.dataset.saveCt);
      const name = ctTable.querySelector(`[data-ct-field="name"][data-ct-id="${id}"]`)?.value.trim();
      if (!name) return alert("Название не может быть пустым");
      return guard(async () => {
        await api.saveCurrControlType({ id, name });
        await Promise.all([loadMeta(true), reloadKinds()]);
        setStatus("Сохранено.");
      });
    }
    const del = e.target.closest("[data-del-ct]");
    if (del) {
      if (!confirm("Удалить вид контроля?")) return;
      return guard(async () => {
        await api.deleteCurrControlType(Number(del.dataset.delCt));
        await Promise.all([loadMeta(true), reloadKinds()]);
        setStatus("Удалено.");
      });
    }
  });

  // ---- дисциплины плана
  newDiscAddBtn?.addEventListener("click", () =>
    guard(async () => {
      const name = newDiscNameInput.value.trim();
      if (!name) throw new Error("Укажите название дисциплины");
      await api.saveCurrDiscipline({
        name,
        departmentId: Number(newDiscDeptSelect.value) || 0,
      });
      newDiscNameInput.value = "";
      await Promise.all([loadMeta(true), reloadDisciplines()]);
      setStatus("Дисциплина добавлена в план.");
    })
  );

  discTable?.addEventListener("click", (e) => {
    const save = e.target.closest("[data-save-disc]");
    if (save) {
      const disciplineId = Number(save.dataset.saveDisc);
      const metaId = Number(save.dataset.metaId) || 0;
      const sel = discTable.querySelector(`[data-disc-dept="${disciplineId}"]`);
      const departmentId = Number(sel?.value) || 0;
      return guard(async () => {
        await api.saveCurrDiscipline({
          id: metaId,
          disciplineId,
          departmentId,
        });
        await Promise.all([loadMeta(true), reloadDisciplines()]);
        setStatus("Кафедра дисциплины сохранена.");
      });
    }
    const del = e.target.closest("[data-del-disc]");
    if (del) {
      if (!confirm("Убрать дисциплину из учебного плана? Её карточки тоже будут удалены.")) return;
      return guard(async () => {
        await api.deleteCurrDiscipline(Number(del.dataset.delDisc));
        await Promise.all([loadMeta(true), reloadDisciplines(), reloadCards()]);
        setStatus("Дисциплина убрана из плана.");
      });
    }
  });
}

export function initCurriculumModal() {
  bindOnce();
}
