<?php
declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/../backend/config/db.php';

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

$templatePath = __DIR__ . '/setka_kabinetov.xlsx';
$spreadsheet = IOFactory::load($templatePath);

function cellString($value): string {
    if ($value === null) return '';
    if (is_string($value)) return $value;
    return (string)$value;
}

function toUtf8String(string $value): string {
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

function normalizeWhitespace(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function normalizeCyrillicBuildingLetters(string $value): string {
    $value = preg_replace('/\x{0410}/u', 'A', $value) ?? $value; // А
    $value = preg_replace('/\x{0412}/u', 'B', $value) ?? $value; // В
    $value = preg_replace('/\x{0421}/u', 'C', $value) ?? $value; // С
    $value = preg_replace('/\x{0414}/u', 'D', $value) ?? $value; // Д
    return $value;
}

function normalizeBuilding(string $value): string {
    $value = normalizeWhitespace(mb_strtoupper($value, 'UTF-8'));
    if ($value === '') return '';

    $value = normalizeCyrillicBuildingLetters($value);

    if (preg_match('/[ABCD]/', $value, $m)) {
        return $m[0];
    }

    return '';
}

function normalizeRoomCode(string $value): string {
    $value = normalizeWhitespace(mb_strtoupper($value, 'UTF-8'));
    if ($value === '') return '';

    $value = normalizeCyrillicBuildingLetters($value);

    $value = preg_replace('/[^0-9A-Z]/u', '', $value) ?? '';
    if (preg_match('/^[ABCD](\d{1,4}[A-Z]?)$/', $value, $m)) {
        return (string)$m[1];
    }
    return $value;
}

function normalizeRoomText(string $value): string {
    $value = normalizeWhitespace(mb_strtoupper($value, 'UTF-8'));
    if ($value === '') return '';

    $value = normalizeCyrillicBuildingLetters($value);

    $value = preg_replace('/[^0-9A-ZА-ЯЁ]/u', '', $value) ?? '';
    return $value;
}

function parseHeaderCodes(string $headerRaw): array {
    $header = normalizeWhitespace(mb_strtoupper($headerRaw, 'UTF-8'));
    if ($header === '') return [];

    $prepared = normalizeCyrillicBuildingLetters($header);

    if (!preg_match_all('/([ABCD])?\s*(\d{1,4}[A-Z]?)/u', $prepared, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $out = [];
    foreach ($matches as $m) {
        $out[] = [
            'building' => isset($m[1]) ? normalizeBuilding((string)$m[1]) : '',
            'code' => normalizeRoomCode((string)$m[2]),
        ];
    }

    return $out;
}

function addAlias(array &$map, string $alias, int $col): void {
    if ($alias === '') return;

    if (!isset($map[$alias])) {
        $map[$alias] = [$col];
        return;
    }

    if (!in_array($col, $map[$alias], true)) {
        $map[$alias][] = $col;
    }
}

function buildPairToStartRowMap($sheet): array {
    $map = [];
    $maxRow = $sheet->getHighestRow();

    for ($row = 1; $row <= $maxRow; $row++) {
        $raw = $sheet->getCell('B' . $row)->getValue();
        $value = trim((string)$raw);
        if (!preg_match('/^\d{1,2}$/u', $value)) continue;

        $pair = (int)$value;
        if ($pair >= 1 && $pair <= 30 && !isset($map[$pair])) {
            $map[$pair] = $row;
        }
    }

    return $map;
}

function buildColumnBuildingMap($sheet): array {
    $map = [];

    foreach ($sheet->getMergeCells() as $range) {
        $parts = explode(':', $range, 2);
        $start = $parts[0];
        $end = $parts[1] ?? $parts[0];

        [$sColL, $sRowN] = Coordinate::coordinateFromString($start);
        [$eColL, $eRowN] = Coordinate::coordinateFromString($end);

        $rMin = min((int)$sRowN, (int)$eRowN);
        $rMax = max((int)$sRowN, (int)$eRowN);

        if ($rMin > 44 || $rMax < 44) continue;

        $label = normalizeWhitespace(cellString($sheet->getCell($start)->getValue()));
        if ($label === '') continue;

        $labelU = mb_strtoupper($label, 'UTF-8');
        $m = null;
        if (!preg_match('/КОРПУС\s*([ABCDАВСД])/u', $labelU, $m)
            && !preg_match('/([ABCDАВСД])\s*КОРПУС/u', $labelU, $m)
            && !preg_match('/(?:^|\s)([ABCDАВСД])(?:\s|$)/u', $labelU, $m)) {
            continue;
        }

        $building = normalizeBuilding((string)$m[1]);
        if ($building === '') continue;

        $cMin = min(Coordinate::columnIndexFromString($sColL), Coordinate::columnIndexFromString($eColL));
        $cMax = max(Coordinate::columnIndexFromString($sColL), Coordinate::columnIndexFromString($eColL));

        for ($c = $cMin; $c <= $cMax; $c++) {
            $map[$c] = $building;
        }
    }

    return $map;
}

function buildRoomColumnIndex($sheet): array {
    $aliases = [];
    $colBuilding = buildColumnBuildingMap($sheet);
    $row = 2;

    $maxCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    for ($col = 3; $col <= $maxCol; $col++) {
        $addr = Coordinate::stringFromColumnIndex($col) . $row;
        $headerRaw = normalizeWhitespace(cellString($sheet->getCell($addr)->getValue()));
        if ($headerRaw === '') continue;

        $zoneBuilding = $colBuilding[$col] ?? '';
        $headerTextAlias = normalizeRoomText($headerRaw);

        $codes = parseHeaderCodes($headerRaw);
        foreach ($codes as $codeItem) {
            $code = $codeItem['code'] ?? '';
            if ($code === '') continue;

            $explicitBuilding = $codeItem['building'] ?? '';
            $building = $explicitBuilding !== '' ? $explicitBuilding : $zoneBuilding;

            if ($building !== '') addAlias($aliases, 'BR:' . $building . ':' . $code, $col);
            addAlias($aliases, 'R:' . $code, $col);
        }

        if ($headerTextAlias !== '') {
            if ($zoneBuilding !== '') addAlias($aliases, 'TXT:' . $zoneBuilding . ':' . $headerTextAlias, $col);
            addAlias($aliases, 'TXT:' . $headerTextAlias, $col);
        }
    }

    return [
        'aliases' => $aliases,
        'colBuilding' => $colBuilding,
    ];
}

function pickColumn(array $cols): ?int {
    if (count($cols) < 1) return null;
    return (int)$cols[0];
}

function resolveRoomColumn(array $indexData, string $buildingRaw, string $roomNumberRaw): ?int {
    $aliases = $indexData['aliases'] ?? [];
    $colBuilding = $indexData['colBuilding'] ?? [];

    $building = normalizeBuilding($buildingRaw);
    if ($building === '' && preg_match('/([ABCDАВСД])\s*[- ]?\s*\d/u', mb_strtoupper($roomNumberRaw, 'UTF-8'), $m)) {
        $building = normalizeBuilding((string)$m[1]);
    }

    $roomCode = normalizeRoomCode($roomNumberRaw);
    $roomText = normalizeRoomText($roomNumberRaw);

    $keys = [];
    if ($building !== '' && $roomCode !== '') $keys[] = 'BR:' . $building . ':' . $roomCode;
    if ($roomCode !== '') $keys[] = 'R:' . $roomCode;
    if ($building !== '' && $roomText !== '') $keys[] = 'TXT:' . $building . ':' . $roomText;
    if ($roomText !== '') $keys[] = 'TXT:' . $roomText;

    foreach ($keys as $key) {
        if (!isset($aliases[$key])) continue;

        $cols = $aliases[$key];
        $filtered = $cols;

        if ($building !== '') {
            $filtered = [];
            foreach ($cols as $col) {
                $zone = $colBuilding[$col] ?? '';
                if ($zone === '' || $zone === $building) {
                    $filtered[] = $col;
                }
            }

            $picked = pickColumn($filtered);
            if ($picked !== null) return $picked;
            continue;
        }

        $picked = pickColumn($filtered);
        if ($picked !== null) return $picked;
    }

    return null;
}

function unmergeAnyOverlappingCell($sheet, string $cellAddr): void {
    $cellAddrU = strtoupper($cellAddr);
    [$cellColL, $cellRowN] = Coordinate::coordinateFromString($cellAddrU);

    $cellCol = Coordinate::columnIndexFromString($cellColL);
    $cellRow = (int)$cellRowN;

    foreach ($sheet->getMergeCells() as $range) {
        $rangeU = strtoupper($range);
        $parts = explode(':', $rangeU, 2);

        $start = $parts[0];
        $end = $parts[1] ?? $parts[0];

        [$sColL, $sRowN] = Coordinate::coordinateFromString($start);
        [$eColL, $eRowN] = Coordinate::coordinateFromString($end);

        $cMin = min(Coordinate::columnIndexFromString($sColL), Coordinate::columnIndexFromString($eColL));
        $cMax = max(Coordinate::columnIndexFromString($sColL), Coordinate::columnIndexFromString($eColL));
        $rMin = min((int)$sRowN, (int)$eRowN);
        $rMax = max((int)$sRowN, (int)$eRowN);

        $inside = ($cellCol >= $cMin && $cellCol <= $cMax && $cellRow >= $rMin && $cellRow <= $rMax);
        if ($inside) {
            $sheet->unmergeCells($range);
        }
    }
}

function styleCell($sheet, string $addr): void {
    $sheet->getStyle($addr)->getAlignment()->setWrapText(true);
    $sheet->getStyle($addr)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
    $sheet->getStyle($addr)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
}

function writeGroupsToCells($sheet, string $colLetter, int $startRow, array $groups): void {
    $groupLines = [];

    foreach ($groups as $group) {
        if ($group === null) continue;
        $parts = preg_split('/\R/u', (string)$group) ?: [];
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') $groupLines[] = $part;
        }
    }

    $groupLines = array_values(array_unique($groupLines));
    $groupLines = array_slice($groupLines, 0, 3);

    $addrG1 = $colLetter . $startRow;
    $addrG2 = $colLetter . ($startRow + 1);
    $addrG3 = $colLetter . ($startRow + 2);

    unmergeAnyOverlappingCell($sheet, $addrG1);
    unmergeAnyOverlappingCell($sheet, $addrG2);
    unmergeAnyOverlappingCell($sheet, $addrG3);

    $sheet->setCellValueExplicit($addrG1, '', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit($addrG2, '', DataType::TYPE_STRING);
    $sheet->setCellValueExplicit($addrG3, '', DataType::TYPE_STRING);

    if (isset($groupLines[0])) $sheet->setCellValueExplicit($addrG1, $groupLines[0], DataType::TYPE_STRING);
    if (isset($groupLines[1])) $sheet->setCellValueExplicit($addrG2, $groupLines[1], DataType::TYPE_STRING);
    if (isset($groupLines[2])) $sheet->setCellValueExplicit($addrG3, $groupLines[2], DataType::TYPE_STRING);

    styleCell($sheet, $addrG1);
    styleCell($sheet, $addrG2);
    styleCell($sheet, $addrG3);
}

function rowValue(array $row, string $key, $default = null) {
    if (array_key_exists($key, $row)) return $row[$key];

    $upper = strtoupper($key);
    if (array_key_exists($upper, $row)) return $row[$upper];

    $lower = strtolower($key);
    if (array_key_exists($lower, $row)) return $row[$lower];

    return $default;
}

function normalizeDbRowText(array $row): array {
    foreach ($row as $k => $v) {
        if (is_string($v)) {
            $row[$k] = toUtf8String($v);
        }
    }
    return $row;
}

function hasColumn($conn, string $tableName, string $columnName): bool {
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

function resolveWeekId($conn): int {
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

function formatTeacherShort(string $surname, string $firstName, string $lastName): string {
    $surname = trim($surname);
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    if ($surname === '' && $firstName === '' && $lastName === '') {
        return '';
    }

    $i1 = $firstName !== '' ? mb_substr($firstName, 0, 1) . '.' : '';
    $i2 = $lastName !== '' ? mb_substr($lastName, 0, 1) . '.' : '';

    return trim($surname . ' ' . $i1 . $i2);
}

function makeKindLabel(string $typeName, string $subjectShort, string $subjectName): string {
    $typeName = trim($typeName);
    $subjectShort = trim($subjectShort);
    $subjectName = trim($subjectName);

    if ($typeName !== '') return $typeName;
    if ($subjectShort !== '') return $subjectShort;
    return $subjectName;
}

function fetchRoomGridItemsFromDb($conn, int $weekId): array {
    $scheduleSoft = hasColumn($conn, 'TB_Schedule', 'IsDeleted') ? ' AND s.IsDeleted = 0' : '';
    $roomSoft = hasColumn($conn, 'TB_Room', 'IsDeleted') ? ' AND r.IsDeleted = 0' : '';
    $groupSoft = hasColumn($conn, 'TB_Group', 'GroupDeleted') ? ' AND g.GroupDeleted = 0' : '';

    $sql = "
      SELECT
        s.idSchedule AS schedule_id,
        s.DayOfWeek AS day_of_week,
        s.TimeSlot AS time_slot,
        r.Building AS building,
        r.RoomNumber AS room_number,
        t.TeacherSurname AS teacher_surname,
        t.TeacherFirstName AS teacher_first,
        t.TeacherLastName AS teacher_last,
        tt.TimeTypeName AS type_name,
        d.DisciplShortName AS subject_short,
        d.DisciplName AS subject_name,
        g.GroupShortName AS group_short,
        g.GroupName AS group_name
      FROM TB_Schedule s
      JOIN TB_Room r ON r.idRoom = s.idRoom{$roomSoft}
      LEFT JOIN TB_Teacher t ON t.idTeacher = s.idTeacher
      LEFT JOIN TB_TimeType tt ON tt.idTimeType = s.idLessonType
      LEFT JOIN TB_Discipl d ON d.idDiscipl = s.idDiscipl
      LEFT JOIN TB_Group g ON g.idGroup = s.idGroup{$groupSoft}
      WHERE s.idWeek = ?{$scheduleSoft}
      ORDER BY s.DayOfWeek, s.TimeSlot, r.Building, r.RoomNumber, s.idSchedule
    ";

    $st = @odbc_prepare($conn, $sql);
    if (!$st) {
        throw new RuntimeException('LESSONS_PREPARE_FAILED: ' . (string)odbc_errormsg($conn));
    }

    if (!@odbc_execute($st, [$weekId])) {
        throw new RuntimeException('LESSONS_EXECUTE_FAILED: ' . (string)odbc_errormsg($st));
    }

    $items = [];
    while ($row = odbc_fetch_array($st)) {
        $row = normalizeDbRowText($row);
        $day = (int)rowValue($row, 'day_of_week', 0);
        $slot = (int)rowValue($row, 'time_slot', 0);
        $scheduleId = (int)rowValue($row, 'schedule_id', 0);

        $building = normalizeWhitespace((string)rowValue($row, 'building', ''));
        $roomNumber = normalizeWhitespace((string)rowValue($row, 'room_number', ''));

        if ($day <= 0 || $slot <= 0 || $scheduleId <= 0 || $roomNumber === '') continue;

        $key = $day . '|' . $slot . '|' . normalizeBuilding($building) . '|' . normalizeRoomCode($roomNumber);
        if (!isset($items[$key])) {
            $teacher = formatTeacherShort(
                (string)rowValue($row, 'teacher_surname', ''),
                (string)rowValue($row, 'teacher_first', ''),
                (string)rowValue($row, 'teacher_last', '')
            );

            $kind = makeKindLabel(
                (string)rowValue($row, 'type_name', ''),
                (string)rowValue($row, 'subject_short', ''),
                (string)rowValue($row, 'subject_name', '')
            );

            $items[$key] = [
                'day_of_week' => $day,
                'time_slot' => $slot,
                'building' => $building,
                'room_number' => $roomNumber,
                'groups' => [],
                'teacher' => $teacher,
                'kind' => $kind,
            ];
        } else {
            $teacher = formatTeacherShort(
                (string)rowValue($row, 'teacher_surname', ''),
                (string)rowValue($row, 'teacher_first', ''),
                (string)rowValue($row, 'teacher_last', '')
            );
            if ($teacher !== '' && $teacher !== $items[$key]['teacher']) {
                $items[$key]['teacher'] = trim($items[$key]['teacher'] . ' / ' . $teacher, ' /');
            }

            $kind = makeKindLabel(
                (string)rowValue($row, 'type_name', ''),
                (string)rowValue($row, 'subject_short', ''),
                (string)rowValue($row, 'subject_name', '')
            );
            if ($kind !== '' && $kind !== $items[$key]['kind']) {
                $items[$key]['kind'] = trim($items[$key]['kind'] . ' / ' . $kind, ' /');
            }
        }

        $group = trim((string)rowValue($row, 'group_short', ''));
        if ($group === '') {
            $group = trim((string)rowValue($row, 'group_name', ''));
        }

        if ($group !== '' && !in_array($group, $items[$key]['groups'], true)) {
            $items[$key]['groups'][] = $group;
        }
    }

    return array_values($items);
}

function normalizeSheetTitle(string $title): string {
    $value = normalizeWhitespace(mb_strtolower(toUtf8String($title), 'UTF-8'));
    $value = str_replace("\u{0451}", "\u{0435}", $value); // ё -> е
    return $value;
}

function resolveDayToSheetMap($spreadsheet): array {
    $aliases = [
        1 => ['pn', 'mon', 'monday', "\u{043f}\u{043d}", "\u{043f}\u{043e}\u{043d}", "\u{043f}\u{043e}\u{043d}\u{0435}\u{0434}\u{0435}\u{043b}\u{044c}\u{043d}\u{0438}\u{043a}"],
        2 => ['vt', 'tue', 'tuesday', "\u{0432}\u{0442}", "\u{0432}\u{0442}\u{043e}\u{0440}", "\u{0432}\u{0442}\u{043e}\u{0440}\u{043d}\u{0438}\u{043a}"],
        3 => ['sr', 'wed', 'wednesday', "\u{0441}\u{0440}", "\u{0441}\u{0440}\u{0435}\u{0434}\u{0430}"],
        4 => ['cht', 'thu', 'thursday', "\u{0447}\u{0442}", "\u{0447}\u{0435}\u{0442}", "\u{0447}\u{0435}\u{0442}\u{0432}\u{0435}\u{0440}\u{0433}"],
        5 => ['pt', 'fri', 'friday', "\u{043f}\u{0442}", "\u{043f}\u{044f}\u{0442}", "\u{043f}\u{044f}\u{0442}\u{043d}\u{0438}\u{0446}\u{0430}"],
        6 => ['sb', 'sat', 'saturday', "\u{0441}\u{0431}", "\u{0441}\u{0443}\u{0431}", "\u{0441}\u{0443}\u{0431}\u{0431}\u{043e}\u{0442}\u{0430}"],
        7 => ['vs', 'sun', 'sunday', "\u{0432}\u{0441}", "\u{0432}\u{043e}\u{0441}", "\u{0432}\u{043e}\u{0441}\u{043a}\u{0440}\u{0435}\u{0441}\u{0435}\u{043d}\u{044c}\u{0435}"],
    ];

    $allSheets = $spreadsheet->getAllSheets();
    $normalized = [];
    foreach ($allSheets as $sheet) {
        $title = $sheet->getTitle();
        $normalized[$title] = normalizeSheetTitle($title);
    }

    $map = [];
    $used = [];

    foreach ($aliases as $day => $tokens) {
        foreach ($normalized as $sheetTitle => $sheetNorm) {
            if (isset($used[$sheetTitle])) continue;

            foreach ($tokens as $token) {
                if ($sheetNorm === $token || strpos($sheetNorm, $token) === 0) {
                    $map[$day] = $sheetTitle;
                    $used[$sheetTitle] = true;
                    break 2;
                }
            }
        }
    }

    for ($day = 1; $day <= 7; $day++) {
        if (isset($map[$day])) continue;
        $idx = $day - 1;
        if (isset($allSheets[$idx])) {
            $map[$day] = $allSheets[$idx]->getTitle();
        }
    }

    return $map;
}

$conn = null;
try {
    $conn = getDBConnection();
    $weekId = resolveWeekId($conn);
    $items = fetchRoomGridItemsFromDb($conn, $weekId);
    $debugRows = [];
    $mappedCount = 0;

    $dayToSheet = resolveDayToSheetMap($spreadsheet);

    $itemsBySheet = [];
    foreach ($items as $item) {
        $sheetName = $dayToSheet[(int)$item['day_of_week']] ?? '';
        if ($sheetName === '') continue;
        $itemsBySheet[$sheetName][] = $item;
    }

    foreach ($dayToSheet as $sheetName) {
        $sheet = $spreadsheet->getSheetByName($sheetName);
        if (!$sheet) continue;

        $roomIndex = buildRoomColumnIndex($sheet);
        $pairToRow = buildPairToStartRowMap($sheet);
        $dayItems = $itemsBySheet[$sheetName] ?? [];

        foreach ($dayItems as $item) {
            $col = resolveRoomColumn($roomIndex, (string)$item['building'], (string)$item['room_number']);
            $startRow = $pairToRow[(int)$item['time_slot']] ?? null;
            if (!$startRow) {
                $debugRows[] = [
                    'sheet' => $sheetName,
                    'reason' => 'PAIR_NOT_FOUND',
                    'building' => $item['building'],
                    'room_number' => $item['room_number'],
                    'time_slot' => $item['time_slot'],
                    'groups' => $item['groups'],
                ];
                continue;
            }

            if (!$col) {
                $debugRows[] = [
                    'sheet' => $sheetName,
                    'reason' => 'ROOM_NOT_MAPPED',
                    'building' => $item['building'],
                    'room_number' => $item['room_number'],
                    'time_slot' => $item['time_slot'],
                    'groups' => $item['groups'],
                ];
                continue;
            }

            $colL = Coordinate::stringFromColumnIndex($col);
            writeGroupsToCells($sheet, $colL, $startRow, $item['groups']);

            $addrTeacher = $colL . ($startRow + 3);
            $addrKind = $colL . ($startRow + 4);

            unmergeAnyOverlappingCell($sheet, $addrTeacher);
            unmergeAnyOverlappingCell($sheet, $addrKind);

            $sheet->setCellValueExplicit($addrTeacher, (string)$item['teacher'], DataType::TYPE_STRING);
            $sheet->setCellValueExplicit($addrKind, (string)$item['kind'], DataType::TYPE_STRING);

            styleCell($sheet, $addrTeacher);
            styleCell($sheet, $addrKind);
            $mappedCount++;
        }
    }

    if ((string)($_GET['debug'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'week_id' => $weekId,
            'items_total' => count($items),
            'items_mapped' => $mappedCount,
            'items_unmapped' => count($debugRows),
            'unmapped' => $debugRows,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');

    $fileName = 'setka_kabinetov_week_' . $weekId . '_' . date('Y-m-d_H-i-s') . '.xlsx';

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
