<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/xlsx_export.php';

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'PHP Zip extension is required to export XLSX.';
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Invalid record id.';
    exit;
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM purchase_requests WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Record not found.';
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT * FROM purchase_request_items WHERE purchase_request_id = :id ORDER BY id ASC'
);
$itemStmt->execute([':id' => $id]);
$items = $itemStmt->fetchAll();

if ($items) {
    // Keep backward compatibility for template fields while also passing all rows.
    $firstItem = $items[0];
    $row['unit'] = $firstItem['unit'] ?? $row['unit'];
    $row['item_description'] = $firstItem['item_description'] ?? $row['item_description'];
    $row['quantity'] = $firstItem['quantity'] ?? $row['quantity'];
    $row['unit_cost'] = $firstItem['unit_cost'] ?? $row['unit_cost'];
    $row['item_total_cost'] = $firstItem['total_cost'] ?? null;
    $row['items'] = $items;
}

$tmpFile = tempnam(sys_get_temp_dir(), 'pr_xlsx_');
if ($tmpFile === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Failed to allocate temporary file.';
    exit;
}

$xlsxPath = $tmpFile . '.xlsx';
rename($tmpFile, $xlsxPath);

try {
    createPrTemplateXlsx($row, $xlsxPath);

    $prNo = trim((string) ($row['pr_no'] ?? ''));
    $safePrNo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $prNo ?: 'record_' . $id);
    $fileName = 'PR_' . $safePrNo . '.xlsx';

    header('Content-Description: File Transfer');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize($xlsxPath));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: public');

    readfile($xlsxPath);
} finally {
    if (is_file($xlsxPath)) {
        unlink($xlsxPath);
    }
}
