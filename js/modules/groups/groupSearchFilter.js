function groupSearchFilter(groups, value) {
    const v = value.trim().toLowerCase();
    if (!v) return groups;

    return groups.filter((g) => g.name.toLowerCase().includes(v));
}
export default groupSearchFilter;
