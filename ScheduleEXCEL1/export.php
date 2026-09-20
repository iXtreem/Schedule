<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../backend/config/db.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;

const TEMPLATE_KEY_ALL = 'all';
const DEFAULT_RECENT_YEARS_LIMIT = 4;
const RECENT_YEARS_LIMIT_MIN = 1;
const RECENT_YEARS_LIMIT_MAX = 10;

function templatePathByKey(string $templateKey): string
{
    $key = strtolower(trim($templateKey));

    if ($key === 'x1' || $key === '1') {
        return __DIR__ . '/maket_raspisaniax1.xlsx';
    }
    if ($key === 'x2' || $key === '2') {
        return __DIR__ . '/maket_raspisaniax2.xlsx';
    }
    if ($key === 'x3' || $key === '3') {
        return __DIR__ . '/maket_raspisaniax3.xlsx';
    }
    if ($key === TEMPLATE_KEY_ALL || $key === 'xall') {
        return __DIR__ . '/maket_raspisaniaxAll.xlsx';
    }

    throw new RuntimeException('TEMPLATE_KEY_INVALID');
}

function templateKeyFromSize(int $templateSize): string
{
    if ($templateSize < 1 || $templateSize > 3) {
        throw new RuntimeException('TEMPLATE_SIZE_INVALID');
    }

    return 'x' . $templateSize;
}

function cellString($value): string
{
    if ($value === null) return '';
    if (is_string($value)) return $value;
    return (string)$value;
}

function toUtf8String(string $value): string
{
    if ($value === '') return '';

    if (mb_check_encoding($value, 'UTF-8')) {
        return $value;
    }

    $enc = mb_detect_encoding($value, ['UTF-8', 'Windows-1251', 'CP1251', 'ISO-8859-1'], true);
    if ($enc && strtoupper($enc) !== 'UTF-8') {
        $converted = @mb_convert_encoding($value, 'UTF-8', $enc);
        if (is_string($converted)) {
            return $converted;
        }
    }

    $fallback = @mb_convert_encoding($value, 'UTF-8', 'Windows-1251');
    return is_string($fallback) ? $fallback : $value;
}

function normalizeWhitespace(string $value): string
{
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function rowValue(array $row, string $key, $default = null)
{
    if (array_key_exists($key, $row)) return $row[$key];

    $upper = strtoupper($key);
    if (array_key_exists($upper, $row)) return $row[$upper];

    $lower = strtolower($key);
    if (array_key_exists($lower, $row)) return $row[$lower];

    return $default;
}

function normalizeDbRowText(array $row): array
{
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $row[$k] = toUtf8String($v);
        }
    }
    return $row;
}

function hasColumn($conn, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $sql = "
      SELECT 1 AS ok
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = 'dbo'
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ";

    $st = @odbc_prepare($conn, $sql);
    if (!$st) {
        $cache[$key] = false;
        return false;
    }

    if (!@odbc_execute($st, [$tableName, $columnName])) {
        $cache[$key] = false;
        return false;
    }

    $cache[$key] = (bool)odbc_fetch_array($st);
    return $cache[$key];
}

function resolveWeekId($conn): int
{
    $weekId = (int)($_GET['week_id'] ?? 0);
    if ($weekId > 0) return $weekId;

    $weeksSoft = hasColumn($conn, 'TB_Weeks', 'IsDeleted') ? ' WHERE IsDeleted = 0' : '';
    $sql = 'SELECT TOP 1 idWeek AS id FROM TB_Weeks' . $weeksSoft . ' ORDER BY StartDate DESC';

    $res = @odbc_exec($conn, $sql);
    if (!$res) {
        throw new RuntimeException('WEEK_QUERY_FAILED: ' . (string)odbc_errormsg($conn));
    }

    $row = odbc_fetch_array($res);
    $id = (int)($row['id'] ?? $row['ID'] ?? 0);
    if ($id <= 0) {
        throw new RuntimeException('WEEK_NOT_FOUND');
    }

    return $id;
}

function parseGroupIdsFromRequest(): array
{
    $raw = (string)($_GET['group_ids'] ?? '');
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[,\s;]+/u', $raw) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $id = (int)$p;
        if ($id <= 0) continue;
        if (!in_array($id, $out, true)) {
            $out[] = $id;
        }
    }

    return $out;
}

function fetchAllGroupIds($conn): array
{
    $groupSoft = hasColumn($conn, 'TB_Group', 'GroupDeleted') ? ' WHERE GroupDeleted = 0' : '';
    $sql = "
      SELECT idGroup AS group_id
      FROM TB_Group{$groupSoft}
      ORDER BY GroupShortName, GroupName, idGroup
    ";

    $res = @odbc_exec($conn, $sql);
    if (!$res) {
        throw new RuntimeException('ALL_GROUPS_QUERY_FAILED: ' . (string)odbc_errormsg($conn));
    }

    $out = [];
    while ($row = odbc_fetch_array($res)) {
        $row = normalizeDbRowText($row);
        $id = (int)rowValue($row, 'group_id', 0);
        if ($id <= 0) continue;
        if (!in_array($id, $out, true)) {
            $out[] = $id;
        }
    }

    return $out;
}

function fetchGroupCatalog($conn): array
{
    $groupSoft = hasColumn($conn, 'TB_Group', 'GroupDeleted') ? ' WHERE GroupDeleted = 0' : '';
    $yearSelect = hasColumn($conn, 'TB_Group', 'GroupYear')
        ? 'GroupYear AS group_year,'
        : 'NULL AS group_year,';

    $sql = "
      SELECT
        idGroup AS group_id,
        {$yearSelect}
        GroupShortName AS group_short,
        GroupName AS group_name
      FROM TB_Group{$groupSoft}
      ORDER BY GroupShortName, GroupName, idGroup
    ";

    $res = @odbc_exec($conn, $sql);
    if (!$res) {
        throw new RuntimeException('GROUP_CATALOG_QUERY_FAILED: ' . (string)odbc_errormsg($conn));
    }

    $catalog = [];
    while ($row = odbc_fetch_array($res)) {
        $row = normalizeDbRowText($row);

        $groupId = (int)rowValue($row, 'group_id', 0);
        if ($groupId <= 0) continue;

        $shortName = normalizeWhitespace((string)rowValue($row, 'group_short', ''));
        $fullName = normalizeWhitespace((string)rowValue($row, 'group_name', ''));
        $groupYear = normalizeGroupYear(rowValue($row, 'group_year', 0));
        if ($groupYear <= 0) {
            $groupYear = parseAdmissionYearFromGroupText($shortName !== '' ? $shortName : $fullName);
        }

        $catalog[] = [
            'id' => $groupId,
            'short' => $shortName,
            'name' => $fullName,
            'year' => $groupYear,
        ];
    }

    return $catalog;
}

function resolveRecentYearListFromCatalog(array $catalog, int $yearsLimit): array
{
    $years = [];
    foreach ($catalog as $group) {
        $year = (int)($group['year'] ?? 0);
        if ($year <= 0) continue;
        if (!in_array($year, $years, true)) {
            $years[] = $year;
        }
    }

    if (!$years) {
        return [];
    }

    rsort($years, SORT_NUMERIC);
    return array_slice($years, 0, $yearsLimit);
}

function fetchRecentGroupIds($conn, int $yearsLimit): array
{
    $catalog = fetchGroupCatalog($conn);
    if (!$catalog) {
        return [];
    }

    $recentYears = resolveRecentYearListFromCatalog($catalog, $yearsLimit);
    if (!$recentYears) {
        return fetchAllGroupIds($conn);
    }

    $yearSet = array_flip($recentYears);
    $groupIds = [];

    foreach ($catalog as $group) {
        $groupId = (int)($group['id'] ?? 0);
        $groupYear = (int)($group['year'] ?? 0);
        if ($groupId <= 0 || $groupYear <= 0) continue;
        if (!isset($yearSet[$groupYear])) continue;
        if (!in_array($groupId, $groupIds, true)) {
            $groupIds[] = $groupId;
        }
    }

    return $groupIds;
}

function resolveGroupIdsForExport($conn): array
{
    $raw = trim((string)($_GET['group_ids'] ?? ''));
    if ($raw !== '') {
        return parseGroupIdsFromRequest();
    }

    return fetchAllGroupIds($conn);
}

function parseTemplateSize($raw): int
{
    if (is_int($raw)) {
        return ($raw >= 1 && $raw <= 3) ? $raw : 0;
    }

    if (is_string($raw)) {
        $raw = normalizeWhitespace($raw);
        if ($raw !== '' && preg_match('/^(?:x)?([1-3])$/iu', $raw, $m)) {
            return (int)$m[1];
        }
        if ($raw !== '' && preg_match('/x([1-3])$/iu', $raw, $m)) {
            return (int)$m[1];
        }
    }

    return 0;
}

function parseTemplateKey($raw): string
{
    if (is_string($raw) || is_int($raw)) {
        $value = strtolower(normalizeWhitespace((string)$raw));
        if ($value === TEMPLATE_KEY_ALL || $value === 'xall') {
            return TEMPLATE_KEY_ALL;
        }
    }

    $size = parseTemplateSize($raw);
    if ($size > 0) {
        return templateKeyFromSize($size);
    }

    return '';
}

function parseTruthyFlag(string $value): bool
{
    $value = strtolower(normalizeWhitespace($value));
    if ($value === '') return false;
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

function isAllRecentExportRequested(): bool
{
    $mode = strtolower(normalizeWhitespace((string)($_GET['export_mode'] ?? $_GET['mode'] ?? '')));
    if (in_array($mode, ['all_recent', 'all_recent_groups', 'all', 'xall'], true)) {
        return true;
    }

    $flags = [
        (string)($_GET['all_recent'] ?? ''),
        (string)($_GET['all_recent_groups'] ?? ''),
        (string)($_GET['export_all_recent'] ?? ''),
    ];

    foreach ($flags as $flag) {
        if (parseTruthyFlag($flag)) {
            return true;
        }
    }

    return false;
}

function parseRecentYearsLimitFromRequest(): int
{
    $raw = normalizeWhitespace((string)($_GET['recent_years'] ?? $_GET['years'] ?? ''));
    if ($raw === '' || !preg_match('/^\d{1,2}$/', $raw)) {
        return DEFAULT_RECENT_YEARS_LIMIT;
    }

    $limit = (int)$raw;
    if ($limit < RECENT_YEARS_LIMIT_MIN) return RECENT_YEARS_LIMIT_MIN;
    if ($limit > RECENT_YEARS_LIMIT_MAX) return RECENT_YEARS_LIMIT_MAX;
    return $limit;
}

function normalizeGroupYear($rawYear): int
{
    $year = (int)$rawYear;
    if ($year >= 2000 && $year <= 2100) {
        return $year;
    }
    if ($year >= 0 && $year <= 99) {
        return 2000 + $year;
    }
    return 0;
}

function parseAdmissionYearFromGroupText(string $value): int
{
    $text = normalizeWhitespace($value);
    if ($text === '') return 0;

    if (preg_match('/\b(20\d{2})\b/u', $text, $m)) {
        return normalizeGroupYear($m[1]);
    }

    if (preg_match('/[A-Za-zА-Яа-яЁё]+(\d{2})/u', $text, $m)) {
        return normalizeGroupYear($m[1]);
    }

    if (preg_match('/\b(\d{2})\b/u', $text, $m)) {
        return normalizeGroupYear($m[1]);
    }

    return 0;
}

function chunkGroupIds(array $groupIds, int $chunkSize = 3): array
{
    $chunkSize = max(1, $chunkSize);
    $clean = [];

    foreach ($groupIds as $id) {
        $groupId = (int)$id;
        if ($groupId <= 0) continue;
        if (!in_array($groupId, $clean, true)) {
            $clean[] = $groupId;
        }
    }

    if (!$clean) {
        return [];
    }

    return array_chunk($clean, $chunkSize);
}

function parseBundlePlanFromRequest(): array
{
    $raw = trim((string)($_GET['bundle_plan'] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('BUNDLE_PLAN_INVALID_JSON');
    }

    $bundles = [];
    foreach ($decoded as $idx => $item) {
        if (!is_array($item)) continue;

        $templateRaw = $item['template_size'] ?? $item['templateSize'] ?? $item['template'] ?? null;
        $templateSize = parseTemplateSize($templateRaw);
        $templateKey = parseTemplateKey($templateRaw);
        $idsRaw = $item['group_ids'] ?? $item['groupIds'] ?? [];
        if (!is_array($idsRaw)) {
            throw new RuntimeException('BUNDLE_PLAN_GROUP_IDS_INVALID_AT_' . ($idx + 1));
        }

        $groupIds = [];
        foreach ($idsRaw as $idRaw) {
            $id = (int)$idRaw;
            if ($id <= 0) continue;
            if (!in_array($id, $groupIds, true)) {
                $groupIds[] = $id;
            }
        }

        if ($templateKey === TEMPLATE_KEY_ALL) {
            throw new RuntimeException('BUNDLE_PLAN_TEMPLATE_ALL_NOT_SUPPORTED_AT_' . ($idx + 1));
        }

        if ($templateSize <= 0) {
            $templateSize = count($groupIds);
        }

        if ($templateSize < 1 || $templateSize > 3) {
            throw new RuntimeException('BUNDLE_PLAN_TEMPLATE_SIZE_INVALID_AT_' . ($idx + 1));
        }

        if (count($groupIds) !== $templateSize) {
            throw new RuntimeException('BUNDLE_PLAN_GROUP_COUNT_MISMATCH_AT_' . ($idx + 1));
        }

        $bundles[] = [
            'template_key' => templateKeyFromSize($templateSize),
            'template_size' => $templateSize,
            'group_ids' => $groupIds,
        ];
    }

    if (!$bundles) {
        throw new RuntimeException('BUNDLE_PLAN_EMPTY');
    }

    return $bundles;
}

function sanitizeSheetTitle(string $title): string
{
    $title = preg_replace('/[\\\\\\/\\?\\*\\[\\]:]+/', '_', $title) ?? '';
    $title = trim($title);
    if ($title === '') {
        $title = 'Sheet';
    }

    if (mb_strlen($title, 'UTF-8') > 31) {
        $title = mb_substr($title, 0, 31, 'UTF-8');
    }

    return $title;
}

function makeSheetTitleFromBundle(
    int $sheetIndex,
    int $templateSize,
    array $groupIds,
    array $groupsById
): string {
    if ($templateSize > 3) {
        return sanitizeSheetTitle('ALL ' . $sheetIndex);
    }

    $parts = [];
    foreach ($groupIds as $groupId) {
        $info = $groupsById[(int)$groupId] ?? null;
        if (!is_array($info)) continue;
        $short = normalizeWhitespace((string)($info['short'] ?? ''));
        $name = normalizeWhitespace((string)($info['name'] ?? ''));
        $label = $short !== '' ? $short : $name;
        if ($label !== '') {
            $parts[] = $label;
        }
    }

    $suffix = $parts ? ' ' . implode('+', $parts) : '';
    return sanitizeSheetTitle('X' . $templateSize . ' ' . $sheetIndex . $suffix);
}

function resolveExportBundles($conn): array
{
    if (isAllRecentExportRequested()) {
        $yearsLimit = parseRecentYearsLimitFromRequest();
        $groupIds = fetchRecentGroupIds($conn, $yearsLimit);
        if (!$groupIds) {
            return [];
        }

        return [[
            'template_key' => TEMPLATE_KEY_ALL,
            'template_size' => count($groupIds),
            'group_ids' => $groupIds,
            'recent_years_limit' => $yearsLimit,
            'sheet_title' => 'ALL recent ' . $yearsLimit . 'y',
        ]];
    }

    $bundles = parseBundlePlanFromRequest();
    if ($bundles) {
        return $bundles;
    }

    $groupIds = resolveGroupIdsForExport($conn);
    if (!$groupIds) {
        return [];
    }

    $chunks = chunkGroupIds($groupIds, 3);
    $out = [];
    foreach ($chunks as $chunk) {
        $templateSize = count($chunk);
        $out[] = [
            'template_key' => templateKeyFromSize($templateSize),
            'template_size' => $templateSize,
            'group_ids' => $chunk,
        ];
    }

    return $out;
}

function collectUniqueGroupIdsFromBundles(array $bundles): array
{
    $out = [];
    foreach ($bundles as $bundle) {
        $ids = $bundle['group_ids'] ?? [];
        if (!is_array($ids)) continue;
        foreach ($ids as $idRaw) {
            $id = (int)$idRaw;
            if ($id <= 0) continue;
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
    }
    return $out;
}

function fetchWeekStartDate($conn, int $weekId): DateTimeImmutable
{
    $weeksSoft = hasColumn($conn, 'TB_Weeks', 'IsDeleted') ? ' AND IsDeleted = 0' : '';
    $sql = "
      SELECT CONVERT(varchar(10), StartDate, 23) AS start_date
      FROM TB_Weeks
      WHERE idWeek = ?{$weeksSoft}
    ";

    $st = @odbc_prepare($conn, $sql);
    if (!$st) {
        throw new RuntimeException('WEEK_PREPARE_FAILED: ' . (string)odbc_errormsg($conn));
    }

    if (!@odbc_execute($st, [$weekId])) {
        throw new RuntimeException('WEEK_EXECUTE_FAILED: ' . (string)odbc_errormsg($st));
    }

    $row = odbc_fetch_array($st);
    $start = normalizeWhitespace((string)rowValue((array)$row, 'start_date', ''));
    if ($start === '') {
        throw new RuntimeException('WEEK_DATE_NOT_FOUND');
    }

    try {
        return new DateTimeImmutable($start);
    } catch (Throwable $e) {
        throw new RuntimeException('WEEK_DATE_PARSE_FAILED: ' . $start);
    }
}

function fetchGroupsByIds($conn, array $groupIds): array
{
    if (!$groupIds) return [];

    $groupSoft = hasColumn($conn, 'TB_Group', 'GroupDeleted') ? ' AND GroupDeleted = 0' : '';
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $sql = "
      SELECT
        idGroup AS group_id,
        GroupShortName AS group_short,
        GroupName AS group_name
      FROM TB_Group
      WHERE idGroup IN ({$placeholders}){$groupSoft}
    ";

    $st = @odbc_prepare($conn, $sql);
    if (!$st) {
        throw new RuntimeException('GROUPS_PREPARE_FAILED: ' . (string)odbc_errormsg($conn));
    }

    if (!@odbc_execute($st, $groupIds)) {
        throw new RuntimeException('GROUPS_EXECUTE_FAILED: ' . (string)odbc_errormsg($st));
    }

    $out = [];
    while ($row = odbc_fetch_array($st)) {
        $row = normalizeDbRowText($row);
        $id = (int)rowValue($row, 'group_id', 0);
        if ($id <= 0) continue;
        $out[$id] = [
            'short' => normalizeWhitespace((string)rowValue($row, 'group_short', '')),
            'name' => normalizeWhitespace((string)rowValue($row, 'group_name', '')),
        ];
    }

    return $out;
}

function formatTeacherShort(string $surname, string $firstName, string $lastName): string
{
    $surname = normalizeWhitespace($surname);
    $firstName = normalizeWhitespace($firstName);
    $lastName = normalizeWhitespace($lastName);

    if ($surname === '' && $firstName === '' && $lastName === '') {
        return '';
    }

    $i1 = $firstName !== '' ? mb_substr($firstName, 0, 1) . '.' : '';
    $i2 = $lastName !== '' ? mb_substr($lastName, 0, 1) . '.' : '';

    return trim($surname . ' ' . $i1 . $i2);
}

function formatRoom(string $buildingRaw, string $roomRaw): string
{
    $building = normalizeWhitespace($buildingRaw);
    $room = normalizeWhitespace($roomRaw);

    if ($room === '') return '';
    if ($building === '') return $room;
    return $building . '-' . $room;
}

function pushUnique(array &$list, string $value): void
{
    $value = normalizeWhitespace($value);
    if ($value === '') return;
    if (!in_array($value, $list, true)) {
        $list[] = $value;
    }
}

function joinParts(array $parts): string
{
    return implode(' / ', $parts);
}

function fetchScheduleMatrix($conn, int $weekId, array $groupIds): array
{
    if (!$groupIds) return [];

    $scheduleSoft = hasColumn($conn, 'TB_Schedule', 'IsDeleted') ? ' AND s.IsDeleted = 0' : '';
    $roomSoft = hasColumn($conn, 'TB_Room', 'IsDeleted') ? ' AND r.IsDeleted = 0' : '';
    $discSoft = hasColumn($conn, 'TB_Discipl', 'DisciplDeleted') ? ' AND d.DisciplDeleted = 0' : '';
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));

    $sql = "
      SELECT
        s.idGroup AS group_id,
        s.DayOfWeek AS day_of_week,
        s.TimeSlot AS time_slot,
        s.CustomText AS custom_text,
        tt.TimeTypeName AS type_name,
        d.DisciplName AS subject_name,
        d.DisciplShortName AS subject_short,
        t.TeacherSurname AS teacher_surname,
        t.TeacherFirstName AS teacher_first,
        t.TeacherLastName AS teacher_last,
        r.Building AS building,
        r.RoomNumber AS room_number
      FROM TB_Schedule s
      LEFT JOIN TB_Discipl d ON d.idDiscipl = s.idDiscipl{$discSoft}
      LEFT JOIN TB_TimeType tt ON tt.idTimeType = s.idLessonType
      LEFT JOIN TB_Teacher t ON t.idTeacher = s.idTeacher
      LEFT JOIN TB_Room r ON r.idRoom = s.idRoom{$roomSoft}
      WHERE s.idWeek = ?{$scheduleSoft}
        AND s.idGroup IN ({$placeholders})
      ORDER BY s.idGroup, s.DayOfWeek, s.TimeSlot, s.idSchedule
    ";

    $params = array_merge([$weekId], $groupIds);
    $st = @odbc_prepare($conn, $sql);
    if (!$st) {
        throw new RuntimeException('LESSONS_PREPARE_FAILED: ' . (string)odbc_errormsg($conn));
    }

    if (!@odbc_execute($st, $params)) {
        throw new RuntimeException('LESSONS_EXECUTE_FAILED: ' . (string)odbc_errormsg($st));
    }

    $matrix = [];

    while ($row = odbc_fetch_array($st)) {
        $row = normalizeDbRowText($row);

        $groupId = (int)rowValue($row, 'group_id', 0);
        $day = (int)rowValue($row, 'day_of_week', 0);
        $slot = (int)rowValue($row, 'time_slot', 0);

        if ($groupId <= 0 || $day <= 0 || $slot <= 0) continue;

        if (!isset($matrix[$groupId][$day][$slot])) {
            $matrix[$groupId][$day][$slot] = [
                'subjects' => [],
                'types' => [],
                'teachers' => [],
                'rooms' => [],
            ];
        }

        $custom = normalizeWhitespace((string)rowValue($row, 'custom_text', ''));
        $subject = normalizeWhitespace((string)rowValue($row, 'subject_name', ''));
        if ($subject === '') {
            $subject = normalizeWhitespace((string)rowValue($row, 'subject_short', ''));
        }

        $subjectText = $custom !== '' ? $custom : $subject;
        $typeName = normalizeWhitespace((string)rowValue($row, 'type_name', ''));
        $teacher = formatTeacherShort(
            (string)rowValue($row, 'teacher_surname', ''),
            (string)rowValue($row, 'teacher_first', ''),
            (string)rowValue($row, 'teacher_last', '')
        );
        $room = formatRoom(
            (string)rowValue($row, 'building', ''),
            (string)rowValue($row, 'room_number', '')
        );

        pushUnique($matrix[$groupId][$day][$slot]['subjects'], $subjectText);
        pushUnique($matrix[$groupId][$day][$slot]['types'], $typeName);
        pushUnique($matrix[$groupId][$day][$slot]['teachers'], $teacher);
        pushUnique($matrix[$groupId][$day][$slot]['rooms'], $room);
    }

    foreach ($matrix as $groupId => $days) {
        foreach ($days as $day => $slots) {
            foreach ($slots as $slot => $entry) {
                $matrix[$groupId][$day][$slot] = [
                    'subject' => joinParts($entry['subjects']),
                    'type' => joinParts($entry['types']),
                    'teacher' => joinParts($entry['teachers']),
                    'room' => joinParts($entry['rooms']),
                ];
            }
        }
    }

    return $matrix;
}

function buildDayStartRows($sheet): array
{
    $map = [];
    $maxRow = $sheet->getHighestRow();
    $dayIndex = 0;

    for ($row = 1; $row <= $maxRow; $row++) {
        $dayLabel = normalizeWhitespace(cellString($sheet->getCell('A' . $row)->getValue()));
        $pairRaw = normalizeWhitespace(cellString($sheet->getCell('B' . $row)->getValue()));

        if ($dayLabel === '' || !preg_match('/^\d{1,2}$/', $pairRaw)) continue;
        if ((int)$pairRaw !== 1) continue;

        $dayIndex++;
        if ($dayIndex > 7) break;

        $map[$dayIndex] = $row;
    }

    return $map;
}

function buildPairRowsByDay($sheet, array $dayStartRows): array
{
    $map = [];
    $startByRow = [];
    foreach ($dayStartRows as $day => $row) {
        $startByRow[(int)$row] = (int)$day;
    }

    $currentDay = 0;
    $maxRow = $sheet->getHighestRow();

    for ($row = 1; $row <= $maxRow; $row++) {
        if (isset($startByRow[$row])) {
            $currentDay = $startByRow[$row];
        }

        if ($currentDay <= 0) continue;

        $pairRaw = normalizeWhitespace(cellString($sheet->getCell('B' . $row)->getValue()));
        if (!preg_match('/^\d{1,2}$/', $pairRaw)) continue;

        $pair = (int)$pairRaw;
        if ($pair < 1 || $pair > 30) continue;

        if (!isset($map[$currentDay][$pair])) {
            $map[$currentDay][$pair] = $row;
        }
    }

    return $map;
}

function getGroupColumnDefs(int $slotsCount): array
{
    if ($slotsCount < 1) {
        throw new RuntimeException('TEMPLATE_SLOTS_INVALID');
    }

    $defs = [];
    for ($i = 0; $i < $slotsCount; $i++) {
        $baseColumnIndex = 4 + ($i * 3); // D,E,F then G,H,I and so on
        $subjectCol = Coordinate::stringFromColumnIndex($baseColumnIndex);
        $teacherCol = Coordinate::stringFromColumnIndex($baseColumnIndex + 1);
        $roomCol = Coordinate::stringFromColumnIndex($baseColumnIndex + 2);

        $defs[] = [
            'header' => $subjectCol . '9',
            'subjectCol' => $subjectCol,
            'typeCol' => $subjectCol,
            'teacherCol' => $teacherCol,
            'roomCol' => $roomCol,
        ];
    }

    return $defs;
}

function detectTemplateGroupCapacity($sheet): int
{
    $maxColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $capacity = 0;
    $seenNonEmpty = false;

    for ($baseColumnIndex = 4; ($baseColumnIndex + 2) <= $maxColumnIndex; $baseColumnIndex += 3) {
        $roomHeaderColumn = Coordinate::stringFromColumnIndex($baseColumnIndex + 2);
        $roomHeader = normalizeWhitespace(cellString($sheet->getCell($roomHeaderColumn . '9')->getValue()));

        if ($roomHeader === '') {
            if ($seenNonEmpty) {
                break;
            }
            continue;
        }

        $seenNonEmpty = true;
        $capacity++;
    }

    return $capacity;
}

function fillGroupHeaders($sheet, array $groupIds, array $groupsById, array $defs): void
{
    foreach ($defs as $idx => $def) {
        $groupId = $groupIds[$idx] ?? null;
        $label = '';
        if ($groupId !== null && isset($groupsById[$groupId])) {
            $short = $groupsById[$groupId]['short'] ?? '';
            $name = $groupsById[$groupId]['name'] ?? '';
            $label = $short !== '' ? $short : $name;
        }

        $sheet->setCellValueExplicit($def['header'], (string)$label, DataType::TYPE_STRING);
    }
}

function fillDayHeaders($sheet, array $dayStartRows, DateTimeImmutable $weekStart): void
{
    $dayNames = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    foreach ($dayStartRows as $day => $row) {
        $day = (int)$day;
        $row = (int)$row;

        if (!isset($dayNames[$day])) continue;

        $date = $weekStart->modify('+' . ($day - 1) . ' days')->format('d.m.Y');
        $label = $dayNames[$day] . ' ' . $date;
        $sheet->setCellValueExplicit('A' . $row, $label, DataType::TYPE_STRING);
    }
}

function clearAndFillLessons(
    $sheet,
    array $groupIds,
    array $pairRowsByDay,
    array $matrix,
    array $defs
): void {
    foreach ($defs as $idx => $def) {
        $groupId = $groupIds[$idx] ?? null;

        foreach ($pairRowsByDay as $day => $rows) {
            foreach ($rows as $pair => $startRow) {
                $startRow = (int)$startRow;

                $subjectAddr = $def['subjectCol'] . $startRow;
                $typeAddr = $def['typeCol'] . ($startRow + 1);
                $teacherAddr = $def['teacherCol'] . ($startRow + 1);
                $roomAddr = $def['roomCol'] . $startRow;

                $sheet->setCellValueExplicit($subjectAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($typeAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($teacherAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($roomAddr, '', DataType::TYPE_STRING);

                if ($groupId === null) continue;

                $entry = $matrix[$groupId][$day][$pair] ?? null;
                if (!$entry) continue;

                $sheet->setCellValueExplicit($subjectAddr, (string)($entry['subject'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($typeAddr, (string)($entry['type'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($teacherAddr, (string)($entry['teacher'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($roomAddr, (string)($entry['room'] ?? ''), DataType::TYPE_STRING);
            }
        }
    }
}

$conn = null;
try {
    $conn = getDBConnection();

    $weekId = resolveWeekId($conn);
    $exportBundles = resolveExportBundles($conn);
    if (!$exportBundles) {
        throw new RuntimeException('GROUP_IDS_REQUIRED');
    }

    $groupIds = collectUniqueGroupIdsFromBundles($exportBundles);
    if (!$groupIds) {
        throw new RuntimeException('GROUP_IDS_REQUIRED');
    }

    $weekStart = fetchWeekStartDate($conn, $weekId);
    $groupsById = fetchGroupsByIds($conn, $groupIds);
    $matrix = fetchScheduleMatrix($conn, $weekId, $groupIds);

    $spreadsheet = null;
    $bundleDebug = [];
    $sheetIndex = 1;

    foreach ($exportBundles as $bundle) {
        $templateRaw = $bundle['template_key'] ?? $bundle['template'] ?? ($bundle['template_size'] ?? null);
        $templateKey = parseTemplateKey($templateRaw);
        $templateSize = (int)($bundle['template_size'] ?? 0);
        $bundleGroupIds = array_values((array)($bundle['group_ids'] ?? []));

        if ($templateKey === '') {
            if ($templateSize >= 1 && $templateSize <= 3) {
                $templateKey = templateKeyFromSize($templateSize);
            } elseif ($templateSize > 3) {
                $templateKey = TEMPLATE_KEY_ALL;
            }
        }

        if ($templateKey === '') {
            throw new RuntimeException('BUNDLE_TEMPLATE_KEY_INVALID');
        }

        if (!$bundleGroupIds) {
            throw new RuntimeException('BUNDLE_GROUP_IDS_EMPTY');
        }

        if ($templateKey !== TEMPLATE_KEY_ALL) {
            if ($templateSize < 1 || $templateSize > 3) {
                throw new RuntimeException('BUNDLE_TEMPLATE_SIZE_INVALID');
            }

            if (count($bundleGroupIds) !== $templateSize) {
                throw new RuntimeException('BUNDLE_GROUP_COUNT_MISMATCH');
            }
        } else {
            $templateSize = count($bundleGroupIds);
        }

        $templatePath = templatePathByKey($templateKey);
        if (!is_file($templatePath)) {
            throw new RuntimeException('TEMPLATE_FILE_NOT_FOUND_' . strtoupper($templateKey));
        }

        $bundleSpreadsheet = IOFactory::load($templatePath);
        $sheet = $bundleSpreadsheet->getActiveSheet();

        $templateCapacity = detectTemplateGroupCapacity($sheet);
        if ($templateCapacity < 1) {
            throw new RuntimeException('TEMPLATE_GROUP_CAPACITY_NOT_FOUND');
        }
        if (count($bundleGroupIds) > $templateCapacity) {
            throw new RuntimeException('BUNDLE_GROUP_COUNT_OVERFLOW');
        }

        $dayStartRows = buildDayStartRows($sheet);
        $pairRowsByDay = buildPairRowsByDay($sheet, $dayStartRows);
        $defs = getGroupColumnDefs($templateCapacity);

        fillGroupHeaders($sheet, $bundleGroupIds, $groupsById, $defs);
        fillDayHeaders($sheet, $dayStartRows, $weekStart);
        clearAndFillLessons($sheet, $bundleGroupIds, $pairRowsByDay, $matrix, $defs);

        $sheetTitleRaw = normalizeWhitespace((string)($bundle['sheet_title'] ?? $bundle['sheetTitle'] ?? ''));
        $sheetTitle = $sheetTitleRaw !== ''
            ? sanitizeSheetTitle($sheetTitleRaw)
            : makeSheetTitleFromBundle($sheetIndex, $templateSize, $bundleGroupIds, $groupsById);
        $sheet->setTitle($sheetTitle);
        $sheet->resetTabColor();

        if ($spreadsheet === null) {
            $spreadsheet = $bundleSpreadsheet;
        } else {
            $spreadsheet->addExternalSheet($sheet);
            $bundleSpreadsheet->disconnectWorksheets();
        }

        $bundleDebug[] = [
            'sheet_index' => $sheetIndex,
            'sheet_title' => $sheetTitle,
            'template_key' => $templateKey,
            'template_size' => $templateSize,
            'template_capacity' => $templateCapacity,
            'group_ids' => $bundleGroupIds,
            'day_start_rows' => $dayStartRows,
            'pairs_by_day' => $pairRowsByDay,
        ];
        $sheetIndex++;
    }

    if ($spreadsheet === null) {
        throw new RuntimeException('EXPORT_SHEETS_EMPTY');
    }
    $spreadsheet->setActiveSheetIndex(0);

    if ((string)($_GET['debug'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'week_id' => $weekId,
            'group_ids' => $groupIds,
            'export_bundles' => $exportBundles,
            'sheets_count' => count($bundleDebug),
            'bundle_debug' => $bundleDebug,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    $fileName = 'setka_raspisaniya_week_' . $weekId . '_' . date('Y-m-d_H-i-s') . '.xlsx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
    $writer->save('php://output');
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'EXPORT_FAILED: ' . $e->getMessage();
} finally {
    if ($conn) {
        dbClose($conn);
    }
}
