<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid record id']);
    exit;
}

$pdo = db();
$prStmt = $pdo->prepare('SELECT id, pr_no FROM purchase_requests WHERE id = :id LIMIT 1');
$prStmt->execute([':id' => $id]);
$pr = $prStmt->fetch();

if (!$pr) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Record not found']);
    exit;
}

$itemStmt = $pdo->prepare(
    'SELECT id, stock_property_no, unit, item_description, quantity, unit_cost, total_cost
     FROM purchase_request_items
     WHERE purchase_request_id = :id
     ORDER BY id ASC'
);
$itemStmt->execute([':id' => $id]);
$items = $itemStmt->fetchAll();

echo json_encode([
    'ok' => true,
    'record_id' => (int) $pr['id'],
    'pr_no' => (string) ($pr['pr_no'] ?? ''),
    'items' => $items,
]);
