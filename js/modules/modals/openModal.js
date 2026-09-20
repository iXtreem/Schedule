import { state } from "../../../app.js";
import { api } from "../../LoadFromBD/api.js";
import { DAY_NAMES } from "../../LoadFromBD/bd.js";
import { renderTable } from "../schedule/renderTable.js";
import { filterByName } from "./searchModal.js";

const modalOverlay = document.getElementById("modalOverlay");
const modalClose = document.getElementById("modalClose");
const modalTitle = document.getElementById("modalTitle");
const modalSubtitle = document.getElementById("modalSubtitle");

const subjectSelect = document.getElementById("subjectSelect");
const teacherSelect = document.getElementById("teacherSelect");
const typeSelect = document.getElementById("typeSelect");
const roomSelect = document.getElementById("roomSelect");
const hoursSelect = document.getElementById("hoursSelect");
const customTextInput = document.getElementById("customTextInput");

const saveLessonBtn = document.getElementById("saveLessonBtn");
const deleteLessonBtn = document.getElementById("deleteLessonBtn");

const termInput = document.getElementById("termInput");
if (termInput) {
  termInput.readOnly = true;
  termInput.disabled = true;
}

const subjectSearch = document.getElementById("subjectSearch");
const teacherSearch = document.getElementById("teacherSearch");
const typeSearch = document.getElementById("typeSearch");
const roomSearch = document.getElementById("roomSearch");

const resetModalBtn = document.getElementById("resetModalBtn");

let baseSubjects = [];
let baseTeachers = [];

let modalSubjects = [];
let modalTeachers = [];
let modalTypes = [];
let modalRoomPrefs = [];
let modalPreferredRoomIds = new Set();
let modalPrimaryRoomIds = new Set();

let modalTeachersBase = [];
let modalSubjectsBase = [];

let isProgrammaticChange = false;

function setValueSilent(selectEl, value) {
  isProgrammaticChange = true;
  selectEl.value = value;
  isProgrammaticChange = false;
}

function extractGroupStartYear(group) {
  const fromDb = Number(group?.year);
  if (Number.isFinite(fromDb) && fromDb > 0) return fromDb;

  const raw = String(group?.short_name || group?.name || "").trim();
  const m4 = raw.match(/\b(20\d{2})\b/);
  if (m4) return Number(m4[1]);

  const m2 = raw.match(/[A-Za-zА-Яа-яЁё]+[-\s]?(\d{2})\b/);
  if (m2) return 2000 + Number(m2[1]);

  return null;
}

function calculateAutoTerm(groupId) {
  const group = state.groups.find((g) => Number(g.id) === Number(groupId));
  const groupYear = extractGroupStartYear(group);
  if (!groupYear) return 1;

  const dt = state.weekStart ? new Date(state.weekStart) : new Date();
  if (!Number.isFinite(dt.getTime())) return 1;

  const year = dt.getFullYear();
  const month = dt.getMonth() + 1;
  let term = 1;

  if (month >= 9) {
    const k = year - groupYear;
    term = k * 2 + 1;
  } else if (month >= 2 && month <= 6) {
    const k = year - groupYear - 1;
    term = k * 2 + 2;
  } else if (month === 1) {
    const k = year - groupYear - 1;
    term = k * 2 + 1;
  } else {
    const k = year - groupYear;
    term = k * 2 + 1;
  }

  if (!Number.isFinite(term)) return 1;
  return Math.max(1, Math.min(12, term));
}

function applyAutoTerm(groupId) {
  const term = calculateAutoTerm(groupId);
  if (termInput) termInput.value = String(term);
  return term;
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

function refillSelectKeepingValue(selectEl, list) {
  const currentValue = selectEl.value;
  fillSelect(selectEl, list);

  if ([...selectEl.options].some((opt) => opt.value === currentValue)) {
    selectEl.value = currentValue;
  }
}

function normalizeRoomPrefsRows(rows) {
  const out = [];
  for (const row of rows || []) {
    const roomId = Number(row?.roomId ?? row?.room_id ?? row?.idRoom ?? 0);
    if (!Number.isFinite(roomId) || roomId <= 0) continue;

    const priorityRaw = Number(row?.priority ?? 100);
    const priority = Number.isFinite(priorityRaw) ? priorityRaw : 100;
    const isPrimary =
      Number(row?.isPrimary ?? row?.is_primary ?? 0) === 1 ? 1 : 0;

    out.push({ roomId, priority, isPrimary });
  }
  return out;
}

function rebuildRoomPreferenceSets(rows) {
  modalPreferredRoomIds = new Set();
  modalPrimaryRoomIds = new Set();

  for (const row of rows || []) {
    const roomId = Number(row.roomId);
    if (!Number.isFinite(roomId) || roomId <= 0) continue;
    modalPreferredRoomIds.add(roomId);
    if (Number(row.isPrimary) === 1) modalPrimaryRoomIds.add(roomId);
  }
}

function roomHasPreference(roomId) {
  return modalPreferredRoomIds.has(Number(roomId));
}

function roomIsPrimaryPreference(roomId) {
  return modalPrimaryRoomIds.has(Number(roomId));
}

function syncRoomSelectHighlight() {
  if (!roomSelect) return;
  const selectedId = Number(roomSelect.value || 0);
  const preferred = roomHasPreference(selectedId);
  const primary = roomIsPrimaryPreference(selectedId);

  roomSelect.classList.toggle("room-select-pref", preferred);
  roomSelect.classList.toggle("room-select-pref-primary", primary);
}

function fillRoomSelectWithPrefs(
  roomList,
  placeholder = "— выбери аудиторию —",
) {
  if (!roomSelect) return;

  const currentValue = roomSelect.value;
  const filtered = filterByName(roomList || [], roomSearch?.value || "");

  const sorted = [...filtered].sort((a, b) => {
    const aPrimary = roomIsPrimaryPreference(a.id) ? 1 : 0;
    const bPrimary = roomIsPrimaryPreference(b.id) ? 1 : 0;
    if (aPrimary !== bPrimary) return bPrimary - aPrimary;

    const aPref = roomHasPreference(a.id) ? 1 : 0;
    const bPref = roomHasPreference(b.id) ? 1 : 0;
    if (aPref !== bPref) return bPref - aPref;

    return String(a?.name || "").localeCompare(String(b?.name || ""), "ru");
  });

  roomSelect.innerHTML = "";
  const empty = document.createElement("option");
  empty.value = "";
  empty.textContent = placeholder;
  roomSelect.appendChild(empty);

  for (const room of sorted) {
    const id = Number(room.id);
    const preferred = roomHasPreference(id);
    const primary = roomIsPrimaryPreference(id);

    const opt = document.createElement("option");
    opt.value = String(id);
    if (primary) opt.textContent = `${room.name} (основная)`;
    else if (preferred) opt.textContent = `${room.name} (рекомендуемая)`;
    else opt.textContent = room.name;

    if (preferred) {
      opt.style.color = "#166534";
      opt.style.backgroundColor = primary ? "#dcfce7" : "#f0fdf4";
    }
    roomSelect.appendChild(opt);
  }

  if ([...roomSelect.options].some((x) => x.value === currentValue)) {
    roomSelect.value = currentValue;
  }

  syncRoomSelectHighlight();
}

async function loadRoomPrefsForSelectedSubject() {
  const subjectId = Number(subjectSelect?.value || 0);

  if (!subjectId) {
    modalRoomPrefs = [];
    rebuildRoomPreferenceSets(modalRoomPrefs);
    fillRoomSelectWithPrefs(state.rooms);
    return;
  }

  try {
    modalRoomPrefs = normalizeRoomPrefsRows(await api.planRoomPrefs(subjectId));
  } catch (e) {
    console.error("Failed to load room preferences:", e);
    modalRoomPrefs = [];
  }
  rebuildRoomPreferenceSets(modalRoomPrefs);
  fillRoomSelectWithPrefs(state.rooms);
}

let modalSearchBound = false;

function bindModalSearch() {
  if (modalSearchBound) return;
  modalSearchBound = true;

  if (subjectSearch && subjectSelect) {
    subjectSearch.addEventListener("input", () => {
      const filtered = filterByName(modalSubjects, subjectSearch.value);
      refillSelectKeepingValue(subjectSelect, filtered);
    });
  }

  if (teacherSearch && teacherSelect) {
    teacherSearch.addEventListener("input", () => {
      const filtered = filterByName(modalTeachers, teacherSearch.value);
      refillSelectKeepingValue(teacherSelect, filtered);
    });
  }

  if (typeSearch && typeSelect) {
    typeSearch.addEventListener("input", () => {
      const filtered = filterByName(modalTypes, typeSearch.value);
      refillSelectKeepingValue(typeSelect, filtered);
    });
  }

  if (roomSearch && roomSelect) {
    roomSearch.addEventListener("input", () => {
      fillRoomSelectWithPrefs(state.rooms);
    });

    roomSelect.addEventListener("change", () => {
      syncRoomSelectHighlight();
    });
  }
}

export function fillSelect(selectEl, list, placeholder = "— выбери —") {
  selectEl.innerHTML = "";

  const empty = document.createElement("option");
  empty.value = "";
  empty.textContent = placeholder;
  selectEl.appendChild(empty);

  for (const item of list || []) {
    const opt = document.createElement("option");
    opt.value = String(item.id);
    opt.textContent = item.name;
    selectEl.appendChild(opt);
  }
}

async function loadPlanSubjectsForModal(groupId) {
  const term = Number(termInput.value) || 1;

  modalSubjects = await api.planSubjects(groupId, term);
  fillSelect(subjectSelect, modalSubjects);
}

async function loadPlanTeachersForModal(groupId) {
  const term = Number(termInput.value) || 1;
  const subjectId = Number(subjectSelect.value);

  teacherSelect.innerHTML = "";
  typeSelect.innerHTML = "";
  modalTeachers = [];
  modalTypes = [];

  if (!subjectId) return;

  modalTeachers = await api.planTeachers(groupId, term, subjectId);
  fillSelect(teacherSelect, modalTeachers);
}

async function loadPlanTypesForModal(groupId) {
  const term = Number(termInput.value) || 1;
  const subjectId = Number(subjectSelect.value);
  const teacherId = Number(teacherSelect.value);

  typeSelect.innerHTML = "";
  modalTypes = [];

  if (!subjectId || !teacherId) return;

  modalTypes = await api.planLessonTypes(groupId, term, subjectId, teacherId);
  fillSelect(typeSelect, modalTypes);
}

async function reloadWeekLessons() {
  const raw = await api.scheduleForWeek(state.currentWeekId);
  state.lessons = normalizeLessons(raw);
}

export async function openModal({ groupId, dayIndex, pairIndex, lessonId }) {
  bindModalSearch();
  state.currentEdit = { groupId, dayIndex, pairIndex, lessonId };

  applyAutoTerm(groupId);

  const group = state.groups.find((g) => Number(g.id) === Number(groupId));
  modalTitle.textContent = "Редактирование занятия";
  modalSubtitle.textContent = `${group?.name ?? "Группа"}, ${DAY_NAMES[dayIndex]}, пара ${pairIndex + 1}`;

  // сброс поисков
  if (subjectSearch) subjectSearch.value = "";
  if (teacherSearch) teacherSearch.value = "";
  if (typeSearch) typeSearch.value = "";
  if (roomSearch) roomSearch.value = "";
  if (customTextInput) customTextInput.value = "";

  // стартовый список до выбора дисциплины
  modalRoomPrefs = [];
  rebuildRoomPreferenceSets(modalRoomPrefs);
  fillRoomSelectWithPrefs(state.rooms);

  // блокируем кнопки пока модалка не готова
  saveLessonBtn.disabled = true;
  deleteLessonBtn.disabled = true;

  await loadModalBaseLists(groupId);
  await loadRoomPrefsForSelectedSubject();
  if (hoursSelect) hoursSelect.value = "2";

  if (lessonId) {
    const lesson = state.lessons.find((l) => Number(l.id) === Number(lessonId));
    if (lesson) {
      setValueSilent(subjectSelect, String(lesson.subjectId));
      await loadRoomPrefsForSelectedSubject();
      if (hoursSelect) setValueSilent(hoursSelect, String(lesson.hours ?? 2));

      // подгрузим преподавателей под этот предмет
      const term = Number(termInput.value) || 1;
      modalTeachers = await api.planTeachers(
        groupId,
        term,
        Number(lesson.subjectId),
      );
      fillSelect(teacherSelect, modalTeachers, "— выбери преподавателя —");
      setValueSilent(teacherSelect, String(lesson.teacherId));

      // подгрузим типы под subject+teacher
      await tryLoadTypes(groupId);
      setValueSilent(typeSelect, String(lesson.typeId));

      // аудитория
      setValueSilent(roomSelect, String(lesson.roomId));
      syncRoomSelectHighlight();
      if (customTextInput) {
        customTextInput.value = lesson.customText || "";
      }

      deleteLessonBtn.disabled = false;
    }
  }

  saveLessonBtn.disabled = false;

  modalOverlay.classList.remove("hidden");
}

export function closeModal() {
  modalOverlay.classList.add("hidden");
}

async function loadModalBaseLists(groupId) {
  const term = Number(termInput.value) || 1;

  baseSubjects = await api.planSubjects(groupId, term);
  baseTeachers = await api.planTeachersBase(groupId, term);

  modalSubjectsBase = [...baseSubjects];
  modalTeachersBase = [...baseTeachers];

  modalSubjects = [...baseSubjects];
  modalTeachers = [...baseTeachers];
  modalTypes = [];

  fillSelect(subjectSelect, modalSubjects, "— выбери дисциплину —");
  fillSelect(teacherSelect, modalTeachers, "— выбери преподавателя —");
  fillSelect(typeSelect, [], "— выбери тип —");
}

function validateLessonForm() {
  const errors = [];

  const hasCustomText = Boolean(customTextInput?.value?.trim());
  const values = [
    subjectSelect.value,
    teacherSelect.value,
    typeSelect.value,
    roomSelect.value,
  ];
  const hasAnyAcademicField = values.some(Boolean);
  const hasAllAcademicFields = values.every(Boolean);

  if (hasCustomText && !hasAnyAcademicField) return errors;

  if (
    (hasCustomText && hasAnyAcademicField && !hasAllAcademicFields) ||
    (!hasCustomText && !hasAllAcademicFields)
  ) {
    if (!subjectSelect.value) errors.push("Выбери дисциплину");
    if (!teacherSelect.value) errors.push("Выбери преподавателя");
    if (!typeSelect.value) errors.push("Выбери тип занятия");
    if (!roomSelect.value) errors.push("Выбери аудиторию");
  }

  return errors;
}

export async function saveLesson() {
  const errors = validateLessonForm();
  if (errors.length) {
    alert("Нельзя сохранить занятие:\n\n• " + errors.join("\n• "));
    return;
  }

  const { groupId, dayIndex, pairIndex, lessonId } = state.currentEdit;
  const parseNullableId = (value) => {
    const n = Number(value);
    return Number.isFinite(n) && n > 0 ? n : null;
  };

  const payload = {
    hours: Number(hoursSelect?.value || 2),
    customText: customTextInput?.value?.trim() || "",
    term: Number(termInput.value) || 1,
    weekId: state.currentWeekId,
    groupId,
    dayOfWeek: dayIndex + 1,
    timeSlot: pairIndex + 1,
    subjectId: parseNullableId(subjectSelect.value),
    teacherId: parseNullableId(teacherSelect.value),
    typeId: parseNullableId(typeSelect.value),
    roomId: parseNullableId(roomSelect.value),
  };

  try {
    if (lessonId) await api.updateLesson(lessonId, payload);
    else await api.createLesson(payload);

    await reloadWeekLessons();
    renderTable();
    closeModal();
  } catch (e) {
    console.error(e);
    alert(e?.message || "Ошибка сохранения занятия или превышение часов");
  }
}

function formatHours(v) {
  const n = Number(v ?? 0);
  // показываем 0.25 / 0.5 / 1.5 нормально
  return (Math.round(n * 100) / 100).toString();
}

async function tryLoadTypes(groupId) {
  const term = Number(termInput.value) || 1;
  const subjectId = Number(subjectSelect.value);
  const teacherId = Number(teacherSelect.value);

  if (!subjectId || !teacherId) {
    fillSelect(typeSelect, [], "— выбери тип —");
    modalTypes = [];
    return;
  }

  modalTypes = await api.planLessonTypes(groupId, term, subjectId, teacherId);

  const typesForSelect = modalTypes.map((t) => {
    const done = formatHours(t.done_hours);
    const planned = formatHours(t.planned_hours);
    const remaining = Number(t.planned_hours ?? 0) - Number(t.done_hours ?? 0);

    return {
      id: t.id,
      name: `${t.name} (${done}/${planned})`,
      _remaining: remaining,
    };
  });

  fillSelect(typeSelect, typesForSelect, "— выбери тип —");

  //запретим выбирать то, где остаток <= 0
  for (const opt of typeSelect.options) {
    if (!opt.value) continue;
    const t = typesForSelect.find((x) => String(x.id) === String(opt.value));
    if (t && t._remaining <= 0.0001) opt.disabled = true;
  }
}

export async function deleteLesson() {
  const { lessonId } = state.currentEdit;
  if (!lessonId) return;
  if (!confirm("Удалить занятие?")) return;

  try {
    await api.deleteLesson(lessonId);
    await reloadWeekLessons();
    renderTable();
    closeModal();
  } catch (e) {
    alert("Ошибка удаления занятия. Смотри консоль.");
    console.error(e);
  }
}

subjectSelect.addEventListener("change", async () => {
  if (isProgrammaticChange) return;

  const groupId = state.currentEdit?.groupId;
  if (!groupId) return;

  const term = Number(termInput.value) || 1;
  const subjectId = Number(subjectSelect.value);

  // типы всегда сбрасываем
  fillSelect(typeSelect, [], "— выбери тип —");
  modalTypes = [];
  await loadRoomPrefsForSelectedSubject();

  // если предмет сняли возвращаем базовых преподавателей
  if (!subjectId) {
    fillSelect(teacherSelect, modalTeachersBase, "— выбери преподавателя —");
    // сохраняем выбранного преподавателя, если он ещё есть в базе (обычно да)
    const prevTeacher = teacherSelect.value;
    if (
      prevTeacher &&
      [...teacherSelect.options].some((o) => o.value === prevTeacher)
    ) {
      setValueSilent(teacherSelect, prevTeacher);
    } else {
      setValueSilent(teacherSelect, "");
    }
    await tryLoadTypes(groupId);
    return;
  }

  // предмет выбран  подгружаем преподавателей по предмету
  const prevTeacher = teacherSelect.value;

  modalTeachers = await api.planTeachers(groupId, term, subjectId);
  fillSelect(teacherSelect, modalTeachers, "— выбери преподавателя —");

  if (
    prevTeacher &&
    [...teacherSelect.options].some((o) => o.value === prevTeacher)
  ) {
    setValueSilent(teacherSelect, prevTeacher);
  } else {
    setValueSilent(teacherSelect, "");
  }

  await tryLoadTypes(groupId);
});

teacherSelect.addEventListener("change", async () => {
  if (isProgrammaticChange) return;

  const groupId = state.currentEdit?.groupId;
  if (!groupId) return;

  const term = Number(termInput.value) || 1;
  const teacherId = Number(teacherSelect.value);

  // типы всегда сбрасываем
  fillSelect(typeSelect, [], "— выбери тип —");
  modalTypes = [];

  // если преподавателя сняли  возвращаем базовые дисциплины
  if (!teacherId) {
    fillSelect(subjectSelect, modalSubjectsBase, "— выбери дисциплину —");

    const prevSubject = subjectSelect.value;
    if (
      prevSubject &&
      [...subjectSelect.options].some((o) => o.value === prevSubject)
    ) {
      setValueSilent(subjectSelect, prevSubject);
    } else {
      setValueSilent(subjectSelect, "");
    }

    await tryLoadTypes(groupId);
    return;
  }

  // преподаватель выбран  подгружаем дисциплины по преподавателю
  const prevSubject = subjectSelect.value;

  modalSubjects = await api.planSubjectsByTeacher(groupId, term, teacherId);
  fillSelect(subjectSelect, modalSubjects, "— выбери дисциплину —");

  //если прежний предмет возможен — сохраняем, иначе очищаем
  if (
    prevSubject &&
    [...subjectSelect.options].some((o) => o.value === prevSubject)
  ) {
    setValueSilent(subjectSelect, prevSubject);
  } else {
    setValueSilent(subjectSelect, "");
  }

  await tryLoadTypes(groupId);
});

resetModalBtn.addEventListener("click", async () => {
  const groupId = state.currentEdit?.groupId;
  if (!groupId) return;

  // очистим поиски
  subjectSearch.value = "";
  teacherSearch.value = "";
  typeSearch.value = "";
  roomSearch.value = "";
  if (customTextInput) customTextInput.value = "";

  // вернём базовые списки
  await loadModalBaseLists(groupId);
  await loadRoomPrefsForSelectedSubject();
});


modalClose.addEventListener("click", closeModal);
saveLessonBtn.addEventListener("click", saveLesson);
deleteLessonBtn.addEventListener("click", deleteLesson);
