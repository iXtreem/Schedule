<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../backend/config/db.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;

$templatePath = __DIR__ . '/setkaTeachers.xlsx';
$spreadsheet = IOFactory::load($templatePath);
$sheet = $spreadsheet->getActiveSheet();

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

function normalizeToken(string $value): string
{
    $value = normalizeWhitespace(toUtf8String($value));
    $value = mb_strtolower($value, 'UTF-8');
    $value = str_replace("\u{0451}", "\u{0435}", $value); // ё -> е
    $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '';
    return normalizeWhitespace($value);
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

function teacherFullName(string $surname, string $first, string $last): string
{
    return normalizeWhitespace(trim(
        normalizeWhitespace($surname) . ' ' .
        normalizeWhitespace($first) . ' ' .
        normalizeWhitespace($last)
    ));
}

function teacherShortName(string $surname, string $first, string $last): string
{
    $surname = normalizeWhitespace($surname);
    $first = normalizeWhitespace($first);
    $last = normalizeWhitespace($last);

    $i1 = $first !== '' ? mb_substr($first, 0, 1, 'UTF-8') : '';
    $i2 = $last !== '' ? mb_substr($last, 0, 1, 'UTF-8') : '';

    return normalizeWhitespace(trim($surname . ' ' . $i1 . ' ' . $i2));
}

function addLookupToken(array &$tokenToIds, string $token, int $teacherId): void
{
    if ($token === '') return;
    if (!isset($tokenToIds[$token])) {
        $tokenToIds[$token] = [$teacherId];
        return;
    }

    if (!in_array($teacherId, $tokenToIds[$token], true)) {
        $tokenToIds[$token][] = $teacherId;
    }
}

function buildTeacherLookup($conn): array
{
    $teacherSoft = hasColumn($conn, 'TB_Teacher', 'IsDeleted') ? ' WHERE IsDeleted = 0' : '';
    $sql = "
      SELECT
        idTeacher AS teacher_id,
        TeacherSurname AS teacher_surname,
        TeacherFirstName AS teacher_first,
        TeacherLastName AS teacher_last
      FROM TB_Teacher{$teacherSoft}
    ";

    $res = @odbc_exec($conn, $sql);
    if (!$res) {
        throw new RuntimeException('TEACHERS_QUERY_FAILED: ' . (string)odbc_errormsg($conn));
    }

    $teachersById = [];
    $tokenToIds = [];

    while ($row = odbc_fetch_array($res)) {
        $row = normalizeDbRowText($row);
        $id = (int)rowValue($row, 'teacher_id', 0);
        if ($id <= 0) continue;

        $surname = (string)rowValue($row, 'teacher_surname', '');
        $first = (string)rowValue($row, 'teacher_first', '');
        $last = (string)rowValue($row, 'teacher_last', '');

        $full = teacherFullName($surname, $first, $last);
        $short = teacherShortName($surname, $first, $last);

        $teachersById[$id] = $full !== '' ? $full : normalizeWhitespace($surname);

        addLookupToken($tokenToIds, normalizeToken($full), $id);
        addLookupToken($tokenToIds, normalizeToken($short), $id);

        $surnameToken = normalizeToken($surname);
        $firstInitial = $first !== '' ? normalizeToken(mb_substr($first, 0, 1, 'UTF-8')) : '';
        $lastInitial = $last !== '' ? normalizeToken(mb_substr($last, 0, 1, 'UTF-8')) : '';

        addLookupToken($tokenToIds, normalizeWhitespace($surnameToken . ' ' . $firstInitial . ' ' . $lastInitial), $id);
        addLookupToken($tokenToIds, normalizeWhitespace($surnameToken . ' ' . $firstInitial), $id);
    }

    return [
        'teachersById' => $teachersById,
        'tokenToIds' => $tokenToIds,
    ];
}

function resolveTeacherIdByName(string $nameRaw, array $lookup): ?int
{
    $token = normalizeToken($nameRaw);
    if ($token === '') return null;

    $tokenToIds = $lookup['tokenToIds'] ?? [];

    $ids = $tokenToIds[$token] ?? [];
    if (count($ids) === 1) return (int)$ids[0];

    $parts = preg_split('/\s+/u', $token) ?: [];
    if (count($parts) >= 2) {
        $short2 = normalizeWhitespace($parts[0] . ' ' . $parts[1]);
        $ids2 = $tokenToIds[$short2] ?? [];
        if (count($ids2) === 1) return (int)$ids2[0];
    }

    return null;
}

function collectTeacherBlocks($sheet): array
{
    $blocks = [];
    $maxCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());

    for ($col = 4; $col <= $maxCol; $col += 3) {
        if ($col + 2 > $maxCol) break;

        $subjectCol = Coordinate::stringFromColumnIndex($col);
        $groupCol = Coordinate::stringFromColumnIndex($col + 1);
        $roomCol = Coordinate::stringFromColumnIndex($col + 2);

        $teacherRaw = normalizeWhitespace(cellString($sheet->getCell($subjectCol . '9')->getValue()));
        if ($teacherRaw === '') continue;

        $blocks[] = [
            'teacher_raw' => $teacherRaw,
            'subject_col' => $subjectCol,
            'type_col' => $subjectCol,
            'group_col' => $groupCol,
            'room_col' => $roomCol,
            'teacher_id' => null,
        ];
    }

    return $blocks;
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

function fetchTeacherScheduleMatrix($conn, int $weekId, array $teacherIds): array
{
    if (!$teacherIds) return [];

    $scheduleSoft = hasColumn($conn, 'TB_Schedule', 'IsDeleted') ? ' AND s.IsDeleted = 0' : '';
    $roomSoft = hasColumn($conn, 'TB_Room', 'IsDeleted') ? ' AND r.IsDeleted = 0' : '';
    $groupSoft = hasColumn($conn, 'TB_Group', 'GroupDeleted') ? ' AND g.GroupDeleted = 0' : '';
    $discSoft = hasColumn($conn, 'TB_Discipl', 'DisciplDeleted') ? ' AND d.DisciplDeleted = 0' : '';
    $placeholders = implode(',', array_fill(0, count($teacherIds), '?'));

    $sql = "
      SELECT
        s.idTeacher AS teacher_id,
        s.DayOfWeek AS day_of_week,
        s.TimeSlot AS time_slot,
        s.CustomText AS custom_text,
        d.DisciplName AS subject_name,
        d.DisciplShortName AS subject_short,
        tt.TimeTypeName AS type_name,
        g.GroupShortName AS group_short,
        g.GroupName AS group_name,
        r.Building AS building,
        r.RoomNumber AS room_number
      FROM TB_Schedule s
      LEFT JOIN TB_Discipl d ON d.idDiscipl = s.idDiscipl{$discSoft}
      LEFT JOIN TB_TimeType tt ON tt.idTimeType = s.idLessonType
      LEFT JOIN TB_Group g ON g.idGroup = s.idGroup{$groupSoft}
      LEFT JOIN TB_Room r ON r.idRoom = s.idRoom{$roomSoft}
      WHERE s.idWeek = ?{$scheduleSoft}
        AND s.idTeacher IN ({$placeholders})
      ORDER BY s.idTeacher, s.DayOfWeek, s.TimeSlot, s.idSchedule
    ";

    $params = array_merge([$weekId], $teacherIds);
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

        $teacherId = (int)rowValue($row, 'teacher_id', 0);
        $day = (int)rowValue($row, 'day_of_week', 0);
        $slot = (int)rowValue($row, 'time_slot', 0);
        if ($teacherId <= 0 || $day <= 0 || $slot <= 0) continue;

        if (!isset($matrix[$teacherId][$day][$slot])) {
            $matrix[$teacherId][$day][$slot] = [
                'subjects' => [],
                'types' => [],
                'groups' => [],
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

        $group = normalizeWhitespace((string)rowValue($row, 'group_short', ''));
        if ($group === '') {
            $group = normalizeWhitespace((string)rowValue($row, 'group_name', ''));
        }

        $room = formatRoom(
            (string)rowValue($row, 'building', ''),
            (string)rowValue($row, 'room_number', '')
        );

        pushUnique($matrix[$teacherId][$day][$slot]['subjects'], $subjectText);
        pushUnique($matrix[$teacherId][$day][$slot]['types'], $typeName);
        pushUnique($matrix[$teacherId][$day][$slot]['groups'], $group);
        pushUnique($matrix[$teacherId][$day][$slot]['rooms'], $room);
    }

    foreach ($matrix as $teacherId => $days) {
        foreach ($days as $day => $slots) {
            foreach ($slots as $slot => $entry) {
                $matrix[$teacherId][$day][$slot] = [
                    'subject' => joinParts($entry['subjects']),
                    'type' => joinParts($entry['types']),
                    'group' => joinParts($entry['groups']),
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
        if (!isset($dayNames[$day])) continue;
        $date = $weekStart->modify('+' . ($day - 1) . ' days')->format('d.m.Y');
        $sheet->setCellValueExplicit('A' . $row, $dayNames[$day] . ' ' . $date, DataType::TYPE_STRING);
    }
}

function clearAndFillTeacherLessons($sheet, array $blocks, array $pairRowsByDay, array $matrix): void
{
    foreach ($blocks as $block) {
        $teacherId = isset($block['teacher_id']) ? (int)$block['teacher_id'] : 0;
        $subjectCol = $block['subject_col'];
        $typeCol = $block['type_col'];
        $groupCol = $block['group_col'];
        $roomCol = $block['room_col'];

        foreach ($pairRowsByDay as $day => $rows) {
            foreach ($rows as $pair => $startRow) {
                $startRow = (int)$startRow;
                $subjectAddr = $subjectCol . $startRow;
                $typeAddr = $typeCol . ($startRow + 1);
                $groupAddr = $groupCol . ($startRow + 1);
                $roomAddr = $roomCol . $startRow;

                $sheet->setCellValueExplicit($subjectAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($typeAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($groupAddr, '', DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($roomAddr, '', DataType::TYPE_STRING);

                if ($teacherId <= 0) continue;

                $entry = $matrix[$teacherId][$day][$pair] ?? null;
                if (!$entry) continue;

                $sheet->setCellValueExplicit($subjectAddr, (string)($entry['subject'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($typeAddr, (string)($entry['type'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($groupAddr, (string)($entry['group'] ?? ''), DataType::TYPE_STRING);
                $sheet->setCellValueExplicit($roomAddr, (string)($entry['room'] ?? ''), DataType::TYPE_STRING);
            }
        }
    }
}

$conn = null;
try {
    $conn = getDBConnection();
    $weekId = resolveWeekId($conn);
    $weekStart = fetchWeekStartDate($conn, $weekId);

    $blocks = collectTeacherBlocks($sheet);
    if (!$blocks) {
        throw new RuntimeException('NO_TEACHERS_IN_TEMPLATE');
    }

    $lookup = buildTeacherLookup($conn);
    $teacherIds = [];
    $unresolved = [];

    foreach ($blocks as $idx => $block) {
        $id = resolveTeacherIdByName((string)$block['teacher_raw'], $lookup);
        $blocks[$idx]['teacher_id'] = $id;

        if ($id !== null) {
            if (!in_array($id, $teacherIds, true)) {
                $teacherIds[] = $id;
            }
        } else {
            $unresolved[] = $block['teacher_raw'];
        }
    }

    $matrix = fetchTeacherScheduleMatrix($conn, $weekId, $teacherIds);
    $dayStartRows = buildDayStartRows($sheet);
    $pairRowsByDay = buildPairRowsByDay($sheet, $dayStartRows);

    fillDayHeaders($sheet, $dayStartRows, $weekStart);
    clearAndFillTeacherLessons($sheet, $blocks, $pairRowsByDay, $matrix);

    if ((string)($_GET['debug'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'week_id' => $weekId,
            'teachers_from_template' => array_map(
                static fn(array $b) => $b['teacher_raw'],
                $blocks
            ),
            'teacher_ids_resolved' => $teacherIds,
            'teachers_unresolved' => array_values(array_unique($unresolved)),
            'day_start_rows' => $dayStartRows,
            'pairs_by_day' => $pairRowsByDay,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    $fileName = 'setka_prepodavateley_week_' . $weekId . '_' . date('Y-m-d_H-i-s') . '.xlsx';
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
