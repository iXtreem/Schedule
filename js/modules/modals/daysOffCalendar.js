
// Модуль вкладки «Выходные дни» окна «Справочники».
// Показывает календарь на месяц. Клик по дню переключает состояние по циклу:
//   обычный -> жёлтый (сокращённый день: пары идут по «праздничному» времени)
//   жёлтый  -> красный (полный выходной: день убирается из таблиц расписания)
//   красный -> обычный (отметка снимается).
// Данные хранятся в той же таблице TB_Holidays, что и переключатель
// «Праздник» у дня недели в таблице расписания (см. renderTable.js / app.js).
import { api } from "../../LoadFromBD/api.js";

const DAY_NAMES_SHORT = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];
const MONTH_NAMES = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

let rootEl = null;        // контейнер календаря (таблица #dictTable)
let loaded = false;       // загружались ли данные хоть раз
let bound = false;        // навешивали ли обработчики

let viewYear = new Date().getFullYear();   // отображаемый год
let viewMonth = new Date().getMonth();     // отображаемый месяц (0..11)
// Отмеченные даты: { "YYYY-MM-DD": "reduced" | "off" }
// reduced — жёлтый (сокращённые пары), off — красный (полный выходной).
let dayMarks = {};

// ---- Служебные функции ----

// Приводит дату объекта Date к строке "YYYY-MM-DD" без смещения часовых поясов.
function toDateStr(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

// Границы текущего месяца для запроса к бэкенду.
function monthRange() {
  const start = new Date(viewYear, viewMonth, 1);
  const end = new Date(viewYear, viewMonth + 1, 0);
  return { start: toDateStr(start), end: toDateStr(end) };
}

// Загрузка отмеченных дней за отображаемый месяц из базы данных.
// Бэкенд возвращает список [{ date, kind }] либо (старая версия) список строк дат.
async function loadHolidays() {
  const { start, end } = monthRange();
  try {
    const list = await api.holidaysRange(start, end);
    dayMarks = {};
    for (const item of list || []) {
      if (typeof item === "string") {
        dayMarks[item] = "off";            // обратная совместимость со старым форматом
      } else if (item && item.date) {
        dayMarks[item.date] = item.kind === "reduced" ? "reduced" : "off";
      }
    }
  } catch (e) {
    alert(`Не удалось загрузить выходные дни: ${e.message || e}`);
  }
}

// Пересчёт границ месяца и перезагрузка данных при смене месяца/года.
async function refreshMonth() {
  await loadHolidays();
  renderCalendar();
}

// ---- Отрисовка ----

function renderCalendar() {
  if (!rootEl) return;

  const first = new Date(viewYear, viewMonth, 1);
  // getDay(): 0 = воскресенье. Перестраиваем неделю под российский формат (Пн..Вс).
  const leading = (first.getDay() + 6) % 7; // сколько пустых клеток до 1-го числа
  const daysInMonth = new Date(viewYear, viewMonth + 1, 0).getDate();
  const todayStr = toDateStr(new Date());

  // Кнопки навигации по месяцам + легенда
  let html = `
    <div class="cal-toolbar">
      <button type="button" class="icon-btn" data-cal-nav="-1" title="Предыдущий месяц">◀</button>
      <span class="cal-title">${MONTH_NAMES[viewMonth]} ${viewYear}</span>
      <button type="button" class="icon-btn" data-cal-nav="1" title="Следующий месяц">▶</button>
      <span class="cal-legend">
        <span class="cal-dot cal-dot-reduced"></span> — сокращённый день (жёлтый)
        &nbsp;<span class="cal-dot cal-dot-off"></span> — выходной (красный)
      </span>
    </div>
    <table class="cal-table"><thead><tr>`;

  for (const n of DAY_NAMES_SHORT) html += `<th>${n}</th>`;
  html += `</tr></thead><tbody><tr>`;

  // Пустые клетки перед началом месяца
  for (let i = 0; i < leading; i++) html += `<td class="cal-empty"></td>`;

  for (let d = 1; d <= daysInMonth; d++) {
    const dateStr = toDateStr(new Date(viewYear, viewMonth, d));
    const mark = dayMarks[dateStr];        // undefined | "reduced" | "off"
    const cls = [
      "cal-day",
      mark === "reduced" ? "cal-day-reduced" : "",  // жёлтый = сокращённый день
      mark === "off" ? "cal-day-off" : "",          // красный = полный выходной
      dateStr === todayStr ? "cal-day-today" : "",
    ].filter(Boolean).join(" ");
    const hint = mark === "off"
      ? "Выходной — снимите отметку"
      : mark === "reduced"
        ? "Сокращённый день — кликните ещё раз, чтобы сделать выходным"
        : "Нажмите: 1 клик — сокращённый (жёлтый), 2 клика — выходной (красный)";
    html += `<td class="${cls}" data-date="${dateStr}" title="${hint}">${d}</td>`;
    if ((leading + d) % 7 === 0 && d !== daysInMonth) html += `</tr><tr>`;
  }

  // Добиваем последнюю строку пустыми клетками
  const used = leading + daysInMonth;
  for (let i = used % 7; i > 0 && i < 7; i++) html += `<td class="cal-empty"></td>`;
  html += `</tr></tbody></table>`;

  rootEl.innerHTML = html;
}

// ---- Взаимодействие ----

// Циклическое переключение состояния дня:
//   обычный -> reduced (жёлтый) -> off (красный) -> обычный.
// При клике сразу сохраняем/удаляем запись в базе, затем перерисовываем.
async function toggleDay(dateStr) {
  const current = dayMarks[dateStr];       // undefined | "reduced" | "off"
  const next = !current ? "reduced" : current === "reduced" ? "off" : null;
  try {
    if (next === null) {
      await api.removeHoliday(dateStr);
      delete dayMarks[dateStr];
    } else {
      await api.addHoliday(dateStr, next);
      dayMarks[dateStr] = next;
    }
  } catch (e) {
    alert(`Не удалось изменить день ${dateStr}: ${e.message || e}`);
    return;
  }
  renderCalendar();
  // Сообщаем главному экрану, что список праздников мог измениться.
  window.dispatchEvent(new CustomEvent("holidays-changed"));
}

// Единый обработчик кликов внутри календаря (навешивается один раз).
function onTableClick(e) {
  const nav = e.target.closest("[data-cal-nav]");
  if (nav) {
    const delta = Number(nav.dataset.calNav);
    viewMonth += delta;
    if (viewMonth < 0) { viewMonth = 11; viewYear--; }
    if (viewMonth > 11) { viewMonth = 0; viewYear++; }
    refreshMonth();
    return;
  }
  const cell = e.target.closest(".cal-day");
  if (cell) toggleDay(cell.dataset.date);
}

// ---- Точка входа для dictModal ----

// Вызывается при каждом открытии вкладки «Выходные дни».
export async function showDaysOffCalendar(container) {
  rootEl = container;
  if (!rootEl) return;

  if (!bound) {
    bound = true;
    rootEl.addEventListener("click", onTableClick);
  }

  // При первом открытии подхватываем текущий месяц активной недели расписания.
  if (!loaded) {
    loaded = true;
    try {
      const { state } = await import("../../../app.js");
      const wk = state?.weeks?.find(
        (w) => Number(w.id) === Number(state.currentWeekId)
      );
      if (wk?.start_date) {
        const d = new Date(wk.start_date);
        if (!isNaN(d)) {
          viewYear = d.getFullYear();
          viewMonth = d.getMonth();
        }
      }
    } catch (_) {
      /* если состояние недоступно — оставляем текущий месяц */
    }
  }

  await loadHolidays();
  renderCalendar();
}

// Выход с вкладки: чистим контейнер (обработчик оставим — он привязан к таблице).
export function hideDaysOffCalendar() {
  if (rootEl) rootEl.innerHTML = "";
}
