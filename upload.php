<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/parser.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function uploadDirPath(): string
{
    $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    return $uploadDir;
}

function validateUploadedPdf(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed';
    }

    $originalName = (string) ($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return 'Only PDF files are allowed';
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? finfo_file($finfo, (string) $file['tmp_name']) : null;
    if ($finfo) {
        finfo_close($finfo);
    }

    if ($mime !== 'application/pdf') {
        return 'Uploaded file is not a valid PDF';
    }

    return null;
}

function safeTempFileName(string $input): ?string
{
    $name = basename($input);
    if (!preg_match('/^[A-Za-z0-9_\-.]+\.pdf$/', $name)) {
        return null;
    }

    return $name;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Method not allowed']);
}

$action = (string) ($_POST['action'] ?? 'process');

if ($action === 'process') {
    if (!isset($_FILES['pdf'])) {
        respond(400, ['ok' => false, 'message' => 'No file uploaded']);
    }

    $file = $_FILES['pdf'];
    $validationError = validateUploadedPdf($file);
    if ($validationError !== null) {
        respond(400, ['ok' => false, 'message' => $validationError]);
    }

    $originalName = (string) ($file['name'] ?? 'document.pdf');
    $safeBase = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', pathinfo($originalName, PATHINFO_FILENAME)) ?: 'document';
    $storedName = 'pending_' . $safeBase . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
    $targetPath = uploadDirPath() . DIRECTORY_SEPARATOR . $storedName;

    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        respond(500, ['ok' => false, 'message' => 'Could not store uploaded file']);
    }

    $text = extractTextFromPdf($targetPath);
    if (trim($text) === '') {
        @unlink($targetPath);
        respond(500, [
            'ok' => false,
            'message' => 'Could not extract text. Install one of: pdftotext, or Python with pypdf/pymupdf and tesseract.',
            'diagnostics' => getExtractorDiagnostics(),
        ]);
    }

    $parsed = parsePurchaseRequestFields($text);
    $itemsCount = is_array($parsed['items'] ?? null) ? count($parsed['items']) : 0;

    respond(200, [
        'ok' => true,
        'stage' => 'processed',
        'message' => 'Processing complete.',
        'items_count' => $itemsCount,
        'temp_file' => $storedName,
        'data' => $parsed,
    ]);
}

if ($action === 'save') {
    $tempFile = safeTempFileName((string) ($_POST['temp_file'] ?? ''));
    if ($tempFile === null) {
        respond(400, ['ok' => false, 'message' => 'Invalid temporary file']);
    }

    $targetPath = uploadDirPath() . DIRECTORY_SEPARATOR . $tempFile;
    if (!is_file($targetPath)) {
        respond(404, ['ok' => false, 'message' => 'Processed file not found. Please upload again.']);
    }

    $text = extractTextFromPdf($targetPath);
    if (trim($text) === '') {
        respond(500, [
            'ok' => false,
            'message' => 'Could not extract text. Install one of: pdftotext, or Python with pypdf/pymupdf and tesseract.',
            'diagnostics' => getExtractorDiagnostics(),
        ]);
    }

    $parsed = parsePurchaseRequestFields($text);

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_requests (
                file_name, fund_cluster, pr_no, responsibility_center_code, request_date,
                unit, item_description, quantity, unit_cost, total_cost,
                requested_by, designation1, approved_by, designation2, raw_text
            ) VALUES (
                :file_name, :fund_cluster, :pr_no, :responsibility_center_code, :request_date,
                :unit, :item_description, :quantity, :unit_cost, :total_cost,
                :requested_by, :designation1, :approved_by, :designation2, :raw_text
            )'
        );

        $stmt->execute([
            ':file_name' => $tempFile,
            ':fund_cluster' => $parsed['fund_cluster'],
            ':pr_no' => $parsed['pr_no'],
            ':responsibility_center_code' => $parsed['responsibility_center_code'],
            ':request_date' => $parsed['request_date'],
            ':unit' => $parsed['unit'],
            ':item_description' => $parsed['item_description'],
            ':quantity' => $parsed['quantity'],
            ':unit_cost' => $parsed['unit_cost'],
            ':total_cost' => $parsed['total_cost'],
            ':requested_by' => $parsed['requested_by'],
            ':designation1' => $parsed['designation1'],
            ':approved_by' => $parsed['approved_by'],
            ':designation2' => $parsed['designation2'],
            ':raw_text' => $text,
        ]);

        $id = (int) $pdo->lastInsertId();

        $items = $parsed['items'] ?? [];
        if (is_array($items) && $items !== []) {
            $itemStmt = $pdo->prepare(
                'INSERT INTO purchase_request_items (
                    purchase_request_id, stock_property_no, unit, item_description, quantity, unit_cost, total_cost
                ) VALUES (
                    :purchase_request_id, :stock_property_no, :unit, :item_description, :quantity, :unit_cost, :total_cost
                )'
            );

            foreach ($items as $item) {
                $itemStmt->execute([
                    ':purchase_request_id' => $id,
                    ':stock_property_no' => $item['stock_property_no'] ?? null,
                    ':unit' => $item['unit'] ?? null,
                    ':item_description' => $item['item_description'] ?? null,
                    ':quantity' => $item['quantity'] ?? null,
                    ':unit_cost' => $item['unit_cost'] ?? null,
                    ':total_cost' => $item['total_cost'] ?? null,
                ]);
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        respond(500, ['ok' => false, 'message' => 'Failed to save entry.']);
    }

    respond(200, [
        'ok' => true,
        'stage' => 'saved',
        'message' => 'PDF processed and saved.',
        'record_id' => $id,
        'items_count' => is_array($parsed['items'] ?? null) ? count($parsed['items']) : 0,
        'data' => $parsed,
    ]);
}

if ($action === 'cancel') {
    $tempFile = safeTempFileName((string) ($_POST['temp_file'] ?? ''));
    if ($tempFile === null) {
        respond(400, ['ok' => false, 'message' => 'Invalid temporary file']);
    }

    $targetPath = uploadDirPath() . DIRECTORY_SEPARATOR . $tempFile;
    if (is_file($targetPath)) {
        @unlink($targetPath);
    }

    respond(200, ['ok' => true, 'stage' => 'canceled', 'message' => 'Processing canceled.']);
}

respond(400, ['ok' => false, 'message' => 'Invalid action']);
