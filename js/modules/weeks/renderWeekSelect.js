import { state } from "../../../app.js";
import { formatDateForDisplay } from "../schedule/dateUtils.js";
function renderWeekSelect() {
  const weekSelect = document.getElementById("weekSelect");
  if (!weekSelect) return;

  weekSelect.innerHTML = "";

  for (const w of state.weeks) {
    const opt = document.createElement("option");
    opt.value = String(w.id);

    if (w.start_date && w.end_date) {
      const s = formatDateForDisplay(new Date(w.start_date));
      const e = formatDateForDisplay(new Date(w.end_date));
      opt.textContent = `${s} — ${e}`;
    } else {
      opt.textContent = w.name || String(w.id);
    }

    weekSelect.appendChild(opt);
  }

  if (state.currentWeekId != null) {
    weekSelect.value = String(state.currentWeekId);
  }
}

export default renderWeekSelect;
