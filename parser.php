<?php

declare(strict_types=1);

function cleanValue(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return $value === '' ? null : $value;
}

function parseMoney(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', $value);
    if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function parseFloat(?string $value): ?float
{
    if ($value === null) {
        return null;
    }

    $normalized = preg_replace('/[^0-9.\-]/', '', $value);
    if ($normalized === null || $normalized === '' || !is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function runCommand(string $command, ?int &$exitCode = null): string
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    $exitCode = $code;
    return implode(PHP_EOL, $output);
}

function runCommandDetailed(string $command): array
{
    $output = [];
    $code = 0;
    exec($command . ' 2>&1', $output, $code);
    return [
        'command' => $command,
        'exit_code' => $code,
        'output' => implode(PHP_EOL, $output),
    ];
}

function extractTextFromPdf(string $pdfPath): string
{
    $escaped = escapeshellarg($pdfPath);

    $text = extractViaPdfToText($escaped);
    if (strlen(trim($text)) > 80) {
        return $text;
    }

    $text = extractViaPython($pdfPath);
    if (strlen(trim($text)) > 80) {
        return $text;
    }

    return $text;
}

function extractViaPdfToText(string $escapedPdfPath): string
{
    $result = runCommand('pdftotext -layout ' . $escapedPdfPath . ' -', $extractCode);
    if ($extractCode !== 0) {
        return '';
    }

    return $result;
}

function extractViaPython(string $pdfPath): string
{
    $script = <<<'PY'
import subprocess
import sys
import tempfile
from pathlib import Path

pdf_path = Path(sys.argv[1])


def read_with_pypdf(path: Path) -> str:
    try:
        from pypdf import PdfReader
    except Exception:
        return ""

    try:
        reader = PdfReader(str(path))
        chunks = []
        for p in reader.pages:
            chunks.append((p.extract_text() or "").strip())
        return "\n\n".join([c for c in chunks if c])
    except Exception:
        return ""


def read_with_ocr(path: Path) -> str:
    try:
        import fitz
    except Exception:
        return ""

    try:
        subprocess.run(["tesseract", "--version"], capture_output=True, text=True, check=False)
    except Exception:
        return ""

    use_eng = False
    try:
        langs = subprocess.run(
            ["tesseract", "--list-langs"],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="ignore",
            check=False,
        )
        combined = (langs.stdout or "") + "\n" + (langs.stderr or "")
        use_eng = "eng" in combined.lower()
    except Exception:
        use_eng = False

    out = []
    try:
        doc = fitz.open(str(path))
        with tempfile.TemporaryDirectory() as tmpdir:
            tmp = Path(tmpdir)
            for idx, page in enumerate(doc, start=1):
                pix = page.get_pixmap(matrix=fitz.Matrix(2, 2), alpha=False)
                image_path = tmp / f"page_{idx}.png"
                pix.save(str(image_path))
                cmd = ["tesseract", str(image_path), "stdout"]
                if use_eng:
                    cmd.extend(["-l", "eng"])

                proc = subprocess.run(
                    cmd,
                    capture_output=True,
                    text=True,
                    encoding="utf-8",
                    errors="ignore",
                    check=False,
                )
                text = (proc.stdout or "").strip()
                if not text and use_eng and proc.returncode != 0:
                    # Retry without explicit language in case eng data isn't available.
                    proc2 = subprocess.run(
                        ["tesseract", str(image_path), "stdout"],
                        capture_output=True,
                        text=True,
                        encoding="utf-8",
                        errors="ignore",
                        check=False,
                    )
                    text = (proc2.stdout or "").strip()

                if text:
                    out.append(text)
        return "\n\n".join([x for x in out if x])
    except Exception:
        return ""


text = read_with_pypdf(pdf_path)
if len(text.strip()) < 80:
    text = read_with_ocr(pdf_path)

try:
    sys.stdout.reconfigure(encoding="utf-8", errors="ignore")
except Exception:
    pass

sys.stdout.buffer.write((text or "").encode("utf-8", errors="ignore"))
PY;

    $fallbackDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($fallbackDir)) {
        mkdir($fallbackDir, 0777, true);
    }
    $tmpPath = $fallbackDir . DIRECTORY_SEPARATOR . 'pdfparse_' . bin2hex(random_bytes(6));

    $pyPath = $tmpPath . '.py';
    if (file_put_contents($pyPath, $script) === false) {
        return '';
    }

    try {
        $commands = [
            'python ' . escapeshellarg($pyPath) . ' ' . escapeshellarg($pdfPath),
            'py -3 ' . escapeshellarg($pyPath) . ' ' . escapeshellarg($pdfPath),
            'python3 ' . escapeshellarg($pyPath) . ' ' . escapeshellarg($pdfPath),
        ];

        foreach ($commands as $command) {
            $text = runCommand($command, $exitCode);
            if ($exitCode === 0 && trim($text) !== '') {
                return $text;
            }
        }

        return '';
    } finally {
        if (is_file($pyPath)) {
            unlink($pyPath);
        }
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }
    }
}

function getExtractorDiagnostics(): array
{
    $checks = [];

    $checks[] = runCommandDetailed('pdftotext -v');
    $checks[] = runCommandDetailed('python --version');
    $checks[] = runCommandDetailed('py -3 --version');
    $checks[] = runCommandDetailed('python3 --version');
    $checks[] = runCommandDetailed('tesseract --version');
    $checks[] = runCommandDetailed(
        'python -c "import sys,importlib.util as u; print(sys.executable); print(\'pypdf=\'+str(bool(u.find_spec(\'pypdf\')))); print(\'fitz=\'+str(bool(u.find_spec(\'fitz\'))))"'
    );

    return $checks;
}

function isTableStopLine(string $line): bool
{
    $line = trim($line);
    if ($line === '') {
        return false;
    }

    // Stop only on true footer labels, not description fragments like
    // "PURPOSE, 200 GRAMS" that can appear inside item rows.
    return (bool) preg_match(
        '/^(?:total(?:\s+cost)?|purpose\s*:|requested\s+by\s*:|signature\s*:|printed\s*name\s*:|designation\s*:|see\s+back\b|effectivity\b)/i',
        $line
    );
}

function isLikelyUnitToken(string $token): bool
{
    $token = strtolower(trim($token));
    if ($token === '') {
        return false;
    }

    $common = [
        'pc', 'pcs', 'piece', 'pieces', 'set', 'lot', 'box', 'roll', 'ream',
        'bottle', 'btl', 'pack', 'pkg', 'unit', 'kg', 'g', 'l', 'ltr', 'meter',
        'm', 'cm', 'dozen',
    ];

    if (in_array($token, $common, true)) {
        return true;
    }

    if (preg_match('/^\d{1,10}$/', $token)) {
        return true;
    }

    if (preg_match('/^[a-z]{1,4}$/', $token)) {
        return true;
    }

    // Alphanumeric short code with at least one digit.
    return (bool) (preg_match('/^[a-z0-9\-\/]{1,8}$/i', $token) && preg_match('/\d/', $token));
}

function isHeaderLikeTableLine(string $line): bool
{
    return (bool) preg_match('/(stock\/|property|item\s*description|quantity|unit\s*cost|total\s*cost|\bno\.)/i', $line);
}

function isItemRowStartLine(string $line): bool
{
    $line = trim($line);
    if (!preg_match('/^(?<code>\d{7,10})(?:\s+(?<rest>.*))?$/', $line, $m)) {
        return false;
    }

    $rest = trim((string) ($m['rest'] ?? ''));
    if ($rest === '') {
        return true;
    }

    // Typical item-row starts contain words, not just numeric triplets.
    if (preg_match('/[A-Za-z]/', $rest)) {
        return true;
    }

    return false;
}

function isEmptyItemRow(array $item): bool
{
    $vals = [
        $item['stock_property_no'] ?? null,
        $item['unit'] ?? null,
        $item['item_description'] ?? null,
        $item['quantity'] ?? null,
        $item['unit_cost'] ?? null,
        $item['total_cost'] ?? null,
    ];

    foreach ($vals as $v) {
        if ($v !== null && $v !== '') {
            return false;
        }
    }

    return true;
}

function parseItemBaseLine(string $line): ?array
{
    $line = cleanValue($line);
    if ($line === null) {
        return null;
    }

    if (isTableStopLine($line) || isHeaderLikeTableLine($line)) {
        return null;
    }

    $tokens = preg_split('/\s+/', $line) ?: [];
    if (count($tokens) < 2) {
        return null;
    }

    // Pull numeric columns from right side: Quantity, Unit Cost, Total Cost.
    $tailNumeric = [];
    $idx = count($tokens) - 1;
    while ($idx >= 0 && preg_match('/^\d[\d,]*(?:\.\d+)?$/', $tokens[$idx])) {
        array_unshift($tailNumeric, $tokens[$idx]);
        $idx--;
    }

    $left = array_slice($tokens, 0, $idx + 1);
    if (count($left) < 2) {
        return null;
    }

    $stockPropertyNo = null;
    $unit = null;
    $descriptionTokens = [];

    // Left-side columns: Stock/Property No., Unit, Item Description.
    if (preg_match('/^\d{4,}$/', $left[0])) {
        if (isset($left[1]) && preg_match('/^\d{2,}$/', $left[1])) {
            // Two leading numeric codes: stock + unit.
            $stockPropertyNo = cleanValue($left[0]);
            $unit = cleanValue($left[1]);
            $descriptionTokens = array_slice($left, 2);
        } elseif (isset($left[1]) && isLikelyUnitToken($left[1]) && count($left) >= 3) {
            // Leading stock code + textual/alnum unit.
            $stockPropertyNo = cleanValue($left[0]);
            $unit = cleanValue($left[1]);
            $descriptionTokens = array_slice($left, 2);
        } else {
            // Common OCR case: only Unit is clearly visible, stock/property is blank.
            $unit = cleanValue($left[0]);
            $descriptionTokens = array_slice($left, 1);
        }
    } else {
        $unit = cleanValue($left[0]);
        $descriptionTokens = array_slice($left, 1);
    }

    $description = cleanValue(implode(' ', $descriptionTokens));
    if ($description === null && $tailNumeric === []) {
        return null;
    }

    $numbers = array_values(array_filter(
        array_map(static fn($n) => parseMoney($n), $tailNumeric),
        static fn($n) => $n !== null
    ));

    $quantity = null;
    $unitCost = null;
    $totalCost = null;

    if (count($numbers) === 1) {
        $quantity = $numbers[0];
    } elseif (count($numbers) === 2) {
        $quantity = $numbers[0];
        $totalCost = $numbers[1];
        if ($quantity > 0) {
            $unitCost = round($totalCost / $quantity, 2);
        }
    } elseif (count($numbers) >= 3) {
        $n = count($numbers);
        $quantity = $numbers[$n - 3];
        $unitCost = $numbers[$n - 2];
        $totalCost = $numbers[$n - 1];
    }

    if ($totalCost === null && $quantity !== null && $unitCost !== null) {
        $totalCost = round($quantity * $unitCost, 2);
    }

    $item = [
        'stock_property_no' => $stockPropertyNo,
        'unit' => $unit,
        'item_description' => $description,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost,
    ];

    if (isEmptyItemRow($item)) {
        return null;
    }

    return $item;
}

function parseItemsFromTableSlice(string $tableSlice): array
{
    if ($tableSlice === '') {
        return [];
    }

    $lines = array_values(array_filter(
        array_map(static fn($line) => trim($line), explode("\n", $tableSlice)),
        static fn($line) => $line !== ''
    ));

    $rowBlocks = [];
    $currentBlock = [];
    $seenTableHeader = false;
    $hasRowStart = false;

    foreach ($lines as $line) {
        if (isTableStopLine($line)) {
            // Some files have "Total" / "Cost" in the table header. Only stop once
            // at least one item row has started.
            if ($hasRowStart) {
                break;
            }
            continue;
        }

        if (isHeaderLikeTableLine($line)) {
            $seenTableHeader = true;
            continue;
        }

        if (!$seenTableHeader && !isItemRowStartLine($line)) {
            continue;
        }

        if (isItemRowStartLine($line)) {
            $hasRowStart = true;
            if ($currentBlock !== []) {
                $rowBlocks[] = $currentBlock;
            }
            $currentBlock = [$line];
            continue;
        }

        if ($currentBlock !== []) {
            $currentBlock[] = $line;
        }
    }

    if ($currentBlock !== []) {
        $rowBlocks[] = $currentBlock;
    }

    $items = [];
    foreach ($rowBlocks as $block) {
        $item = parseItemFromRowBlock($block);
        if ($item !== null && !isEmptyItemRow($item)) {
            $items[] = $item;
        }
    }

    // Safety rule: if last parsed row is empty, do not include it.
    if ($items !== [] && isEmptyItemRow(end($items))) {
        array_pop($items);
    }

    return $items;
}

function parseItemFromRowBlock(array $block): ?array
{
    $block = array_values(array_filter(array_map(static fn($line) => cleanValue($line), $block)));
    if ($block === []) {
        return null;
    }

    $first = $block[0];
    if ($first === null) {
        return null;
    }

    $stockPropertyNo = null;
    $unit = null;
    $descParts = [];
    $numericOnly = [];
    $seedQuantity = null;
    $seedUnitCost = null;
    $seedTotalCost = null;

    // If the row is a single compact line, parse directly.
    $direct = parseItemBaseLine($first);
    if (count($block) === 1 && $direct !== null && ($direct['item_description'] ?? null) !== null) {
        return $direct;
    }

    if (preg_match('/^(?<code>\d{7,10})(?:\s+(?<rest>.*))?$/', $first, $m)) {
        $stockPropertyNo = cleanValue($m['code'] ?? null);
        $rest = cleanValue($m['rest'] ?? null);
        if ($direct !== null) {
            $directUnit = cleanValue($direct['unit'] ?? null);
            if ($directUnit !== null && $directUnit !== $stockPropertyNo) {
                $unit = $directUnit;
            }

            $directDesc = cleanValue($direct['item_description'] ?? null);
            if ($directDesc !== null) {
                $descParts[] = $directDesc;
            } elseif ($rest !== null) {
                $descParts[] = $rest;
            }

            $seedQuantity = $direct['quantity'] ?? null;
            $seedUnitCost = $direct['unit_cost'] ?? null;
            $seedTotalCost = $direct['total_cost'] ?? null;
        } elseif ($rest !== null) {
            $descParts[] = $rest;
        }
    } else {
        return parseItemBaseLine(implode(' ', $block));
    }

    for ($i = 1; $i < count($block); $i++) {
        $line = $block[$i];
        if ($line === null || isTableStopLine($line) || isHeaderLikeTableLine($line)) {
            continue;
        }

        if (preg_match('/^\d[\d,]*(?:\.\d+)?$/', $line)) {
            $n = parseMoney($line);
            if ($n !== null) {
                $numericOnly[] = $n;
            }
            continue;
        }

        // Numeric triplet lines: "qty unit_cost total_cost"
        if (preg_match('/^\d[\d,]*(?:\.\d+)?(?:\s+\d[\d,]*(?:\.\d+)?){1,2}$/', $line)) {
            preg_match_all('/\d[\d,]*(?:\.\d+)?/', $line, $matches);
            foreach (($matches[0] ?? []) as $token) {
                $n = parseMoney($token);
                if ($n !== null) {
                    $numericOnly[] = $n;
                }
            }
            continue;
        }

        $descParts[] = $line;
    }

    // Optional Unit token at the start of description block.
    if ($descParts !== []) {
        $firstDesc = $descParts[0];
        $tokens = preg_split('/\s+/', (string) $firstDesc) ?: [];
        if (count($tokens) >= 2 && isLikelyUnitToken($tokens[0])) {
            $unit = cleanValue($tokens[0]);
            $descParts[0] = cleanValue(implode(' ', array_slice($tokens, 1))) ?? '';
        }
    }

    $description = cleanValue(implode(' ', array_filter($descParts, static fn($v) => $v !== null && $v !== '')));

    $quantity = $seedQuantity;
    $unitCost = $seedUnitCost;
    $totalCost = $seedTotalCost;
    if (count($numericOnly) === 1) {
        $quantity = $numericOnly[0];
    } elseif (count($numericOnly) === 2) {
        $quantity = $numericOnly[0];
        $totalCost = $numericOnly[1];
        if ($quantity > 0) {
            $unitCost = round($totalCost / $quantity, 2);
        }
    } elseif (count($numericOnly) >= 3) {
        $n = count($numericOnly);
        $quantity = $numericOnly[$n - 3];
        $unitCost = $numericOnly[$n - 2];
        $totalCost = $numericOnly[$n - 1];
    }

    if ($totalCost === null && $quantity !== null && $unitCost !== null) {
        $totalCost = round($quantity * $unitCost, 2);
    }

    $item = [
        'stock_property_no' => $stockPropertyNo,
        'unit' => $unit,
        'item_description' => $description,
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost,
    ];

    if (isEmptyItemRow($item)) {
        return null;
    }

    return $item;
}

function normalizeItemsColumnAlignment(array $items): array
{
    if ($items === []) {
        return $items;
    }

    $hasNumericUnitWithoutStock = false;
    foreach ($items as $item) {
        $stock = cleanValue($item['stock_property_no'] ?? null);
        $unit = cleanValue($item['unit'] ?? null);
        if ($stock === null && $unit !== null && preg_match('/^\d{7,10}$/', $unit)) {
            $hasNumericUnitWithoutStock = true;
            break;
        }
    }

    if (!$hasNumericUnitWithoutStock) {
        return $items;
    }

    $strictUnits = [
        'pc', 'pcs', 'piece', 'pieces', 'set', 'lot', 'box', 'roll', 'ream',
        'bottle', 'btl', 'pack', 'pkg', 'unit', 'kg', 'g', 'l', 'ltr', 'meter',
        'm', 'cm', 'dozen',
    ];

    foreach ($items as &$item) {
        $stock = cleanValue($item['stock_property_no'] ?? null);
        $unit = cleanValue($item['unit'] ?? null);
        if ($stock !== null && preg_match('/^\d{7,10}$/', $stock)) {
            if ($unit === null) {
                $item['unit'] = $stock;
                $item['stock_property_no'] = null;
                continue;
            }

            $isStrictUnit = in_array(strtolower($unit), $strictUnits, true) || (bool) preg_match('/^\d{1,10}$/', $unit);
            if (!$isStrictUnit) {
                $item['item_description'] = cleanValue($unit . ' ' . ($item['item_description'] ?? ''));
                $item['unit'] = $stock;
                $item['stock_property_no'] = null;
            }
        }
    }
    unset($item);

    return $items;
}

function normalizeTrailingDescriptionQuantity(array $items): array
{
    foreach ($items as &$item) {
        $quantity = $item['quantity'] ?? null;
        $unitCost = $item['unit_cost'] ?? null;
        $totalCost = $item['total_cost'] ?? null;
        $description = cleanValue($item['item_description'] ?? null);

        if ($description === null || $quantity !== null || $unitCost !== null || $totalCost !== null) {
            continue;
        }

        // Common OCR/layout case: quantity is appended as the last token in description.
        // Example: "STAPLE REMOVER, PLIER TYPE 100" -> qty=100.
        if (!preg_match('/^(.*\D)\s+(\d[\d,]*(?:\.\d+)?)$/', $description, $m)) {
            continue;
        }

        $qty = parseFloat($m[2] ?? null);
        $desc = cleanValue($m[1] ?? null);
        if ($qty === null || $qty <= 0 || $desc === null) {
            continue;
        }

        $item['item_description'] = $desc;
        $item['quantity'] = $qty;
    }
    unset($item);

    return $items;
}

function parsePurchaseRequestFields(string $text): array
{
    $normalized = preg_replace('/\r\n?|\n/u', "\n", $text) ?? $text;

    $fundCluster = null;
    if (preg_match('/Fund\s*Cluster\s*:?\s*\n?(.+?)(?:\n\s*(?:Office\/Section|PR\s*No\.?|Responsibility\s*Center\s*Code))/is', $normalized, $m)) {
        $fundCluster = cleanValue($m[1]);
    } elseif (preg_match('/Fund\s*Cluster\s*:?\s*(.+)/i', $normalized, $m)) {
        $fundCluster = cleanValue($m[1]);
    }

    preg_match('/PR\s*No\.?\s*:?\s*([A-Za-z0-9\-\/]+)/i', $normalized, $prNoMatch);
    $prNo = cleanValue($prNoMatch[1] ?? null);

    preg_match('/Responsibility\s*Center\s*Code\s*:?\s*([A-Za-z0-9\-\/_ ]*)/i', $normalized, $rccMatch);
    $responsibilityCenterCode = cleanValue($rccMatch[1] ?? null);
    if ($responsibilityCenterCode !== null && preg_match('/\b(stock|property|unit|item)\b/i', $responsibilityCenterCode)) {
        $responsibilityCenterCode = null;
    }

    preg_match('/Date\s*:?\s*([0-9]{1,2}[-\/][A-Za-z]{3}[-\/][0-9]{2,4}|[0-9]{1,2}[-\/][0-9]{1,2}[-\/][0-9]{2,4})/i', $normalized, $dateMatch);
    $requestDate = cleanValue($dateMatch[1] ?? null);

    $requestedBy = null;
    $approvedBy = null;
    if (preg_match('/Printed\s*Name\s*:\s*(.*?)(?:\n\s*Designation\s*:|$)/is', $normalized, $nameBlockMatch)) {
        $nameBlock = trim((string) ($nameBlockMatch[1] ?? ''));
        $nameLines = array_values(array_filter(
            array_map(static fn($line) => cleanValue($line), preg_split('/\n+/', $nameBlock) ?: []),
            static fn($line) => $line !== null && $line !== ''
        ));

        $leftParts = [];
        $rightParts = [];
        foreach ($nameLines as $line) {
            if (preg_match('/^(.+?)\s{2,}(.+)$/', (string) $line, $splitBySpaces)) {
                $leftParts[] = cleanValue($splitBySpaces[1] ?? null);
                $rightParts[] = cleanValue($splitBySpaces[2] ?? null);
            }
        }

        if ($leftParts !== [] && $rightParts !== []) {
            $requestedBy = cleanValue(implode(' ', array_filter($leftParts, static fn($v) => $v !== null && $v !== '')));
            $approvedBy = cleanValue(implode(' ', array_filter($rightParts, static fn($v) => $v !== null && $v !== '')));
        } elseif (count($nameLines) >= 2) {
            // Fallback for wrapped two-column names where extraction flattened spacing.
            $requestedBy = cleanValue((string) $nameLines[0]);
            $approvedBy = cleanValue(implode(' ', array_map(static fn($v) => (string) $v, array_slice($nameLines, 1))));
        } elseif (count($nameLines) === 1) {
            $nameLine = cleanValue((string) $nameLines[0]);
            if ($nameLine !== null) {
                if (preg_match('/^(.+?)\s{2,}(.+)$/', $nameLine, $splitBySpaces)) {
                    $requestedBy = cleanValue($splitBySpaces[1]);
                    $approvedBy = cleanValue($splitBySpaces[2]);
                } elseif (preg_match('/^([A-Z\s\.]+)\s+([A-Z][a-z].+)$/', $nameLine, $splitByCase)) {
                    $requestedBy = cleanValue($splitByCase[1]);
                    $approvedBy = cleanValue($splitByCase[2]);
                } else {
                    $parts = preg_split('/\s+/', $nameLine) ?: [];
                    $mid = intdiv(count($parts), 2);
                    $requestedBy = cleanValue(implode(' ', array_slice($parts, 0, $mid)));
                    $approvedBy = cleanValue(implode(' ', array_slice($parts, $mid)));
                }
            }
        }
    }

    $designation1 = null;
    $designation2 = null;
    if (preg_match('/Designation\s*:\s*(.+?)(?:\n|$)/i', $normalized, $designationMatch)) {
        $designationLine = cleanValue($designationMatch[1] ?? null);
        if ($designationLine !== null) {
            if (preg_match('/^(.+?)\s{2,}(.+)$/', $designationLine, $splitBySpaces)) {
                $designation1 = cleanValue($splitBySpaces[1]);
                $designation2 = cleanValue($splitBySpaces[2]);
            } else {
                $parts = preg_split('/\s+/', $designationLine) ?: [];
                $mid = intdiv(count($parts), 2);
                $designation1 = cleanValue(implode(' ', array_slice($parts, 0, $mid)));
                $designation2 = cleanValue(implode(' ', array_slice($parts, $mid)));
            }
        }
    }

    $tableSlice = '';
    if (preg_match('/Responsibility\s*Center\s*Code\s*:?(.*?)(?:Purpose\s*:|Requested\s*by\s*:)/is', $normalized, $sliceMatch)) {
        $tableSlice = trim($sliceMatch[1]);
    }

    $unit = null;
    $itemDescription = null;
    $quantity = null;
    $unitCost = null;
    $totalCost = null;

    // Take the LAST "Total Cost" occurrence to avoid header false positives.
    preg_match_all('/(?:^|\n)\s*Total\s*\n\s*Cost\s*:?\s*([0-9,]+(?:\.\d{2})?)/i', $normalized, $totalMatchesA);
    preg_match_all('/(?:^|\n)\s*Total\s*Cost\s*:?\s*([0-9,]+(?:\.\d{2})?)/i', $normalized, $totalMatchesB);

    $totalCandidates = [];
    if (!empty($totalMatchesA[1])) {
        $totalCandidates = array_merge($totalCandidates, $totalMatchesA[1]);
    }
    if (!empty($totalMatchesB[1])) {
        $totalCandidates = array_merge($totalCandidates, $totalMatchesB[1]);
    }

    if ($totalCandidates !== []) {
        $last = end($totalCandidates);
        $totalCost = parseMoney(is_string($last) ? $last : null);
    }

    $items = normalizeItemsColumnAlignment(parseItemsFromTableSlice($tableSlice));
    $items = normalizeTrailingDescriptionQuantity($items);

    if ($items !== []) {
        $first = $items[0];
        $unit = $first['unit'] ?? null;
        $itemDescription = $first['item_description'] ?? null;
        $quantity = $first['quantity'] ?? null;
        $unitCost = $first['unit_cost'] ?? null;
    }

    if ($totalCost === null && $items !== []) {
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

    if ($items !== []) {
        if (count($items) === 1 && $items[0]['total_cost'] === null && $totalCost !== null) {
            $items[0]['total_cost'] = $totalCost;
        }
        if (count($items) === 1 && $items[0]['unit_cost'] === null && $items[0]['quantity'] !== null && $totalCost !== null && $items[0]['quantity'] > 0) {
            $items[0]['unit_cost'] = round($totalCost / $items[0]['quantity'], 2);
            $unitCost = $items[0]['unit_cost'];
        }
    }

    return [
        'fund_cluster' => $fundCluster,
        'pr_no' => $prNo,
        'responsibility_center_code' => $responsibilityCenterCode,
        'request_date' => $requestDate,
        'unit' => $unit,
        'item_description' => cleanValue($itemDescription),
        'quantity' => $quantity,
        'unit_cost' => $unitCost,
        'total_cost' => $totalCost,
        'requested_by' => $requestedBy,
        'designation1' => $designation1,
        'approved_by' => $approvedBy,
        'designation2' => $designation2,
        'items' => $items,
    ];
}
