
import { state, scheduleTable } from "../../../app.js";
import { DAY_NAMES } from "../../LoadFromBD/bd.js";
import {
  formatDateForDisplay,
  formatDateForInput,
  getDayType,
  isFullOffDay,
  getMaxPairs,
  getTimeSlots,
} from "./dateUtils.js";

export function renderTeacherTable() {
  if (!state.weekStart || !state.currentWeekId) {
    scheduleTable.innerHTML = `<tr><td class="muted">Нет выбранной недели</td></tr>`;
    return;
  }

  const searchTerm = (state.teacherSearchTerm || "").trim().toLowerCase();

  const visibleTeachers = [...(state.teachers || [])]
    .filter((teacher) => {
      const name = String(teacher?.name || "").toLowerCase();
      return searchTerm ? name.includes(searchTerm) : true;
    })
    .sort((a, b) =>
      String(a?.name || "").localeCompare(String(b?.name || ""), "ru"),
    );

  if (!visibleTeachers.length) {
    scheduleTable.innerHTML = `<tr><td class="muted">Преподаватели не найдены</td></tr>`;
    return;
  }

  const subjectMap = toNameMap(state.subjects);
  const groupMap = toGroupMap(state.groups);
  const roomMap = toNameMap(state.rooms);
  const typeMap = toNameMap(state.lessonTypes);
  const lessonsBySlot = indexLessonsByTeacherSlot();

  let html = `
    <thead>
      <tr>
        <th>День</th>
        <th>№</th>
        <th class="time-cell">Время</th>
        ${visibleTeachers
          .map(
            (teacher) =>
              `<th class="teacher-col-head" title="${escapeHtml(String(teacher.name || ""))}">${escapeHtml(String(teacher.name || ""))}</th>`,
          )
          .join("")}
      </tr>
    </thead>
    <tbody>
  `;

  for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
    const date = new Date(state.weekStart);
    date.setDate(date.getDate() + dayIndex);

    // Красный день («Выходные дни») — полный выходной, исключаем из таблицы.
    if (isFullOffDay(date)) continue;

    const dayType = getDayType(date);
    const maxPairs = getMaxPairs(dayType);
    const timeSlots = getTimeSlots(dayType);
    const dateStr = formatDateForInput(date);

    for (let pairIndex = 0; pairIndex < maxPairs; pairIndex++) {
      const dayOfWeek = dayIndex + 1;
      const timeSlot = pairIndex + 1;

      html += "<tr>";

      if (pairIndex === 0) {
        html += `
          <td class="day-cell day-cell--teacher" rowspan="${maxPairs}" data-date="${dateStr}">
            <div><b>${DAY_NAMES[dayIndex]}</b></div>
            <div class="muted">${formatDateForDisplay(date)}</div>
            <div class="muted">Тип: ${dayType}</div>
          </td>
        `;
      }

      html += `<td>${timeSlot}</td>`;
      html += `<td class="time-cell">${timeSlots[timeSlot] || "-"}</td>`;

      for (const teacher of visibleTeachers) {
        const lesson = lessonsBySlot.get(
          slotKey(Number(teacher.id), dayOfWeek, timeSlot),
        );
        html += `<td class="teacher-cell">${renderTeacherLessonCell(lesson, subjectMap, groupMap, roomMap, typeMap)}</td>`;
      }

      html += "</tr>";
    }
  }

  html += "</tbody>";
  scheduleTable.innerHTML = html;
}

function indexLessonsByTeacherSlot() {
  const map = new Map();
  const currentWeekId = Number(state.currentWeekId);

  for (const lesson of state.lessons || []) {
    if (Number(lesson.weekId) !== currentWeekId) continue;

    const teacherId = Number(lesson.teacherId);
    if (!teacherId) continue;

    const key = slotKey(teacherId, lesson.dayOfWeek, lesson.timeSlot);
    if (!map.has(key)) {
      map.set(key, lesson);
    }
  }

  return map;
}

function slotKey(teacherId, dayOfWeek, timeSlot) {
  return `${Number(teacherId)}|${Number(dayOfWeek)}|${Number(timeSlot)}`;
}

function renderTeacherLessonCell(lesson, subjectMap, groupMap, roomMap, typeMap) {
  if (!lesson) return `<span class="muted">—</span>`;

  const customText = String(lesson.customText ?? "").trim();
  if (customText) {
    return `
      <div class="lesson-cell teacher-lesson">
        <div class="lesson-custom-text">${escapeHtml(customText)}</div>
      </div>
    `;
  }

  const subjectName = getName(subjectMap, lesson.subjectId);
  const groupName = getName(groupMap, lesson.groupId);
  const roomName = getName(roomMap, lesson.roomId);
  const typeName = getName(typeMap, lesson.typeId, "");

  const hourSuffix =
    Number(lesson.hours) === 1 ? ' <span class="lesson-hours">(1 час)</span>' : "";

  return `
    <div class="lesson-cell teacher-lesson">
      <div class="lesson-subject">${escapeHtml(subjectName)}${hourSuffix}</div>
      <div class="teacher-lesson-meta">${escapeHtml(groupName)} · ${escapeHtml(roomName)}</div>
      ${typeName ? `<div class="teacher-lesson-type muted">${escapeHtml(typeName)}</div>` : ""}
    </div>
  `;
}

function toNameMap(items) {
  const map = new Map();
  for (const item of items || []) {
    const id = Number(item?.id);
    if (!id) continue;
    map.set(id, item?.name ?? item?.short_name ?? "");
  }
  return map;
}

function toGroupMap(items) {
  const map = new Map();
  for (const item of items || []) {
    const id = Number(item?.id);
    if (!id) continue;
    map.set(id, item?.short_name ?? item?.name ?? "");
  }
  return map;
}

function getName(map, id, fallback = "—") {
  const value = map.get(Number(id));
  return value == null || String(value).trim() === "" ? fallback : String(value);
}

function escapeHtml(value) {
  return String(value)
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}
