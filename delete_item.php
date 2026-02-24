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

$itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;
$prId = isset($_POST['pr_id']) ? (int) $_POST['pr_id'] : 0;

if ($itemId <= 0 || $prId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid item id or record id']);
    exit;
}

$pdo = db();

function recalculatePrTotal(PDO $pdo, int $prId): ?float
{
    $sumStmt = $pdo->prepare(
        'SELECT SUM(total_cost) FROM purchase_request_items WHERE purchase_request_id = :pr_id AND total_cost IS NOT NULL'
    );
    $sumStmt->execute([':pr_id' => $prId]);
    $sum = $sumStmt->fetchColumn();
    $total = $sum === null ? null : (float) $sum;

    $updStmt = $pdo->prepare('UPDATE purchase_requests SET total_cost = :total WHERE id = :pr_id');
    $updStmt->bindValue(':total', $total, $total === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $updStmt->bindValue(':pr_id', $prId, PDO::PARAM_INT);
    $updStmt->execute();

    return $total;
}

try {
    $pdo->beginTransaction();

    $findStmt = $pdo->prepare(
        'SELECT id, purchase_request_id
         FROM purchase_request_items
         WHERE id = :item_id
         LIMIT 1'
    );
    $findStmt->execute([':item_id' => $itemId]);
    $item = $findStmt->fetch();

    if (!$item || (int) $item['purchase_request_id'] !== $prId) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Item not found']);
        exit;
    }

    $delStmt = $pdo->prepare('DELETE FROM purchase_request_items WHERE id = :item_id');
    $delStmt->execute([':item_id' => $itemId]);

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_request_items WHERE purchase_request_id = :pr_id');
    $countStmt->execute([':pr_id' => $prId]);
    $remainingCount = (int) $countStmt->fetchColumn();
    $prTotalCost = recalculatePrTotal($pdo, $prId);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => 'Item deleted successfully.',
        'item_id' => $itemId,
        'pr_id' => $prId,
        'remaining_count' => $remainingCount,
        'pr_total_cost' => $prTotalCost,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to delete item']);
}
