

import { state } from "../../../app.js";
import { getBellSchedules } from "../modals/bellStore.js";
import { normalizeBellText } from "./bellUtils.js"; // ← новая зависимость
export function formatDateForInput(date) {
  const yyyy = date.getFullYear();
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const dd = String(date.getDate()).padStart(2, "0");
  return `${yyyy}-${mm}-${dd}`;
}
export function getTimeSlots(dayType) {
  const bells = getBellSchedules();
  const slots = bells[dayType] || bells.workday || {};
  // Единый стиль отображения: даже если в базе остались старые строки
  // выходных вида «8:00-9:00» (без перемены), приводим их к виду
  // рабочих дней — «8:00-8:30<br>8:30-9:00».
  const out = {};
  for (const key of Object.keys(slots)) {
    out[key] = normalizeBellText(slots[key]);
  }
  return out;
}

// Число пар определяется настройками «Время пар»: не жёсткие 7/10,
// а реально заданное количество заполненных слотов.
// Раньше для выходных стояла нижняя граница 10 — из-за этого даже когда
// выходные были настроены как рабочие (8:00-8:45<br>8:50-9:30), таблица
// дорисовывала пустые строки. Теперь все типы дней считаются одинаково.
export function getMaxPairs(dayType, timeSlots) {
  const slots = timeSlots || getTimeSlots(dayType);
  const nums = Object.keys(slots)
    .map(Number)
    .filter((n) => Number.isFinite(n) && n >= 1 && slots[n]);
  // хотя бы одна строка на день всегда должна быть
  return Math.max(1, ...nums);
}
export function formatDateForDisplay(date) {
  const dd = String(date.getDate()).padStart(2, "0");
  const mm = String(date.getMonth() + 1).padStart(2, "0");
  const yyyy = date.getFullYear();
  return `${dd}.${mm}.${yyyy}`;
}
export function isFullOffDay(dateOrStr) {
  const dateStr =
    typeof dateOrStr === "string" ? dateOrStr : formatDateForInput(dateOrStr);
  return state.dayMarks[dateStr] === "off";
}
export function getDayType(date) {
  const dateStr = formatDateForInput(date);
  // ВАЖНО: state.holidays должен ВСЕГДА быть массивом строк 'YYYY-MM-DD'
  // (список праздников недели, грузится из БД через api.holidaysRange).
  // Если бэкенд вернул вместо массива объект или null, .includes() упал бы с
  // TypeError («Cannot read properties of undefined (reading '2026-10-02')»).
  // Поэтому нормализуем значение к массиву прямо здесь — таблица отрисзуется
  // корректно даже при «битом» ответе сервера.
  const holidays = Array.isArray(state.holidays)
    ? state.holidays
    : [];
  if (holidays.includes(dateStr)) return "holiday";
  if (date.getDay() === 0) return "sunday";
  return "workday";
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
