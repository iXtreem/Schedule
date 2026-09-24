
import renderWeekSelect from "./js/modules/weeks/renderWeekSelect.js";
import {
  renderGroups,
  setGroupCheckboxesFromState,
} from "./js/modules/groups/renderGroups.js";
import { renderTable } from "./js/modules/schedule/renderTable.js";
import { renderTeacherTable } from "./js/modules/schedule/renderTeacherTable.js";

import {
  openModal,
  closeModal,
  saveLesson,
  deleteLesson,
} from "./js/modules/modals/openModal.js";
import { initRoomPrefsModal } from "./js/modules/modals/roomPrefsModal.js";
import { initDictModal } from "./js/modules/modals/dictModal.js";
import { ensureBellLoaded, setBellSchedules } from "./js/modules/modals/bellStore.js";
import { filterByName } from "./js/modules/modals/searchModal.js";

import {
  formatDateForInput,
  formatDateForDisplay,
} from "./js/modules/schedule/dateUtils.js";
import { renderWeekDates } from "./js/modules/weeks/weakStoreState.js";
// Модуль автосоздания недель семестра в одну кнопку (модалка + вызов API).
import { initGenerateWeeks } from "./js/modules/weeks/generateWeeks.js";

import { api } from "./js/LoadFromBD/api.js";

export const state = {
  currentWeekId: null,
  weekStart: null,
  holidays: [],

  groups: [],
  subjects: [],
  teachers: [],
  lessonTypes: [],
  rooms: [],
  weeks: [],

  selectedGroupIds: [],
  lessons: [],
  currentEdit: null,

  filteredGroups: [],

  groupTerm: {},

  groupPageIndex: 0,
  groupPageSize: 3,

  onlyActiveGroups: true,
  groupTripletPresets: [],
  exportBundles: [],
  scheduleView: "groups",
  teacherSearchTerm: "",
};

const weekSelect = document.getElementById("weekSelect");
const groupBox = document.getElementById("groupBox");
export const scheduleTable = document.getElementById("scheduleTable");
const groupSearch = document.getElementById("groupSearch");
const applyGroupsBtn = document.getElementById("applyGroups");
const downloadRoomGridBtn = document.getElementById("downloadRoomGridBtn");
const openExportBuilderBtn = document.getElementById("openExportBuilderBtn");
const downloadScheduleGridBtn = document.getElementById(
  "downloadScheduleGridBtn",
);
const downloadAllRecentScheduleBtn = document.getElementById(
  "downloadAllRecentScheduleBtn",
);
const downloadTeacherGridBtn = document.getElementById("downloadTeacherGridBtn");
const exportBuilderOverlay = document.getElementById("exportBuilderOverlay");
const exportBuilderClose = document.getElementById("exportBuilderClose");
const exportBuilderCancel = document.getElementById("exportBuilderCancel");
const groupsTabBtn = document.getElementById("groupsTabBtn");
const teachersTabBtn = document.getElementById("teachersTabBtn");
const teacherViewSearch = document.getElementById("teacherViewSearch");

const selectAllGroupsBtn = document.getElementById("selectAllGroupsBtn");
const clearAllGroupsBtn = document.getElementById("clearAllGroupsBtn");
const onlyActiveGroups = document.getElementById("onlyActiveGroups");

const prevTripletBtn = document.getElementById("prevTripletBtn");
const nextTripletBtn = document.getElementById("nextTripletBtn");
const tripletInfo = document.getElementById("tripletInfo");

const tripletPresetSelect = document.getElementById("tripletPresetSelect");
const saveTripletPresetBtn = document.getElementById("saveTripletPresetBtn");
const deleteTripletPresetBtn = document.getElementById(
  "deleteTripletPresetBtn",
);
const exportTemplateSelect = document.getElementById("exportTemplateSelect");
const addExportBundleBtn = document.getElementById("addExportBundleBtn");
const clearExportBundlesBtn = document.getElementById("clearExportBundlesBtn");
const exportBundlesList = document.getElementById("exportBundlesList");

const addWeekBtn = document.getElementById("addWeekBtn");
const weekModalOverlay = document.getElementById("weekModalOverlay");
const weekModalClose = document.getElementById("weekModalClose");
const weekCancelBtn = document.getElementById("weekCancelBtn");
const saveWeekBtn = document.getElementById("saveWeekBtn");

const weekNameInput = document.getElementById("weekName");
const startDateInput = document.getElementById("startDate");
const endDateInput = document.getElementById("endDate");

const modalClose = document.getElementById("modalClose");
const saveLessonBtn = document.getElementById("saveLessonBtn");
const deleteLessonBtn = document.getElementById("deleteLessonBtn");
const logoutBtn = document.getElementById("logoutBtn");
const authUserLabel = document.getElementById("authUserLabel");
const groupsOnlyNodes = [...document.querySelectorAll(".groups-only")];
const teachersOnlyNodes = [...document.querySelectorAll(".teachers-only")];

function uniqById(list) {
  const m = new Map();
  for (const x of list || []) {
    const id = Number(x.id);
    if (!m.has(id)) m.set(id, { ...x, id });
  }
  return [...m.values()];
}

function normalizeWeeks(raw) {
  return (raw || []).map((w) => ({
    ...w,
    id: Number(w.id ?? w.idWeek ?? w.id_week),
    start_date: w.start_date ?? w.StartDate ?? w.startDate,
    end_date: w.end_date ?? w.EndDate ?? w.endDate,
    name: w.name ?? w.WeekName ?? w.week_name,
  }));
}

function normalizeGroups(raw) {
  return uniqById(
    (raw || []).map((g) => ({
      ...g,
      id: Number(g.id ?? g.idGroup ?? g.id_group),
      name:
        g.name ??
        g.GroupName ??
        g.group_name ??
        g.short_name ??
        g.GroupShortName,
      short_name: g.short_name ?? g.GroupShortName ?? g.group_short_name ?? "",
      size: Number(g.size ?? g.max_students ?? g.GroupMaxContrBook ?? 0),
    })),
  );
}

// Нормализация ответа «праздники недели» к массиву строк 'YYYY-MM-DD'.
// Бэкенд (entity=holidays_range) обычно возвращает ["2026-10-05", ...],
// но при ошибке/неожиданном формате может вернуть объект вида
// {"2026-10-02": true} или null — из-за этого в getDayType падал
// renderTable с TypeError. Приводим любой ответ к безопасному массиву.
function normalizeHolidays(raw) {
  if (Array.isArray(raw)) {
    return raw
      .map((x) => (typeof x === "string" ? x : x && x.date)) // строки или {date}
      .filter(Boolean);
  }
  if (raw && typeof raw === "object") {
    // объект-словарь дат -> берём ключи, где значение «истинно»
    return Object.keys(raw).filter((k) => raw[k]);
  }
  return [];
}

function normalizeLessons(raw) {
  const num = (v) => (v == null ? null : Number(v));
  return (raw || []).map((r) => ({
    customText:
      r.customText == null && r.custom_text == null && r.CustomText == null
        ? ""
        : String(r.customText ?? r.custom_text ?? r.CustomText),
    id: num(r.id ?? r.idSchedule ?? r.id_schedule),
    weekId: num(r.weekId ?? r.week_id ?? r.idWeek),
    groupId: num(r.groupId ?? r.group_id ?? r.idGroup),
    dayOfWeek: num(r.dayOfWeek ?? r.day_of_week ?? r.DayOfWeek),
    timeSlot: num(r.timeSlot ?? r.time_slot ?? r.TimeSlot),
    subjectId: num(r.subjectId ?? r.subject_id ?? r.idDiscipl),
    teacherId: num(r.teacherId ?? r.teacher_id ?? r.idTeacher),
    roomId: num(r.roomId ?? r.room_id ?? r.idRoom),
    typeId: num(r.typeId ?? r.lesson_type_id ?? r.idLessonType),
    hours: r.hours != null ? Number(r.hours) : null,
  }));
}

function renderActiveTable() {
  if (state.scheduleView === "teachers") {
    renderTeacherTable();
    return;
  }
  renderTable();
}

function setScheduleView(view) {
  const nextView = view === "teachers" ? "teachers" : "groups";
  if (state.scheduleView === nextView) return;

  state.scheduleView = nextView;
  syncScheduleViewUi();
  renderActiveTable();
}

function syncScheduleViewUi() {
  const isTeachersView = state.scheduleView === "teachers";

  groupsTabBtn?.classList.toggle("is-active", !isTeachersView);
  groupsTabBtn?.setAttribute("aria-selected", String(!isTeachersView));

  teachersTabBtn?.classList.toggle("is-active", isTeachersView);
  teachersTabBtn?.setAttribute("aria-selected", String(isTeachersView));

  for (const el of groupsOnlyNodes) {
    el.classList.toggle("hidden", isTeachersView);
  }
  for (const el of teachersOnlyNodes) {
    el.classList.toggle("hidden", !isTeachersView);
  }
}

async function ensureAuthorized() {
  const me = await api.authMe();
  if (!me?.authenticated || !me?.user?.id) {
    window.location.href = "./login.html";
    return null;
  }
  return me.user;
}

function bindAuthActions() {
  logoutBtn?.addEventListener("click", async () => {
    try {
      await api.authLogout();
    } catch (e) {
      console.error("Ошибка выхода из системы:", e);
    } finally {
      window.location.href = "./login.html";
    }
  });
}

let eventsBound = false;

async function init() {
  // Кнопки модальных окон привязываем СРАЗУ, до любых обращений к серверу.
  // Иначе при недоступном API (не запущен MySQL/Apache, ошибка в PHP)
  // init() прерывается выше этой строки — и кнопки «Справочники»,
  // «Закрепление кабинета» и т.п. остаются без обработчиков («ничего не происходит»).
  initRoomPrefsModal();
  initDictModal();
  // Кнопка «⚡ Семестр» (автосоздание недель) — тоже привязываем сразу,
  // до любых обращений к серверу.
  initGenerateWeeks();

  // Ответное событие из модуля недель: после автосоздания открыть первую
  // созданную неделю (changeWeek живёт здесь, в app.js).
  window.addEventListener("weeks-generated", async (e) => {
    const firstWeekId = e.detail?.firstWeekId;
    if (!firstWeekId) return;
    try {
      await changeWeek(firstWeekId);
      if (weekSelect) weekSelect.value = String(firstWeekId);
    } catch (err) {
      console.error("Не удалось открыть первую неделю:", err);
    }
  });

  const authUser = await ensureAuthorized();
  if (!authUser) return;

  if (authUserLabel) authUserLabel.textContent = `Пользователь: ${authUser.login}`;
  bindAuthActions();

  state.groups = normalizeGroups(await api.groups());
  state.filteredGroups = [...state.groups];

  if (onlyActiveGroups) onlyActiveGroups.checked = true;
  applyGroupsFilters();
  renderGroups();
  setGroupCheckboxesFromState();

  loadTripletPresets();
  updateTripletInfo();
  renderExportBundles();

  state.weeks = normalizeWeeks(await api.weeks());

  // Время пар (расписание звонков) загружаем из БД — таблица расписания
  // должна показывать сохранённые настройки, а не значения по умолчанию.
  await ensureBellLoaded();

  const [subjects, teachers, rooms, lessonTypes] = await Promise.all([
    api.subjects(),
    api.teachers(),
    api.rooms(),
    api.lessonTypes(),
  ]);

  state.subjects = (subjects || []).map((x) => ({ ...x, id: Number(x.id) }));
  state.teachers = (teachers || []).map((x) => ({ ...x, id: Number(x.id) }));
  state.rooms = (rooms || []).map((x) => ({ ...x, id: Number(x.id) }));
  state.lessonTypes = (lessonTypes || []).map((x) => ({
    ...x,
    id: Number(x.id),
  }));

  window.addEventListener("dict-changed", async (e) => {
    const kind = e.detail?.kind;
    try {
      if (kind === "groups") {
        state.groups = normalizeGroups(await api.groups());
        applyGroupsFilters();
        renderGroups();
        setGroupCheckboxesFromState();
      } else if (kind === "weeks") {
        state.weeks = normalizeWeeks(await api.weeks());
        renderWeekSelect();
        const stillThere = state.weeks.some(
          (w) => Number(w.id) === Number(state.currentWeekId)
        );
        const nextWeek = stillThere ? state.currentWeekId : state.weeks[0]?.id;
        if (nextWeek) {
          await changeWeek(nextWeek);
          if (weekSelect) weekSelect.value = String(nextWeek);
        }
      } else if (kind === "teachers" || kind === "subjects" || kind === "rooms" || kind === "lesson_types") {
        const [subjects, teachers, rooms, lessonTypes] = await Promise.all([
          api.subjects(),
          api.teachers(),
          api.rooms(),
          api.lessonTypes(),
        ]);
        state.subjects = (subjects || []).map((x) => ({ ...x, id: Number(x.id) }));
        state.teachers = (teachers || []).map((x) => ({ ...x, id: Number(x.id) }));
        state.rooms = (rooms || []).map((x) => ({ ...x, id: Number(x.id) }));
        state.lessonTypes = (lessonTypes || []).map((x) => ({ ...x, id: Number(x.id) }));
      } else if (kind === "bell") {
        // «Время пар» сохранено — синхронизируем общее хранилище со сервером,
        // чтобы колонка времени в таблице расписания сразу обновилась.
        setBellSchedules(await ensureBellLoaded(true));
      }
      renderActiveTable();
    } catch (err) {
      console.error("Не удалось обновить данные после изменения справочника:", err);
    }
  });

  // Вкладка «Выходные дни» меняет праздники через календарь — обновляем таблицу расписания.
  window.addEventListener("holidays-changed", async () => {
    try {
      const wk = state.weeks.find(
        (w) => Number(w.id) === Number(state.currentWeekId)
      );
      // нормализуем ответ к массиву: бэкенд при ошибке может вернуть не список
      if (wk) state.holidays = normalizeHolidays(await api.holidaysRange(wk.start_date, wk.end_date));
      renderActiveTable();
    } catch (err) {
      console.error("Не удалось обновить праздники:", err);
    }
  });

  state.selectedGroupIds = state.groups.map((g) => Number(g.id));

  renderWeekSelect();
  renderGroups();
  setGroupCheckboxesFromState();
  if (teacherViewSearch) teacherViewSearch.value = state.teacherSearchTerm;
  syncScheduleViewUi();

  if (state.weeks.length) {
    await changeWeek(state.weeks[0].id);
    weekSelect.value = String(state.weeks[0].id);
  } else {
    scheduleTable.innerHTML = `<tr><td class="muted">Нет недель</td></tr>`;
  }

  bindEvents();
}

async function changeWeek(weekId) {
  state.currentWeekId = Number(weekId);

  const wk = state.weeks.find((w) => Number(w.id) === Number(weekId));
  if (!wk) return;

  state.weekStart = new Date(wk.start_date);

  const rawLessons = await api.scheduleForWeek(weekId);
  state.lessons = normalizeLessons(rawLessons);

  // Праздники недели: всегда нормализуем к массиву строк 'YYYY-MM-DD'.
  // Это защита от падения renderTable (TypeError в getDayType), если бэкенд
  // вернул вместо списка объект/null (например, при ошибке SQL или сессии).
  state.holidays = normalizeHolidays(await api.holidaysRange(wk.start_date, wk.end_date));

  renderWeekDates();
  renderActiveTable();
}

function bindEvents() {
  if (eventsBound) return;
  eventsBound = true;

  weekSelect.addEventListener("change", async () => {
    const weekId = Number(weekSelect.value);
    if (!weekId) return;
    await changeWeek(weekId);
  });

  groupsTabBtn?.addEventListener("click", () => {
    setScheduleView("groups");
  });

  teachersTabBtn?.addEventListener("click", () => {
    setScheduleView("teachers");
  });

  teacherViewSearch?.addEventListener("input", () => {
    state.teacherSearchTerm = teacherViewSearch.value || "";
    if (state.scheduleView === "teachers") {
      renderActiveTable();
    }
  });

  groupSearch.addEventListener("input", () => {
    applyGroupsFilters();
    renderGroups();
    setGroupCheckboxesFromState();
  });

  groupBox?.addEventListener("change", (e) => {
    const cb = e.target.closest("input[type=checkbox]");
    if (!cb) return;

    const id = Number(cb.value || 0);
    if (!id) return;

    if (cb.checked) {
      if (!state.selectedGroupIds.includes(id)) {
        state.selectedGroupIds.push(id);
      }
    } else {
      state.selectedGroupIds = state.selectedGroupIds.filter(
        (groupId) => Number(groupId) !== id,
      );
    }

    updateTripletInfo();
  });

  onlyActiveGroups?.addEventListener("change", () => {
    applyGroupsFilters();
    renderGroups();
    setGroupCheckboxesFromState();
    renderActiveTable();
    updateTripletInfo();
  });

  selectAllGroupsBtn?.addEventListener("click", () => {
    state.selectedGroupIds = state.filteredGroups.map((g) => Number(g.id));
    state.groupPageIndex = 0;
    renderGroups();
    setGroupCheckboxesFromState();
    renderActiveTable();
    updateTripletInfo();
  });

  clearAllGroupsBtn?.addEventListener("click", () => {
    state.selectedGroupIds = [];
    state.groupPageIndex = 0;
    renderGroups();
    setGroupCheckboxesFromState();
    renderActiveTable();
    updateTripletInfo();
  });


  applyGroupsBtn.addEventListener("click", () => {
    state.groupPageIndex = 0;
    renderActiveTable();
    updateTripletInfo();
  });

  // листание по 3 группы
  prevTripletBtn?.addEventListener("click", () => {
    state.groupPageIndex = Math.max(0, state.groupPageIndex - 1);
    renderActiveTable();
    updateTripletInfo();
  });

  nextTripletBtn?.addEventListener("click", () => {
    const ids = getSelectedGroupsForTable();
    const totalPages = Math.max(1, Math.ceil(ids.length / state.groupPageSize));
    state.groupPageIndex = Math.min(totalPages - 1, state.groupPageIndex + 1);
    renderActiveTable();
    updateTripletInfo();
  });

  // сохранить пресет (3 группы)
  saveTripletPresetBtn?.addEventListener("click", () => {
    const ids = getSelectedGroupsForTable();
    const start = state.groupPageIndex * state.groupPageSize;
    const triplet = ids.slice(start, start + state.groupPageSize);

    if (triplet.length !== 3) {
      alert(
        "Чтобы сохранить набор, на странице должно быть ровно 3 выбранные группы.",
      );
      return;
    }

    const name = prompt("Название набора (3 группы):", "Набор 1");
    if (!name) return;

    const preset = {
      id: Date.now(),
      name,
      groupIds: triplet,
    };

    state.groupTripletPresets.push(preset);
    saveTripletPresets();
    renderTripletPresets();
  });

  // применить пресет
  tripletPresetSelect?.addEventListener("change", () => {
    const id = Number(tripletPresetSelect.value);
    if (!id) return;

    const p = state.groupTripletPresets.find((x) => Number(x.id) === id);
    if (!p) return;

    state.selectedGroupIds = p.groupIds.map(Number);
    state.groupPageIndex = 0;

    renderGroups();
    setGroupCheckboxesFromState();
    renderActiveTable();
    updateTripletInfo();
  });

  // удалить пресет
  deleteTripletPresetBtn?.addEventListener("click", () => {
    const id = Number(tripletPresetSelect?.value || 0);
    if (!id) {
      alert("Сначала выбери набор в списке.");
      return;
    }
    if (!confirm("Удалить выбранный набор?")) return;

    state.groupTripletPresets = state.groupTripletPresets.filter(
      (x) => Number(x.id) !== id,
    );
    saveTripletPresets();
    renderTripletPresets();
    tripletPresetSelect.value = "";
  });

  addExportBundleBtn?.addEventListener("click", () => {
    addExportBundleFromCurrentSelection();
  });

  clearExportBundlesBtn?.addEventListener("click", () => {
    state.exportBundles = [];
    renderExportBundles();
  });

  exportBundlesList?.addEventListener("click", (e) => {
    const removeBtn = e.target.closest("button[data-export-bundle-id]");
    if (!removeBtn) return;

    const bundleId = Number(removeBtn.dataset.exportBundleId || 0);
    if (!bundleId) return;

    state.exportBundles = state.exportBundles.filter(
      (bundle) => Number(bundle.id) !== bundleId,
    );
    renderExportBundles();
  });

  openExportBuilderBtn?.addEventListener("click", () => {
    openExportBuilderModal();
  });

  exportBuilderClose?.addEventListener("click", () => {
    closeExportBuilderModal();
  });

  exportBuilderCancel?.addEventListener("click", () => {
    closeExportBuilderModal();
  });

  exportBuilderOverlay?.addEventListener("click", (e) => {
    if (e.target === exportBuilderOverlay) {
      closeExportBuilderModal();
    }
  });

  scheduleTable.addEventListener("click", async (e) => {
    if (state.scheduleView !== "groups") return;

    const cell = e.target.closest("td[data-role='lesson-cell']");
    if (!cell) return;

    const groupId = Number(cell.dataset.groupId);
    const dayIndex = Number(cell.dataset.dayIndex);
    const pairIndex = Number(cell.dataset.pairIndex);
    const lessonId = cell.dataset.lessonId
      ? Number(cell.dataset.lessonId)
      : null;

    await openModal({ groupId, dayIndex, pairIndex, lessonId });
  });

  // Обработчик тумблеров «Праздник» в таблице удалён:
  // выходные дни теперь отмечаются кликом по календарю
  // в окне «Справочники» → вкладка «Выходные дни» (см. js/modules/modals/daysOffCalendar.js).
  // Обновление таблицы происходит через событие "holidays-changed".

  if (modalClose) modalClose.addEventListener("click", closeModal);
  if (saveLessonBtn) saveLessonBtn.addEventListener("click", saveLesson);
  if (deleteLessonBtn) deleteLessonBtn.addEventListener("click", deleteLesson);

  addWeekBtn.addEventListener("click", () => {
    weekNameInput.value = "";
    startDateInput.value = "";
    endDateInput.value = "";

    const base = state.weekStart ? new Date(state.weekStart) : new Date();
    const start = new Date(base);
    start.setDate(start.getDate() + 7);
    const end = new Date(start);
    end.setDate(end.getDate() + 6);

    startDateInput.value = formatDateForInput(start);
    endDateInput.value = formatDateForInput(end);
    weekNameInput.value = `${formatDateForDisplay(start)} - ${formatDateForDisplay(end)}`;

    weekModalOverlay.classList.remove("hidden");
  });

  weekModalClose.addEventListener("click", () =>
    weekModalOverlay.classList.add("hidden"),
  );
  weekCancelBtn.addEventListener("click", () =>
    weekModalOverlay.classList.add("hidden"),
  );

  saveWeekBtn.addEventListener("click", async () => {
    const weekName = weekNameInput.value.trim();
    const startDate = startDateInput.value;
    const endDate = endDateInput.value;

    if (!weekName || !startDate || !endDate)
      return alert("Заполни все поля недели.");
    if (new Date(endDate) < new Date(startDate))
      return alert("Дата окончания меньше даты начала.");

    try {
      const createWeekFn = api.createWeek || api.addWeek;
      if (!createWeekFn) {
        alert("Нет метода api.createWeek/api.addWeek в api.js");
        return;
      }


      const res = await api.createWeek({
        name: weekName,
        start_date: startDate,
        end_date: endDate,
      });

      if (!res?.success) {
        alert("Ошибка создания недели");
        return;
      }


      state.weeks = normalizeWeeks(await api.weeks());
      renderWeekSelect();

      weekModalOverlay.classList.add("hidden");


      weekSelect.value = String(res.id);
      await changeWeek(res.id);
    } catch (e) {
      console.error(e);
      alert("Ошибка создания недели");
    }
  });

  downloadRoomGridBtn?.addEventListener("click", () => {
    const weekId = Number(state.currentWeekId || weekSelect?.value || 0);
    if (!weekId) {
      alert("Сначала выбери неделю.");
      return;
    }

    const url = `./ScheduleEXCEL2/export.php?week_id=${encodeURIComponent(String(weekId))}&v=${Date.now()}`;
    window.location.href = url;
  });

  downloadScheduleGridBtn?.addEventListener("click", () => {
    const weekId = Number(state.currentWeekId || weekSelect?.value || 0);
    if (!weekId) {
      alert("Сначала выбери неделю.");
      return;
    }

    const bundlePlan = buildExportBundlePlan();
    if (!bundlePlan.length) {
      alert("Сначала сформируй наборы экспорта в конструкторе.");
      return;
    }

    const url = `./ScheduleEXCEL1/export.php?week_id=${encodeURIComponent(String(weekId))}&bundle_plan=${encodeURIComponent(JSON.stringify(bundlePlan))}&v=${Date.now()}`;
    closeExportBuilderModal();
    window.location.href = url;
  });

  downloadAllRecentScheduleBtn?.addEventListener("click", () => {
    const weekId = Number(state.currentWeekId || weekSelect?.value || 0);
    if (!weekId) {
      alert("Select a week first.");
      return;
    }

    const url = `./ScheduleEXCEL1/export.php?week_id=${encodeURIComponent(String(weekId))}&export_mode=all_recent&recent_years=4&v=${Date.now()}`;
    closeExportBuilderModal();
    window.location.href = url;
  });

  downloadTeacherGridBtn?.addEventListener("click", () => {
    const weekId = Number(state.currentWeekId || weekSelect?.value || 0);
    if (!weekId) {
      alert("Сначала выбери неделю.");
      return;
    }

    const url = `./ScheduleEXCEL3/export.php?week_id=${encodeURIComponent(String(weekId))}&v=${Date.now()}`;
    window.location.href = url;
  });
}

function getAdmissionYearFromGroupName(name) {
  // ищем "ЭВТ23-1Б" => 23, "2023" => 2023
  const s = (name || "").trim();

  // 4 цифры
  const m4 = s.match(/\b(20\d{2})\b/);
  if (m4) return Number(m4[1]);

  // 2 цифры после букв (ЭВТ23-..., ИС-21 и т.п.)
  const m2 = s.match(/[A-Za-zА-Яа-яЁё]+(\d{2})/);
  if (m2) return 2000 + Number(m2[1]);

  // запасной вариант: любые 2 цифры
  const mAny2 = s.match(/\b(\d{2})\b/);
  if (mAny2) return 2000 + Number(mAny2[1]);

  return null;
}

function isActiveGroup(g) {
  const year = getAdmissionYearFromGroupName(g.short_name || g.name || "");
  if (!year) return true;
  const currentYear = new Date().getFullYear();
  const minYear = currentYear - 4;
  return year >= minYear;
}

function applyGroupsFilters() {
  const q = (groupSearch.value || "").trim().toLowerCase();
  const onlyActive = !!onlyActiveGroups?.checked;

  state.onlyActiveGroups = onlyActive;

  state.filteredGroups = state.groups.filter((g) => {
    const nm = (g.short_name || g.name || "").toLowerCase();
    const okSearch = q ? nm.includes(q) : true;
    const okActive = onlyActive ? isActiveGroup(g) : true;
    return okSearch && okActive;
  });

  // если текущая страница вылетела за пределы — откатим
  const totalPages = Math.max(
    1,
    Math.ceil(getSelectedGroupsForTable().length / state.groupPageSize),
  );
  if (state.groupPageIndex > totalPages - 1)
    state.groupPageIndex = totalPages - 1;
}

function getSelectedGroupsForTable() {
  // показываем только те, что выбраны + попадают в фильтр актуальности (если включен)
  const allowed = new Set(state.filteredGroups.map((g) => Number(g.id)));
  const picked = state.selectedGroupIds.filter((id) => allowed.has(Number(id)));
  // если ничего не осталось после фильтра — можно показать пусто
  return picked;
}

function updateTripletInfo() {
  const ids = getSelectedGroupsForTable();
  const totalPages = Math.max(1, Math.ceil(ids.length / state.groupPageSize));
  const current = Math.min(state.groupPageIndex + 1, totalPages);
  if (tripletInfo) tripletInfo.textContent = `${current}/${totalPages}`;
}

function loadTripletPresets() {
  try {
    state.groupTripletPresets = JSON.parse(
      localStorage.getItem("groupTripletPresets") || "[]",
    );
  } catch {
    state.groupTripletPresets = [];
  }
  renderTripletPresets();
}

function saveTripletPresets() {
  localStorage.setItem(
    "groupTripletPresets",
    JSON.stringify(state.groupTripletPresets),
  );
}

function renderTripletPresets() {
  if (!tripletPresetSelect) return;
  tripletPresetSelect.innerHTML = "";
  const opt0 = document.createElement("option");
  opt0.value = "";
  opt0.textContent = "— наборы (3 группы) —";
  tripletPresetSelect.appendChild(opt0);

  for (const p of state.groupTripletPresets) {
    const opt = document.createElement("option");
    opt.value = String(p.id);
    opt.textContent = p.name;
    tripletPresetSelect.appendChild(opt);
  }
}

function openExportBuilderModal() {
  renderExportBundles();
  exportBuilderOverlay?.classList.remove("hidden");
}

function closeExportBuilderModal() {
  exportBuilderOverlay?.classList.add("hidden");
}

function getCheckedGroupIdsFromUi() {
  const out = [];
  for (const rawId of state.selectedGroupIds || []) {
    const id = Number(rawId);
    if (!Number.isFinite(id) || id <= 0) continue;
    if (!out.includes(id)) {
      out.push(id);
    }
  }
  return out;
}

function getGroupLabelById(groupId) {
  const id = Number(groupId);
  const group = state.groups.find((g) => Number(g.id) === id);
  if (!group) return `#${id}`;

  const shortName = String(group.short_name || "").trim();
  const name = String(group.name || "").trim();
  return shortName || name || `#${id}`;
}

function renderExportBundles() {
  if (!exportBundlesList) return;

  if (!state.exportBundles.length) {
    exportBundlesList.classList.add("muted");
    exportBundlesList.textContent = "Наборы для экспорта не добавлены.";
    return;
  }

  exportBundlesList.classList.remove("muted");
  exportBundlesList.innerHTML = "";

  state.exportBundles.forEach((bundle, index) => {
    const row = document.createElement("div");
    row.className = "export-bundle-item";

    const labels = bundle.groupIds.map((id) => getGroupLabelById(id));
    const text = document.createElement("span");
    text.className = "export-bundle-text";
    text.textContent = `#${index + 1} макет x${bundle.templateSize}: ${labels.join(", ")}`;

    const btn = document.createElement("button");
    btn.type = "button";
    btn.className = "export-bundle-remove";
    btn.dataset.exportBundleId = String(bundle.id);
    btn.textContent = "Удалить";

    row.appendChild(text);
    row.appendChild(btn);
    exportBundlesList.appendChild(row);
  });
}

function addExportBundleFromCurrentSelection() {
  const templateSize = Number(exportTemplateSelect?.value || 0);
  if (![1, 2, 3].includes(templateSize)) {
    alert("Сначала выбери макет x1/x2/x3.");
    return;
  }

  const groupIds = getCheckedGroupIdsFromUi();
  if (groupIds.length !== templateSize) {
    alert(
      `Для макета x${templateSize} нужно выбрать ровно ${templateSize} групп(у/ы).`,
    );
    return;
  }

  const usedGroupIds = new Set(
    state.exportBundles.flatMap((bundle) => bundle.groupIds.map((id) => Number(id))),
  );
  const alreadyUsed = groupIds.filter((id) => usedGroupIds.has(id));
  if (alreadyUsed.length) {
    const labels = alreadyUsed.map((id) => getGroupLabelById(id)).join(", ");
    alert(`Эти группы уже добавлены в наборы экспорта: ${labels}`);
    return;
  }

  state.exportBundles.push({
    id: Date.now() + Math.floor(Math.random() * 1000),
    templateSize,
    groupIds,
  });
  renderExportBundles();
}

function buildExportBundlePlan() {
  if (!state.exportBundles.length) return [];

  const out = [];
  for (const bundle of state.exportBundles) {
    const templateSize = Number(bundle.templateSize || 0);
    if (![1, 2, 3].includes(templateSize)) continue;

    const groupIds = (bundle.groupIds || [])
      .map((id) => Number(id))
      .filter((id) => Number.isFinite(id) && id > 0);

    if (groupIds.length !== templateSize) continue;

    out.push({
      template_size: templateSize,
      group_ids: groupIds,
    });
  }

  return out;
}


// init() запускаем с защитой: если сервер недоступен или вернул ошибку —
// выводим её в консоль, но ранее привязанные кнопки (в т.ч. «Справочники»)
// продолжают работать и окно открывается.
init().catch((err) => {
  console.error("Ошибка инициализации приложения:", err);
});
