import { state } from "../../../app.js";
import { api } from "../../LoadFromBD/api.js";

const openBtn = document.getElementById("roomPrefsBtn");
const overlay = document.getElementById("roomPrefsModalOverlay");
const closeBtn = document.getElementById("roomPrefsModalClose");
const cancelBtn = document.getElementById("roomPrefsCancelBtn");
const saveBtn = document.getElementById("roomPrefsSaveBtn");
const addRowBtn = document.getElementById("addRoomPrefRowBtn");
const subjectSelect = document.getElementById("roomPrefSubjectSelect");
const subjectSearchInput = document.getElementById("roomPrefSubjectSearch");
const roomSearchInput = document.getElementById("roomPrefRoomSearch");
const overviewSearchInput = document.getElementById("roomPrefOverviewSearch");
const rowsWrap = document.getElementById("roomPrefRows");
const overviewWrap = document.getElementById("roomPrefOverview");

let isBound = false;
let allPrefsRows = [];

function normId(v) {
  const n = Number(v);
  return Number.isFinite(n) && n > 0 ? n : 0;
}

function byName(a, b) {
  return String(a?.name || "").localeCompare(String(b?.name || ""), "ru");
}

function getSubjects() {
  return [...(state.subjects || [])].sort(byName);
}

function getRooms() {
  return [...(state.rooms || [])].sort(byName);
}

function filterByText(list, query, field = "name") {
  const q = String(query || "")
    .trim()
    .toLowerCase();
  if (!q) return list;
  return (list || []).filter((item) =>
    String(item?.[field] || "")
      .toLowerCase()
      .includes(q),
  );
}

function str(v) {
  return String(v ?? "").trim();
}

function fillSubjectSelect() {
  if (!subjectSelect) return;

  const current = normId(subjectSelect.value);
  const filteredSubjects = filterByText(
    getSubjects(),
    subjectSearchInput?.value,
    "name",
  );
  subjectSelect.innerHTML = "";

  const empty = document.createElement("option");
  empty.value = "";
  empty.textContent = "- select subject -";
  subjectSelect.appendChild(empty);

  for (const subject of filteredSubjects) {
    const opt = document.createElement("option");
    opt.value = String(subject.id);
    opt.textContent = subject.name;
    subjectSelect.appendChild(opt);
  }

  if (
    current &&
    [...subjectSelect.options].some((x) => normId(x.value) === current)
  ) {
    subjectSelect.value = String(current);
  }
}

function makeRoomSelect(selectedRoomId) {
  const select = document.createElement("select");
  select.className = "select room-pref-room";

  const empty = document.createElement("option");
  empty.value = "";
  empty.textContent = "- кабинет -";
  select.appendChild(empty);

  const filteredRooms = filterByText(
    getRooms(),
    roomSearchInput?.value,
    "name",
  );
  for (const room of filteredRooms) {
    const opt = document.createElement("option");
    opt.value = String(room.id);
    opt.textContent = room.name;
    select.appendChild(opt);
  }

  const roomId = normId(selectedRoomId);
  if (roomId) select.value = String(roomId);
  return select;
}

function refreshRoomSelects() {
  if (!rowsWrap) return;
  const rows = rowsWrap.querySelectorAll(".room-pref-row");

  for (const row of rows) {
    const oldSelect = row.querySelector(".room-pref-room");
    if (!oldSelect) continue;

    const selectedId = normId(oldSelect.value);
    const newSelect = makeRoomSelect(selectedId);
    oldSelect.replaceWith(newSelect);
  }
}

function renderRow(pref = {}) {
  if (!rowsWrap) return;

  const row = document.createElement("div");
  row.className = "room-pref-row";

  const roomSelect = makeRoomSelect(pref.roomId);

  const priorityInput = document.createElement("input");
  priorityInput.className = "select room-pref-priority";
  priorityInput.type = "number";
  priorityInput.min = "0";
  priorityInput.step = "1";
  priorityInput.value = String(Number(pref.priority ?? 100));

  const primaryCell = document.createElement("label");
  primaryCell.className = "room-pref-primary-cell";

  const primaryInput = document.createElement("input");
  primaryInput.type = "radio";
  primaryInput.name = "room-pref-primary-radio";
  primaryInput.checked = Number(pref.isPrimary ?? pref.is_primary ?? 0) === 1;

  const primaryText = document.createElement("span");
  primaryText.textContent = "Primary";

  primaryCell.appendChild(primaryInput);
  primaryCell.appendChild(primaryText);

  const removeBtn = document.createElement("button");
  removeBtn.type = "button";
  removeBtn.className = "room-pref-remove-btn";
  removeBtn.textContent = "Remove";
  removeBtn.addEventListener("click", () => row.remove());

  row.appendChild(roomSelect);
  row.appendChild(priorityInput);
  row.appendChild(primaryCell);
  row.appendChild(removeBtn);

  rowsWrap.appendChild(row);
}

function renderRows(prefs = []) {
  if (!rowsWrap) return;
  rowsWrap.innerHTML = "";

  const list = Array.isArray(prefs) ? prefs : [];
  if (list.length === 0) {
    renderRow({ priority: 100, isPrimary: 1 });
    return;
  }

  for (const pref of list) {
    renderRow({
      roomId: pref.roomId ?? pref.room_id ?? pref.idRoom,
      priority: pref.priority ?? 100,
      isPrimary: pref.isPrimary ?? pref.is_primary ?? 0,
    });
  }
}

function normalizeAllPrefRow(row) {
  return {
    subjectId: normId(row.subject_id ?? row.subjectId),
    subjectName: str(row.subject_name ?? row.subjectName),
    roomId: normId(row.room_id ?? row.roomId),
    roomName: str(row.room_name ?? row.roomName),
    priority: Number(row.priority ?? 100),
    isPrimary: Number(row.is_primary ?? row.isPrimary ?? 0) === 1 ? 1 : 0,
  };
}

function normalizeSubjectPrefRow(row) {
  return {
    roomId: normId(row.roomId ?? row.room_id ?? row.idRoom),
    priority: Number(row.priority ?? 100),
    isPrimary: Number(row.isPrimary ?? row.is_primary ?? 0) === 1 ? 1 : 0,
  };
}

async function deleteOverviewRoom(subjectIdRaw, roomIdRaw) {
  const subjectId = normId(subjectIdRaw);
  const roomId = normId(roomIdRaw);
  if (!subjectId || !roomId) return;

  const currentPrefs = await api.planRoomPrefs(subjectId);
  const normalized = (currentPrefs || [])
    .map(normalizeSubjectPrefRow)
    .filter((x) => x.roomId > 0);

  const nextPrefs = normalized.filter((x) => x.roomId !== roomId);
  await api.saveRoomPrefs(subjectId, nextPrefs);

  if (normId(subjectSelect?.value) === subjectId) {
    await loadSubjectPrefs();
  }
  await loadAllPrefsOverview();
}

function groupedOverviewRows() {
  const query = str(overviewSearchInput?.value).toLowerCase();
  const groups = new Map();

  for (const raw of allPrefsRows) {
    const row = normalizeAllPrefRow(raw);
    if (!row.subjectId || !row.roomId || !row.subjectName || !row.roomName)
      continue;

    const subjectKey = String(row.subjectId);
    if (!groups.has(subjectKey)) {
      groups.set(subjectKey, {
        subjectId: row.subjectId,
        subjectName: row.subjectName,
        rooms: [],
      });
    }

    groups.get(subjectKey).rooms.push({
      roomId: row.roomId,
      roomName: row.roomName,
      priority: Number.isFinite(row.priority) ? row.priority : 100,
      isPrimary: row.isPrimary,
    });
  }

  let list = [...groups.values()];
  list.sort((a, b) => a.subjectName.localeCompare(b.subjectName, "ru"));

  for (const item of list) {
    item.rooms.sort((a, b) => {
      if (b.isPrimary !== a.isPrimary) return b.isPrimary - a.isPrimary;
      if (a.priority !== b.priority) return a.priority - b.priority;
      return a.roomName.localeCompare(b.roomName, "ru");
    });
  }

  if (!query) return list;
  return list.filter((item) => {
    if (item.subjectName.toLowerCase().includes(query)) return true;
    return item.rooms.some((room) =>
      room.roomName.toLowerCase().includes(query),
    );
  });
}

function renderOverview() {
  if (!overviewWrap) return;
  overviewWrap.innerHTML = "";

  const groups = groupedOverviewRows();
  if (!groups.length) {
    const empty = document.createElement("div");
    empty.className = "muted";
    empty.textContent = "Связки не найдены.";
    overviewWrap.appendChild(empty);
    return;
  }

  for (const item of groups) {
    const block = document.createElement("div");
    block.className = "room-pref-overview-item";

    const title = document.createElement("div");
    title.className = "room-pref-overview-subject";
    title.textContent = item.subjectName;

    const rooms = document.createElement("div");
    rooms.className = "room-pref-overview-rooms";

    for (const room of item.rooms) {
      const chip = document.createElement("span");
      chip.className = "room-pref-overview-room";
      if (room.isPrimary === 1)
        chip.classList.add("room-pref-overview-room--primary");

      const primaryText = room.isPrimary === 1 ? "Primary, " : "";
      const label = document.createElement("span");
      label.className = "room-pref-overview-room-label";
      label.textContent = `${room.roomName} (${primaryText}P${room.priority})`;

      const delBtn = document.createElement("button");
      delBtn.type = "button";
      delBtn.className = "room-pref-overview-delete-btn";
      delBtn.title = "Удалить аудиторию из предпочтений";
      delBtn.textContent = "×";
      delBtn.addEventListener("click", (e) => {
        e.preventDefault();
        e.stopPropagation();
        delBtn.disabled = true;
        deleteOverviewRoom(item.subjectId, room.roomId)
          .catch((err) => {
            console.error(err);
            alert(`Delete failed: ${err?.message || err}`);
          })
          .finally(() => {
            delBtn.disabled = false;
          });
      });

      chip.appendChild(label);
      chip.appendChild(delBtn);
      rooms.appendChild(chip);
    }

    block.appendChild(title);
    block.appendChild(rooms);
    overviewWrap.appendChild(block);
  }
}

async function loadAllPrefsOverview() {
  allPrefsRows = await api.planRoomPrefsAll();
  renderOverview();
}

function collectRows() {
  if (!rowsWrap) return [];

  const out = [];
  const seen = new Set();
  const rows = rowsWrap.querySelectorAll(".room-pref-row");

  for (const row of rows) {
    const roomId = normId(row.querySelector(".room-pref-room")?.value);
    const priorityRaw = Number(
      row.querySelector(".room-pref-priority")?.value ?? 100,
    );
    const priority =
      Number.isFinite(priorityRaw) && priorityRaw >= 0
        ? Math.round(priorityRaw)
        : 100;
    const isPrimary = row.querySelector('input[type="radio"]')?.checked ? 1 : 0;

    if (!roomId || seen.has(roomId)) continue;
    seen.add(roomId);

    out.push({ roomId, priority, isPrimary });
  }

  if (out.length > 0 && !out.some((x) => x.isPrimary === 1)) {
    out[0].isPrimary = 1;
  }

  let foundPrimary = false;
  for (const item of out) {
    if (item.isPrimary === 1 && !foundPrimary) {
      foundPrimary = true;
      continue;
    }
    if (item.isPrimary === 1 && foundPrimary) item.isPrimary = 0;
  }

  return out;
}

async function loadSubjectPrefs() {
  const subjectId = normId(subjectSelect?.value);
  if (!subjectId) {
    renderRows([]);
    return;
  }

  const prefs = await api.planRoomPrefs(subjectId);
  renderRows(prefs);
}

async function openModal() {
  if (!overlay) return;

  if (!state.subjects?.length || !state.rooms?.length) {
    alert("Wait until subjects and rooms are loaded.");
    return;
  }

  if (subjectSearchInput) subjectSearchInput.value = "";
  if (roomSearchInput) roomSearchInput.value = "";
  if (overviewSearchInput) overviewSearchInput.value = "";

  fillSubjectSelect();

  if (!subjectSelect.value && state.subjects.length) {
    subjectSelect.value = String(state.subjects[0].id);
  }

  await Promise.all([loadSubjectPrefs(), loadAllPrefsOverview()]);
  overlay.classList.remove("hidden");
}

function closeModal() {
  overlay?.classList.add("hidden");
}

async function savePrefs() {
  const subjectId = normId(subjectSelect?.value);
  if (!subjectId) {
    alert("Select a subject.");
    return;
  }

  const prefs = collectRows();
  await api.saveRoomPrefs(subjectId, prefs);
  await Promise.all([loadSubjectPrefs(), loadAllPrefsOverview()]);
  alert("Сохранено.");
}

export function initRoomPrefsModal() {
  if (isBound) return;
  if (!overlay || !openBtn || !subjectSelect || !rowsWrap) return;

  isBound = true;

  openBtn.addEventListener("click", () => {
    openModal().catch((e) => {
      console.error(e);
      alert("Failed to open room preferences modal.");
    });
  });

  closeBtn?.addEventListener("click", closeModal);
  cancelBtn?.addEventListener("click", closeModal);

  addRowBtn?.addEventListener("click", () =>
    renderRow({ priority: 100, isPrimary: 0 }),
  );

  subjectSelect.addEventListener("change", () => {
    loadSubjectPrefs().catch((e) => {
      console.error(e);
      alert("Failed to load room preferences.");
    });
  });

  subjectSearchInput?.addEventListener("input", () => {
    fillSubjectSelect();
    loadSubjectPrefs().catch((e) => {
      console.error(e);
    });
  });

  roomSearchInput?.addEventListener("input", () => {
    refreshRoomSelects();
  });

  overviewSearchInput?.addEventListener("input", () => {
    renderOverview();
  });

  saveBtn?.addEventListener("click", () => {
    savePrefs().catch((e) => {
      console.error(e);
      alert(`Save failed: ${e?.message || e}`);
    });
  });
}
