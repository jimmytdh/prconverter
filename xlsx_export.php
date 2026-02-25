<?php

declare(strict_types=1);

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function colLetter(int $index): string
{
    $result = '';
    while ($index > 0) {
        $index--;
        $result = chr(65 + ($index % 26)) . $result;
        $index = intdiv($index, 26);
    }
    return $result;
}

function buildCell(string $ref, mixed $value, int $style = 0, bool $numeric = false): string
{
    if ($value === null || $value === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }

    if ($numeric) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . xmlEscape((string) $value) . '</v></c>';
    }

    return '<c r="' . $ref . '" t="inlineStr" s="' . $style . '"><is><t>' . xmlEscape((string) $value) . '</t></is></c>';
}

function estimateRowHeight(string $text, int $base = 24): int
{
    $len = strlen($text);
    if ($len <= 60) {
        return $base;
    }

    $extraLines = (int) ceil(($len - 60) / 45);
    return min(140, $base + ($extraLines * 14));
}

function textLen(string $text): int
{
    return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
}

function estimateWrappedLineCount(string $text, int $charsPerLine): int
{
    $text = str_replace("\r", '', $text);
    $parts = explode("\n", $text);
    $lines = 0;
    $charsPerLine = max(1, $charsPerLine);

    foreach ($parts as $part) {
        $len = textLen($part);
        $lines += max(1, (int) ceil($len / $charsPerLine));
    }

    return max(1, $lines);
}

function estimateMergedRowHeight(
    string $text,
    array $columnWidths,
    int $startCol,
    int $endCol,
    int $minHeight = 24,
    int $maxHeight = 180
): int {
    $width = 0.0;
    for ($c = $startCol; $c <= $endCol; $c++) {
        $width += (float) ($columnWidths[$c] ?? 10);
    }

    // Excel column width is roughly character capacity; apply small padding discount.
    $charsPerLine = max(8, (int) floor($width - 2));
    $lineCount = estimateWrappedLineCount($text, $charsPerLine);

    // Approximate Calibri/Cambria 12pt wrapped line height in points.
    $height = (int) ceil(($lineCount * 15) + 8);

    return max($minHeight, min($maxHeight, $height));
}

function normalizeExportItems(array $row): array
{
    $items = [];
    if (isset($row['items']) && is_array($row['items'])) {
        $items = $row['items'];
    }

    if ($items === []) {
        $items = [[
            'stock_property_no' => $row['stock_property_no'] ?? null,
            'unit' => $row['unit'] ?? null,
            'item_description' => $row['item_description'] ?? null,
            'quantity' => $row['quantity'] ?? null,
            'unit_cost' => $row['unit_cost'] ?? null,
            'total_cost' => $row['item_total_cost'] ?? ($row['total_cost'] ?? null),
        ]];
    }

    $out = [];
    foreach ($items as $item) {
        $stock = trim((string) ($item['stock_property_no'] ?? ''));
        $unitFallback = trim((string) ($item['unit'] ?? ''));
        $desc = trim((string) ($item['item_description'] ?? ''));
        $qty = $item['quantity'] ?? null;
        $unitCost = $item['unit_cost'] ?? null;
        $itemTotal = $item['total_cost'] ?? null;

        if ($stock === '' && $unitFallback === '' && $desc === '' && $qty === null && $unitCost === null && $itemTotal === null) {
            continue;
        }

        // Per requirement: place stock/property no. under Unit column in Excel.
        $unitDisplay = $stock !== '' ? $stock : $unitFallback;

        $out[] = [
            'unit_display' => $unitDisplay !== '' ? $unitDisplay : null,
            'item_description' => $desc !== '' ? $desc : null,
            'quantity' => is_numeric((string) $qty) ? (float) $qty : null,
            'unit_cost' => is_numeric((string) $unitCost) ? (float) $unitCost : null,
            'total_cost' => is_numeric((string) $itemTotal) ? (float) $itemTotal : null,
        ];
    }

    if ($out === []) {
        $out[] = [
            'unit_display' => null,
            'item_description' => null,
            'quantity' => null,
            'unit_cost' => null,
            'total_cost' => null,
        ];
    }

    return $out;
}

function createPrTemplateXlsx(array $row, string $targetPath): void
{
    $fundCluster = trim((string) ($row['fund_cluster'] ?? ''));
    $prNo = trim((string) ($row['pr_no'] ?? ''));
    $requestDate = trim((string) ($row['request_date'] ?? ''));
    $rcc = trim((string) ($row['responsibility_center_code'] ?? ''));
    $totalCost = is_numeric((string) ($row['total_cost'] ?? null)) ? (float) $row['total_cost'] : null;
    $requestedBy = trim((string) ($row['requested_by'] ?? ''));
    $designation1 = trim((string) ($row['designation1'] ?? ''));
    $approvedBy = trim((string) ($row['approved_by'] ?? ''));
    $designation2 = trim((string) ($row['designation2'] ?? ''));

    $entityName = 'Entity Name: CEBU SOUTH MEDICAL CENTER';
    $requestedBlock = trim($requestedBy . "\n" . $designation1);
    $approvedBlock = trim($approvedBy . "\n" . $designation2);
    $purposeText = "Purpose:\nState your purpose here..";

    $items = normalizeExportItems($row);
    $itemCount = max(1, count($items));

    $headerSpacerRows = 5;
    $footerSpacerRows = 5;
    $rr = static fn(int $r): int => $r + $headerSpacerRows;

    $rowTableStart = $rr(1);
    $rowItemStart = $rr(7);
    $rowItemEnd = $rowItemStart + $itemCount - 1;
    $rowTotalStart = $rowItemEnd + 1;
    $rowTotalEnd = $rowTotalStart + 1;
    $rowPurposeStart = $rowTotalEnd + 1;
    $rowPurposeEnd = $rowPurposeStart + 2;
    $rowReqHeaderStart = $rowPurposeEnd + 1;
    $rowReqHeaderEnd = $rowReqHeaderStart + 1;
    $rowSigStart = $rowReqHeaderEnd + 1;
    $rowSigEnd = $rowSigStart + 1;
    $rowFooter = $rowSigEnd + 1;
    $maxRow = $rowFooter + $footerSpacerRows;
    $columnWidths = [
        1 => 16.0,
        2 => 14.0,
        3 => 23.0,
        4 => 10.0,
        5 => 12.0,
        6 => 10.0,
        7 => 10.0,
        8 => 16.0,
    ];

    if ($totalCost === null) {
        $sum = 0.0;
        $hasAny = false;
        foreach ($items as $item) {
            if ($item['total_cost'] !== null) {
                $sum += (float) $item['total_cost'];
                $hasAny = true;
            }
        }
        if ($hasAny) {
            $totalCost = round($sum, 2);
        }
    }

    $cells = [];

    // Initialize all cells as blank/default style.
    for ($r = 1; $r <= $maxRow; $r++) {
        for ($c = 1; $c <= 8; $c++) {
            $ref = colLetter($c) . $r;
            $cells[$ref] = ['value' => null, 'style' => 0, 'numeric' => false];
        }
    }

    // Apply bordered table style only within the table body area.
    for ($r = $rowTableStart; $r <= $rowFooter; $r++) {
        for ($c = 1; $c <= 8; $c++) {
            $ref = colLetter($c) . $r;
            $cells[$ref] = ['value' => null, 'style' => 2, 'numeric' => false];
        }
    }

    // Header/content values.
    $cells['A' . $rr(1)] = ['value' => $entityName, 'style' => 3, 'numeric' => false];
    $cells['G' . $rr(1)] = ['value' => "Fund Cluster:\n" . $fundCluster, 'style' => 3, 'numeric' => false];

    $cells['A' . $rr(3)] = ['value' => 'Office/Section :', 'style' => 3, 'numeric' => false];
    $cells['C' . $rr(3)] = ['value' => 'PR No.: ' . $prNo, 'style' => 3, 'numeric' => false];
    $cells['G' . $rr(3)] = ['value' => 'Date: ' . $requestDate, 'style' => 3, 'numeric' => false];
    $cells['C' . $rr(4)] = ['value' => 'Responsibility Center Code : ' . $rcc, 'style' => 3, 'numeric' => false];

    $cells['A' . $rr(5)] = ['value' => "Stock/\nProperty\nNo.", 'style' => 4, 'numeric' => false];
    $cells['B' . $rr(5)] = ['value' => 'Unit', 'style' => 4, 'numeric' => false];
    $cells['C' . $rr(5)] = ['value' => 'Item Description', 'style' => 4, 'numeric' => false];
    $cells['E' . $rr(5)] = ['value' => 'Quantity', 'style' => 4, 'numeric' => false];
    $cells['G' . $rr(5)] = ['value' => "Unit\nCost", 'style' => 4, 'numeric' => false];
    $cells['H' . $rr(5)] = ['value' => 'Total Cost', 'style' => 4, 'numeric' => false];

    foreach ($items as $idx => $item) {
        $r = $rowItemStart + $idx;
        $cells['A' . $r] = ['value' => '', 'style' => 2, 'numeric' => false];
        $cells['B' . $r] = ['value' => $item['unit_display'], 'style' => 4, 'numeric' => false];
        $cells['C' . $r] = ['value' => $item['item_description'], 'style' => 7, 'numeric' => false];
        $cells['E' . $r] = ['value' => $item['quantity'], 'style' => 5, 'numeric' => true];
        $cells['G' . $r] = ['value' => $item['unit_cost'], 'style' => 5, 'numeric' => true];
        $cells['H' . $r] = ['value' => $item['total_cost'], 'style' => 5, 'numeric' => true];
    }

    $cells['G' . $rowTotalStart] = ['value' => 'Total Cost', 'style' => 6, 'numeric' => false];
    $cells['H' . $rowTotalStart] = ['value' => $totalCost, 'style' => 5, 'numeric' => true];

    $cells['A' . $rowPurposeStart] = ['value' => $purposeText, 'style' => 11, 'numeric' => false];

    $cells['C' . $rowReqHeaderStart] = ['value' => 'Requested by:', 'style' => 6, 'numeric' => false];
    $cells['F' . $rowReqHeaderStart] = ['value' => 'Approved by:', 'style' => 6, 'numeric' => false];

    $cells['A' . $rowSigStart] = ['value' => "Signature :\nPrinted Name :\nDesignation :", 'style' => 7, 'numeric' => false];
    $cells['C' . $rowSigStart] = ['value' => '', 'style' => 7, 'numeric' => false];
    $cells['F' . $rowSigStart] = ['value' => '', 'style' => 7, 'numeric' => false];
    $cells['C' . $rowSigEnd] = ['value' => $requestedBlock, 'style' => 10, 'numeric' => false];
    $cells['F' . $rowSigEnd] = ['value' => $approvedBlock, 'style' => 10, 'numeric' => false];

    $cells['A' . $rowFooter] = ['value' => 'See back page for instructions', 'style' => 8, 'numeric' => false];

    $merges = [
        'A' . $rr(1) . ':F' . $rr(2), 'G' . $rr(1) . ':H' . $rr(2),
        'A' . $rr(3) . ':B' . $rr(4), 'C' . $rr(3) . ':F' . $rr(3), 'C' . $rr(4) . ':F' . $rr(4), 'G' . $rr(3) . ':H' . $rr(4),
        'A' . $rr(5) . ':A' . $rr(6), 'B' . $rr(5) . ':B' . $rr(6), 'C' . $rr(5) . ':D' . $rr(6), 'E' . $rr(5) . ':F' . $rr(6), 'G' . $rr(5) . ':G' . $rr(6), 'H' . $rr(5) . ':H' . $rr(6),
    ];

    for ($r = $rowItemStart; $r <= $rowItemEnd; $r++) {
        $merges[] = 'C' . $r . ':D' . $r;
        $merges[] = 'E' . $r . ':F' . $r;
    }

    $merges[] = 'A' . $rowTotalStart . ':F' . $rowTotalEnd;
    $merges[] = 'G' . $rowTotalStart . ':G' . $rowTotalEnd;
    $merges[] = 'H' . $rowTotalStart . ':H' . $rowTotalEnd;

    $merges[] = 'A' . $rowPurposeStart . ':H' . $rowPurposeEnd;

    $merges[] = 'A' . $rowReqHeaderStart . ':B' . $rowReqHeaderEnd;
    $merges[] = 'C' . $rowReqHeaderStart . ':E' . $rowReqHeaderEnd;
    $merges[] = 'F' . $rowReqHeaderStart . ':H' . $rowReqHeaderEnd;

    $merges[] = 'A' . $rowSigStart . ':B' . $rowSigEnd;
    $merges[] = 'C' . $rowSigStart . ':E' . $rowSigStart;
    $merges[] = 'F' . $rowSigStart . ':H' . $rowSigStart;
    $merges[] = 'C' . $rowSigEnd . ':E' . $rowSigEnd;
    $merges[] = 'F' . $rowSigEnd . ':H' . $rowSigEnd;

    $merges[] = 'A' . $rowFooter . ':H' . $rowFooter;

    $rowsXml = [];
    $rowHeights = [];
    for ($r = 1; $r <= $headerSpacerRows; $r++) {
        $rowHeights[$r] = 24;
    }
    $rowHeights[$rr(1)] = 26;
    $rowHeights[$rr(2)] = 26;
    $rowHeights[$rr(3)] = 24;
    $rowHeights[$rr(4)] = 24;
    $rowHeights[$rr(5)] = 24;
    $rowHeights[$rr(6)] = 24;

    for ($r = $rowItemStart; $r <= $rowItemEnd; $r++) {
        $itemText = (string) ($cells['C' . $r]['value'] ?? '');
        $rowHeights[$r] = estimateMergedRowHeight($itemText, $columnWidths, 3, 4, 26, 160);
    }

    $rowHeights[$rowTotalStart] = 24;
    $rowHeights[$rowTotalEnd] = 24;
    $purposeBlockHeight = estimateMergedRowHeight($purposeText, $columnWidths, 1, 8, 72, 180);
    $purposeRowEach = (int) ceil($purposeBlockHeight / 3);
    $rowHeights[$rowPurposeStart] = $purposeRowEach;
    $rowHeights[$rowPurposeStart + 1] = $purposeRowEach;
    $rowHeights[$rowPurposeEnd] = $purposeRowEach;
    $rowHeights[$rowReqHeaderStart] = 24;
    $rowHeights[$rowReqHeaderEnd] = 24;
    $rowHeights[$rowSigStart] = 24;
    $requestBlockHeight = estimateMergedRowHeight($requestedBlock, $columnWidths, 3, 5, 44, 180);
    $approveBlockHeight = estimateMergedRowHeight($approvedBlock, $columnWidths, 6, 8, 44, 180);
    $rowHeights[$rowSigEnd] = max($requestBlockHeight, $approveBlockHeight);
    $rowHeights[$rowFooter] = 20;
    for ($r = $rowFooter + 1; $r <= $maxRow; $r++) {
        $rowHeights[$r] = 24;
    }

    for ($r = 1; $r <= $maxRow; $r++) {
        $cellXml = '';
        for ($c = 1; $c <= 8; $c++) {
            $ref = colLetter($c) . $r;
            if (!isset($cells[$ref])) {
                continue;
            }
            $entry = $cells[$ref];
            $cellXml .= buildCell($ref, $entry['value'], $entry['style'], $entry['numeric']);
        }

        $height = $rowHeights[$r] ?? 20;
        $rowsXml[] = '<row r="' . $r . '" ht="' . $height . '" customHeight="1">' . $cellXml . '</row>';
    }

    $mergeXml = '';
    foreach ($merges as $merge) {
        $mergeXml .= '<mergeCell ref="' . $merge . '"/>';
    }

    $headerImagePath = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'header.png';
    $footerImagePath = __DIR__ . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'footer.png';

    $imageEntries = [];
    if (is_file($headerImagePath)) {
        $imageEntries[] = [
            'name' => 'Header',
            'path' => $headerImagePath,
            'from_col' => 0,
            'from_row' => 0,
            'to_col' => 8,
            'to_row' => $headerSpacerRows,
        ];
    }
    if (is_file($footerImagePath)) {
        // Place footer image in the reserved blank rows below the table.
        $footerFromRow = $rowFooter; // zero-based anchor for row ($rowFooter + 1)
        $imageEntries[] = [
            'name' => 'Footer',
            'path' => $footerImagePath,
            'from_col' => 0,
            'from_row' => $footerFromRow,
            'to_col' => 8,
            'to_row' => $footerFromRow + $footerSpacerRows,
        ];
    }

    $sheetDrawingRefXml = '';
    $sheetRelsXml = null;
    $drawingXml = null;
    $drawingRelsXml = null;
    if ($imageEntries !== []) {
        $sheetDrawingRefXml = '<drawing r:id="rId1"/>';
        $sheetRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>'
            . '</Relationships>';

        $drawBlocks = '';
        $drawRelBlocks = '';
        foreach ($imageEntries as $i => $entry) {
            $rid = 'rId' . ($i + 1);
            $imgName = 'image' . ($i + 1) . '.png';
            $cNvPrId = (string) ($i + 1);
            $drawBlocks .= '<xdr:twoCellAnchor editAs="oneCell">'
                . '<xdr:from>'
                . '<xdr:col>' . $entry['from_col'] . '</xdr:col><xdr:colOff>0</xdr:colOff>'
                . '<xdr:row>' . $entry['from_row'] . '</xdr:row><xdr:rowOff>0</xdr:rowOff>'
                . '</xdr:from>'
                . '<xdr:to>'
                . '<xdr:col>' . $entry['to_col'] . '</xdr:col><xdr:colOff>0</xdr:colOff>'
                . '<xdr:row>' . $entry['to_row'] . '</xdr:row><xdr:rowOff>0</xdr:rowOff>'
                . '</xdr:to>'
                . '<xdr:pic>'
                . '<xdr:nvPicPr>'
                . '<xdr:cNvPr id="' . $cNvPrId . '" name="' . xmlEscape($entry['name']) . '"/>'
                . '<xdr:cNvPicPr/>'
                . '</xdr:nvPicPr>'
                . '<xdr:blipFill>'
                . '<a:blip r:embed="' . $rid . '"/>'
                . '<a:stretch><a:fillRect/></a:stretch>'
                . '</xdr:blipFill>'
                . '<xdr:spPr>'
                . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>'
                . '</xdr:spPr>'
                . '</xdr:pic>'
                . '<xdr:clientData/>'
                . '</xdr:twoCellAnchor>';

            $drawRelBlocks .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/' . $imgName . '"/>';
        }

        $drawingXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . $drawBlocks
            . '</xdr:wsDr>';

        $drawingRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $drawRelBlocks
            . '</Relationships>';
    }

    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
        . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="20"/>'
        . '<cols>'
        . '<col min="1" max="1" width="' . $columnWidths[1] . '" customWidth="1"/>'
        . '<col min="2" max="2" width="' . $columnWidths[2] . '" customWidth="1"/>'
        . '<col min="3" max="3" width="' . $columnWidths[3] . '" customWidth="1"/>'
        . '<col min="4" max="4" width="' . $columnWidths[4] . '" customWidth="1"/>'
        . '<col min="5" max="5" width="' . $columnWidths[5] . '" customWidth="1"/>'
        . '<col min="6" max="6" width="' . $columnWidths[6] . '" customWidth="1"/>'
        . '<col min="7" max="7" width="' . $columnWidths[7] . '" customWidth="1"/>'
        . '<col min="8" max="8" width="' . $columnWidths[8] . '" customWidth="1"/>'
        . '</cols>'
        . '<sheetData>' . implode('', $rowsXml) . '</sheetData>'
        . '<mergeCells count="' . count($merges) . '">' . $mergeXml . '</mergeCells>'
        . '<pageMargins left="0.25" right="0.25" top="0.30" bottom="0.30" header="0.10" footer="0.10"/>'
        . '<pageSetup paperSize="9" orientation="portrait" fitToWidth="1" fitToHeight="0"/>'
        . $sheetDrawingRefXml
        . '</worksheet>';

    $stylesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
        . '<fonts count="3">'
        . '<font><sz val="12"/><name val="Cambria"/><family val="2"/></font>'
        . '<font><b/><sz val="12"/><name val="Cambria"/><family val="2"/></font>'
        . '<font><i/><b/><sz val="11"/><name val="Cambria"/><family val="2"/></font>'
        . '</fonts>'
        . '<fills count="3">'
        . '<fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFFFFFFF"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        . '<border><left/><right/><top/><bottom/><diagonal/></border>'
        . '<border><left style="thin"><color auto="1"/></left><right style="thin"><color auto="1"/></right><top style="thin"><color auto="1"/></top><bottom style="thin"><color auto="1"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="12">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' // 0
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>' // 1
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>' // 2
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment vertical="center" wrapText="1"/></xf>' // 3
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>' // 4
        . '<xf numFmtId="164" fontId="0" fillId="2" borderId="1" xfId="0" applyNumberFormat="1" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center"/></xf>' // 5
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="center" vertical="center"/></xf>' // 6
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="left" vertical="center" wrapText="1"/></xf>' // 7
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="left" vertical="center"/></xf>' // 8
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' // 9
        . '<xf numFmtId="0" fontId="0" fillId="2" borderId="1" xfId="0" applyFill="1" applyBorder="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>' // 10
        . '<xf numFmtId="0" fontId="2" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"><alignment horizontal="left" vertical="top" wrapText="1"/></xf>' // 11
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $printArea = "'PR'!\$A\$1:\$H\$" . $maxRow;

    $workbookXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="PR" sheetId="1" r:id="rId1"/></sheets>'
        . '<definedNames><definedName name="_xlnm.Print_Area" localSheetId="0">' . $printArea . '</definedName></definedNames>'
        . '</workbook>';

    $contentTypesXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Default Extension="png" ContentType="image/png"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . ($imageEntries !== [] ? '<Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/>' : '')
        . '</Types>';

    $relsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbookRelsXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';

    $zip = new ZipArchive();
    if ($zip->open($targetPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Failed to create XLSX package.');
    }

    $zip->addFromString('[Content_Types].xml', $contentTypesXml);
    $zip->addFromString('_rels/.rels', $relsXml);
    $zip->addFromString('xl/workbook.xml', $workbookXml);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRelsXml);
    $zip->addFromString('xl/styles.xml', $stylesXml);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);
    if ($imageEntries !== [] && $drawingXml !== null && $drawingRelsXml !== null && $sheetRelsXml !== null) {
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $sheetRelsXml);
        $zip->addFromString('xl/drawings/drawing1.xml', $drawingXml);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $drawingRelsXml);
        foreach ($imageEntries as $i => $entry) {
            $bin = file_get_contents($entry['path']);
            if ($bin !== false) {
                $zip->addFromString('xl/media/image' . ($i + 1) . '.png', $bin);
            }
        }
    }
    $zip->close();
}
