import { state } from "../../../app.js";
import { BELL_SCHEDULES } from "../../LoadFromBD/bd.js";
export function formatDateForInput(date) {
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  return `${yyyy}-${mm}-${dd}`;
}

export function formatDateForDisplay(date) {
  const dd = String(date.getDate()).padStart(2, "0");
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const yyyy = date.getFullYear();
  return `${dd}.${mm}.${yyyy}`;
}

export function getDayType(date) {
  const dateStr = formatDateForInput(date);
  if (state.holidays.includes(dateStr)) return "holiday";
  if (date.getDay() === 0) return "sunday";
  return "workday";
}

export function getMaxPairs(dayType) {
  return dayType === "holiday" ? 10 : 7;
}

export function getTimeSlots(dayType) {
  return BELL_SCHEDULES[dayType] || BELL_SCHEDULES.workday;
}

export function findLesson(weekId, groupId, dayOfWeek, timeSlot) {
  return state.lessons.find(
    (l) =>
      l.weekId === weekId &&
      l.groupId === groupId &&
      l.dayOfWeek === dayOfWeek &&
      l.timeSlot === timeSlot,
  );
}
