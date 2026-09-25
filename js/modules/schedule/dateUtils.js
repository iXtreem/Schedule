
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
  // ВАЖНО: в state нет поля dayMarks (его никогда не инициализировали),
  // поэтому state.dayMarks[dateStr] падало с TypeError
  // «Cannot read properties of undefined (reading '2026-12-28')».
  // Нормализуем значение к объекту — при отсутствии данных день считается рабочим.
  const dayMarks = state.dayMarks || {};
  return dayMarks[dateStr] === "off";
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
// Соответствие «индекс строки таблицы (0..6) → день недели по дате».
// Раньше таблица всегда выводила полную неделю: 7 строк подряд от даты
// начала недели, и подписи дней (DAY_NAMES[dayIndex]) были привязаны к
// позиции строки, а не к реальной дате. Если неделя начиналась не с
// понедельника (например, «Понедельник» попадал на дату вторника),
// расписание сбивалось: занятия искали не в тот день.
// Теперь считаем фактический день недели из даты: slot = 0 для Пн … 6 для Вс.
// Если дата вообще не попадает ни в один день недели (защитный случай) —
// возвращаем null, и строка такого дня в таблицу не выводится.
export function getDaySlotFromDate(date) {
  if (!(date instanceof Date) || !Number.isFinite(date.getTime())) return null;
  const dow = date.getDay(); // 0 = Вс, 1 = Пн, ..., 6 = Сб
  return dow === 0 ? 6 : dow - 1;
}

// Границы текущей недели: понедельник (weekStart) и воскресенье (weekEnd).
// state.weekEnd заполняется в app.js из end_date недели; если бэкенд его
// не вернул — считаем воскресеньём дату «понедельник + 6 дней».
export function getWeekBounds() {
  const start = new Date(state.weekStart);
  if (!Number.isFinite(start.getTime())) return null;
  const end =
    state.weekEnd && Number.isFinite(new Date(state.weekEnd).getTime())
      ? new Date(state.weekEnd)
      : new Date(start.getTime() + 6 * 86400000);
  return { start, end };
}

// Принадлежит ли дата текущей неделе (от понедельника weekStart до
// воскресенья weekEnd включительно). Нужно потому, что таблица перебирает
// 7 позиций от даты начала недели; когда неделя начинается не с понедельника
// (например, срезанная первая/последняя неделя семестра), после воскресенья
// в переборе идёт понедельник СЛЕДУЮЩЕЙ недели — его добавляем в таблицу,
// т.к. это уже другая неделя. Дата вне диапазона -> false -> строка скрывается.
export function isDateInCurrentWeek(date) {
  const bounds = getWeekBounds();
  if (!bounds) return false;
  if (!(date instanceof Date) || !Number.isFinite(date.getTime())) return false;
  const t = date.getTime();
  return t >= bounds.start.getTime() && t <= bounds.end.getTime();
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
