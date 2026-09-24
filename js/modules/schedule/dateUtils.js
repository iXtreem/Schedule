
import { state } from "../../../app.js";
import { getBellSchedules } from "../modals/bellStore.js";
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

export function getTimeSlots(dayType) {
  const bells = getBellSchedules();
  return bells[dayType] || bells.workday || {};
}

// Число пар определяется настройками «Время пар»: не жёсткие 7/10,
// а реально заданное количество слотов (но не меньше дефолтных границ).
export function getMaxPairs(dayType, timeSlots) {
  const slots = timeSlots || getTimeSlots(dayType);
  const nums = Object.keys(slots)
    .map(Number)
    .filter((n) => Number.isFinite(n) && n >= 1 && slots[n]);
  const hardMin = dayType === "holiday" ? 10 : 7;
  return Math.max(hardMin, ...nums, 1);
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
