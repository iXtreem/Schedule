import { state, scheduleTable } from "../../../app.js";
import {
    formatDateForDisplay,
    formatDateForInput,
    getDayType,
    getMaxPairs,
    getTimeSlots,
    findLesson,
} from "./dateUtils.js";
import { DAY_NAMES } from "../../LoadFromBD/bd.js";

export function renderTable() {
    const selectedIds = state.selectedGroupIds.map(Number);
    let visibleGroupsAll = state.groups.filter((g) =>
        selectedIds.includes(Number(g.id)),
    );

    // если включён фильтр актуальности — отрежем выпускников прямо тут
    if (state.onlyActiveGroups) {
        const currentYear = new Date().getFullYear();
        const minYear = currentYear - 4;
        const getYear = (nm) => {
            const s = (nm || "").trim();
            const m4 = s.match(/\b(20\d{2})\b/);
            if (m4) return Number(m4[1]);
            const m2 = s.match(/[A-Za-zА-Яа-яЁё]+(\d{2})/);
            if (m2) return 2000 + Number(m2[1]);
            return null;
        };

        visibleGroupsAll = visibleGroupsAll.filter((g) => {
            const y = getYear(g.short_name || g.name || "");
            return !y ? true : y >= minYear;
        });
    }

    const eligibleAll = state.onlyActiveGroups ? visibleGroupsAll : state.groups;

    const eligibleIds = eligibleAll.map((g) => Number(g.id));
    const isAllSelected =
        eligibleIds.length > 0 &&
        eligibleIds.every((id) => selectedIds.includes(id));


    const pageSize = state.groupPageSize || 3;
    const pageIndex = state.groupPageIndex || 0;

    let visibleGroups = visibleGroupsAll;

    if (!isAllSelected) {
        const start = pageIndex * pageSize;
        visibleGroups = visibleGroupsAll.slice(start, start + pageSize);
    }

    if (!visibleGroups.length) {
        scheduleTable.innerHTML = `<tr><td class="muted">Выбери хотя бы одну группу</td></tr>`;
        return;
    }

    //Заголовок
    let html = `
    <thead>
        <tr>
            <th>День</th>
            <th>№</th>
            <th class="time-cell">Время</th>
            ${visibleGroups
                .map((g) => `<th>${g.name}</th><th>Ауд.</th>`)
                .join("")}
        </tr>
    </thead>
    <tbody>
    `;

    for (let dayIndex = 0; dayIndex < 7; dayIndex++) {
        const date = new Date(state.weekStart);
        date.setDate(date.getDate() + dayIndex);

        const dayType = getDayType(date);
        const maxPairs = getMaxPairs(dayType);
        const timeSlots = getTimeSlots(dayType);

        const dateStr = formatDateForInput(date);
        const isHoliday = state.holidays.includes(dateStr);

        for (let pairIndex = 0; pairIndex < maxPairs; pairIndex++) {
            const dayOfWeek = dayIndex + 1;
            const timeSlot = pairIndex + 1;

            html += "<tr>";

            if (pairIndex === 0) {
                html += `
                <td class="day-cell" rowspan="${maxPairs}" data-date="${dateStr}">
                    <div><b>${DAY_NAMES[dayIndex]}</b></div>
                    <div class="muted">${formatDateForDisplay(date)}</div>

                    <label class="switch-row">
                    <span class="muted">Праздник</span>
                    <input class="holiday-toggle" type="checkbox" ${
                        isHoliday ? "checked" : ""
                    }>
                    <span class="switch"></span>
                    </label>

                    <div class="muted">Тип: ${dayType}</div>
                </td>
                `;
            }

            html += `<td>${timeSlot}</td>`;
            html += `<td class="time-cell">${timeSlots[timeSlot] || "-"}</td>`;

            for (const g of visibleGroups) {
                const lesson = findLesson(
                    state.currentWeekId,
                    g.id,
                    dayOfWeek,
                    timeSlot,
                );

                const lessonText = lesson
                    ? (() => {
                          const customText = String(
                              lesson.customText ?? "",
                          ).trim();
                          if (customText) {
                              return `
                                <div class="lesson-cell">
                                <div class="lesson-custom-text">${escapeHtml(customText)}</div>
                                </div>
                                `;
                          }

                          const subjectName = getName(
                              state.subjects,
                              lesson.subjectId,
                          );
                          const teacherFull = getName(
                              state.teachers,
                              lesson.teacherId,
                          );
                          const teacherShort = shortTeacherName(teacherFull);

                          const typeName = getTypeNameById(lesson.typeId);
                          const typeClass = isBoldUnderlinedType(typeName)
                              ? "lesson-type lesson-type--strong"
                              : "lesson-type";

                          const hourSuffix =
                              Number(lesson.hours) === 1
                                  ? ' <span class="lesson-hours">(1 час)</span>'
                                  : "";

                          return `
                                <div class="lesson-cell">
                                <div class="lesson-subject">${subjectName}${hourSuffix}</div>
                                <div class="${typeClass}">${typeName || ""}</div>
                                <div class="lesson-teacher muted">${teacherShort}</div>
                                </div>
                                `;
                      })()
                    : `<span class="muted">—</span>`;

                const roomText = lesson
                    ? getName(state.rooms, lesson.roomId)
                    : "&nbsp;";

                html += `
                <td class="clickable"
                    data-role="lesson-cell"
                    data-group-id="${g.id}"
                    data-day-index="${dayIndex}"
                    data-pair-index="${pairIndex}"
                    ${lesson ? `data-lesson-id="${lesson.id}"` : ""}>
                    ${lessonText}
                </td>
                <td>${roomText}</td>
                `;
            }
            html += "</tr>";
        }
    }

    html += "</tbody>";
    scheduleTable.innerHTML = html;

    if (visibleGroupsAll.length > 0 && visibleGroups.length === 0) {
        state.groupPageIndex = 0;
        renderTable();
    }
}

function shortTeacherName(fullName) {
    const s = (fullName || "").trim().replace(/\s+/g, " ");
    const parts = s.split(" ");
    if (parts.length === 0) return "";

    const surname = parts[0];
    const n1 = parts[1]?.[0] ? parts[1][0].toUpperCase() + "." : "";
    const n2 = parts[2]?.[0] ? parts[2][0].toUpperCase() + "." : "";

    return `${surname} ${n1}${n2}`.trim();
}

function isBoldUnderlinedType(typeName) {
    const t = (typeName || "").trim().toLowerCase();
    return (
        t === "диф.зачет" ||
        t === "диф. зачёт" ||
        t === "диф.зачёт" ||
        t === "зачет" ||
        t === "зачёт" ||
        t === "защита курсовых работ"
    );
}

function getTypeNameById(typeId) {
    const t = state.lessonTypes.find((x) => Number(x.id) === Number(typeId));
    return t?.name || t?.TimeTypeName || "";
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function getName(list, id) {
    const item = list.find((x) => x.id === id);
    return item ? item.name : "—";
}
