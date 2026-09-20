import { state } from "../../../app.js";
import renderWeekSelect from "./renderWeekSelect.js";
import {
  setGroupCheckboxesFromState,
  renderGroups,
} from "../groups/renderGroups.js";
import { renderTable } from "../schedule/renderTable.js";

export function renderWeekDates() {
  const el = document.getElementById("weekDates");
  if (!el) return;

  const wk = state.weeks.find((w) => w.id === state.currentWeekId);
  if (!wk) {
    el.textContent = "";
    return;
  }

  if (wk.name) {
    el.textContent = wk.name;
    return;
  }

  const s = wk.start_date ? formatDateForDisplay(new Date(wk.start_date)) : "";
  const e = wk.end_date ? formatDateForDisplay(new Date(wk.end_date)) : "";
  el.textContent = s && e ? `${s} — ${e}` : "";
}
