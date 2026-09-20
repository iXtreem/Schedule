import { state } from "../../../app.js";

function renderGroups() {
  groupBox.innerHTML = "";

  const seen = new Set();

  state.filteredGroups.forEach((g) => {
    const id = Number(g.id);
    if (seen.has(id)) return;
    seen.add(id);

    const label = document.createElement("label");
    label.className = "group-item";

    const cb = document.createElement("input");
    cb.type = "checkbox";
    cb.className = "group-checkbox";
    cb.value = String(id);
    cb.id = `g_${id}`;

    const text = document.createElement("span");
    text.textContent = `${g.name} (${g.size})`;

    label.appendChild(cb);
    label.appendChild(text);
    groupBox.appendChild(label);
  });
}

function setGroupCheckboxesFromState() {
  for (const g of state.groups) {
    const cb = document.getElementById(`g_${g.id}`);
    if (!cb) continue;
    cb.checked = state.selectedGroupIds.includes(g.id);
  }
}

export { renderGroups, setGroupCheckboxesFromState };
