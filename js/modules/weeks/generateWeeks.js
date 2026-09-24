
// Модуль «Автосоздание недель семестра» (кнопка в один клик).
//
// Открывает модалку с двумя полями: начало и конец семестра.
// По нажатию «Создать недели» вызывает api.generateWeeks — бэкенд строит
// список учебных недель с учётом выходных (воскресенья + дни из таблицы
// holiday) и вставляет отсутствующие недели в таблицу week.
// Существующие недели не дублируются, поэтому кнопку можно жать повторно
// (например, после добавления новых праздников).
import { api } from "../../LoadFromBD/api.js";
import { state } from "../../../app.js";
import renderWeekSelect from "./renderWeekSelect.js";

// ---- Ссылки на элементы страницы ----
const genBtn = document.getElementById("generateWeeksBtn");
const overlay = document.getElementById("genWeeksOverlay");
const closeBtn = document.getElementById("genWeeksClose");
const cancelBtn = document.getElementById("genWeeksCancel");
const runBtn = document.getElementById("genWeeksRun");
const startInput = document.getElementById("genSemesterStart");
const endInput = document.getElementById("genSemesterEnd");
const statusEl = document.getElementById("genWeeksStatus");

let bound = false; // обработчики навешиваем только один раз

// Дата в формате YYYY-MM-DD для input[type=date] (без смещения поясов).
function toDateInputStr(d) {
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, "0");
  const day = String(d.getDate()).padStart(2, "0");
  return `${y}-${m}-${day}`;
}

// Открыть модалку автогенерации недель.
export function openGenerateWeeksModal() {
  if (!overlay) return;

  // Подсказка по умолчанию: если есть недели — начинаем со следующей после
  // последней созданной; иначе — с ближайшего понедельника.
  if (state.weeks.length) {
    const lastEnd = state.weeks
      .map((w) => w.end_date)
      .filter(Boolean)
      .sort()
      .at(-1);
    if (lastEnd && !startInput.value) {
      const next = new Date(lastEnd + "T00:00:00");
      next.setDate(next.getDate() + 1);
      // Неделя начинается с понедельника — сдвигаем дату на ближайший Пн.
      const dow = (next.getDay() + 6) % 7; // 0 = понедельник
      next.setDate(next.getDate() - dow);
      startInput.value = toDateInputStr(next);
    }
  } else if (!startInput.value) {
    const today = new Date();
    const dow = (today.getDay() + 6) % 7; // 0 = понедельник
    const monday = new Date(today);
    monday.setDate(monday.getDate() - dow);
    startInput.value = toDateInputStr(monday);
  }

  overlay.classList.remove("hidden");
}

function closeGenerateWeeksModal() {
  overlay?.classList.add("hidden");
}

// Основной запуск: генерируем недели на сервере и обновляем список.
// Все изменения (создание/правка границ/удаление лишних недель) выполняет
// бэкенд и записывает их в таблицу week — здесь только перезагружаем данные.
async function runGeneration() {
  const start = startInput.value;
  const end = endInput.value;

  if (!start || !end) {
    statusEl.textContent = "Укажите дату начала и окончания семестра.";
    return;
  }
  if (end < start) {
    statusEl.textContent = "Дата окончания раньше даты начала.";
    return;
  }

  runBtn.disabled = true;
  statusEl.textContent = "Создаю недели…";

  try {
    const res = await api.generateWeeks({ start_date: start, end_date: end });

    // Перезагружаем список недель из БД и обновляем выпадающий список.
    const raw = await api.weeks();
    state.weeks = (raw || [])
      .map((w) => ({
        ...w,
        id: Number(w.id),
        start_date: w.start_date,
        end_date: w.end_date,
        name: w.name,
      }))
      .sort((a, b) => String(a.start_date).localeCompare(String(b.start_date)));

    renderWeekSelect();

    // Итог по базовым цифрам от сервера: создано / скорректировано / удалено.
    const created = res?.created ?? 0;
    const updated = res?.updated ?? 0;
    const removed = res?.removed ?? 0;
    const unchanged = Math.max(state.weeks.length - created - updated, 0);
    let msg = `Готово: создано ${created}, исправлено ${updated}, без изменений ${unchanged}`;
    if (removed) msg += `, удалено лишних ${removed}`;
    statusEl.textContent = msg + ".";

    // Если текущая неделя не выбрана или была удалена при синхронизации —
    // просим app.js открыть первую неделю (сам модуль не вызывает changeWeek,
    // чтобы не тянуть лишние зависимости).
    const currentStillThere = state.weeks.some(
      (w) => Number(w.id) === Number(state.currentWeekId)
    );
    if (!currentStillThere && state.weeks.length) {
      window.dispatchEvent(
        new CustomEvent("weeks-generated", { detail: { firstWeekId: state.weeks[0].id } })
      );
    }
  } catch (e) {
    console.error(e);
    statusEl.textContent = `Ошибка: ${e.message || e}`;
  } finally {
    runBtn.disabled = false;
  }
}

// Навешиваем обработчики (вызывается один раз из app.js при старте).
export function initGenerateWeeks() {
  if (bound || !genBtn) return;
  bound = true;

  genBtn.addEventListener("click", openGenerateWeeksModal);
  closeBtn?.addEventListener("click", closeGenerateWeeksModal);
  cancelBtn?.addEventListener("click", closeGenerateWeeksModal);
  runBtn?.addEventListener("click", runGeneration);

  // Клик по затемнению за окном — закрыть.
  overlay?.addEventListener("click", (e) => {
    if (e.target === overlay) closeGenerateWeeksModal();
  });
}
