<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$prId = isset($_POST['pr_id']) ? (int) $_POST['pr_id'] : 0;
$itemId = isset($_POST['item_id']) ? (int) $_POST['item_id'] : 0;

if ($prId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid PR id']);
    exit;
}

$stockPropertyNo = trim((string) ($_POST['stock_property_no'] ?? ''));
$unit = trim((string) ($_POST['unit'] ?? ''));
$itemDescription = trim((string) ($_POST['item_description'] ?? ''));

$quantity = isset($_POST['quantity']) && $_POST['quantity'] !== '' ? (float) $_POST['quantity'] : null;
$unitCost = isset($_POST['unit_cost']) && $_POST['unit_cost'] !== '' ? (float) $_POST['unit_cost'] : null;
$totalCost = isset($_POST['total_cost']) && $_POST['total_cost'] !== '' ? (float) $_POST['total_cost'] : null;

if ($itemDescription === '' && $stockPropertyNo === '' && $unit === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Please provide at least stock/property no., unit, or item description.']);
    exit;
}

if ($totalCost === null && $quantity !== null && $unitCost !== null) {
    $totalCost = round($quantity * $unitCost, 2);
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

    $prStmt = $pdo->prepare('SELECT id FROM purchase_requests WHERE id = :pr_id LIMIT 1');
    $prStmt->execute([':pr_id' => $prId]);
    $pr = $prStmt->fetch();
    if (!$pr) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'PR record not found']);
        exit;
    }

    $mode = 'create';

    if ($itemId > 0) {
        $findStmt = $pdo->prepare('SELECT id, purchase_request_id FROM purchase_request_items WHERE id = :item_id LIMIT 1');
        $findStmt->execute([':item_id' => $itemId]);
        $existing = $findStmt->fetch();

        if (!$existing || (int) $existing['purchase_request_id'] !== $prId) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['ok' => false, 'message' => 'Item not found']);
            exit;
        }

        $updStmt = $pdo->prepare(
            'UPDATE purchase_request_items
             SET stock_property_no = :stock_property_no,
                 unit = :unit,
                 item_description = :item_description,
                 quantity = :quantity,
                 unit_cost = :unit_cost,
                 total_cost = :total_cost
             WHERE id = :item_id'
        );

        $updStmt->execute([
            ':stock_property_no' => $stockPropertyNo !== '' ? $stockPropertyNo : null,
            ':unit' => $unit !== '' ? $unit : null,
            ':item_description' => $itemDescription !== '' ? $itemDescription : null,
            ':quantity' => $quantity,
            ':unit_cost' => $unitCost,
            ':total_cost' => $totalCost,
            ':item_id' => $itemId,
        ]);

        $mode = 'update';
    } else {
        $insStmt = $pdo->prepare(
            'INSERT INTO purchase_request_items (
                purchase_request_id, stock_property_no, unit, item_description, quantity, unit_cost, total_cost
             ) VALUES (
                :pr_id, :stock_property_no, :unit, :item_description, :quantity, :unit_cost, :total_cost
             )'
        );

        $insStmt->execute([
            ':pr_id' => $prId,
            ':stock_property_no' => $stockPropertyNo !== '' ? $stockPropertyNo : null,
            ':unit' => $unit !== '' ? $unit : null,
            ':item_description' => $itemDescription !== '' ? $itemDescription : null,
            ':quantity' => $quantity,
            ':unit_cost' => $unitCost,
            ':total_cost' => $totalCost,
        ]);

        $itemId = (int) $pdo->lastInsertId();
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM purchase_request_items WHERE purchase_request_id = :pr_id');
    $countStmt->execute([':pr_id' => $prId]);
    $remainingCount = (int) $countStmt->fetchColumn();

    $prTotalCost = recalculatePrTotal($pdo, $prId);

    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'message' => $mode === 'update' ? 'Item updated successfully.' : 'Item added successfully.',
        'mode' => $mode,
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
    echo json_encode(['ok' => false, 'message' => 'Failed to save item']);
}
