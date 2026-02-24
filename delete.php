<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid record id']);
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('SELECT id, file_name, pr_no FROM purchase_requests WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Record not found']);
        exit;
    }

    $del = $pdo->prepare('DELETE FROM purchase_requests WHERE id = :id');
    $del->execute([':id' => $id]);

    $pdo->commit();

    $uploadPath = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . (string) $row['file_name'];
    if (is_file($uploadPath)) {
        @unlink($uploadPath);
    }

    echo json_encode([
        'ok' => true,
        'message' => 'Record deleted successfully.',
        'id' => (int) $id,
        'pr_no' => (string) ($row['pr_no'] ?? ''),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Delete failed.']);
}
