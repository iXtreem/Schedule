// Поиск из модалки занятия удалён — модуль оставлен как утилита фильтрации.
export function filterByName(list, value) {
    const v = (value || "").trim().toLowerCase();
    if (!v) return list;
    return list.filter((item) => item.name.toLowerCase().includes(v));
}
