const subjectSearch = document.getElementById("subjectSearch");
const teacherSearch = document.getElementById("teacherSearch");
const typeSearch = document.getElementById("typeSearch");
const roomSearch = document.getElementById("roomSearch");

export function filterByName(list, value) {
    const v = value.trim().toLowerCase();
    if (!v) return list;
    return list.filter((item) => item.name.toLowerCase().includes(v));
}
