<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');
requireAuth(true);
$pdo = db();

function fetchStatusHistory(PDO $pdo, int $prId): array
{
    $stmt = $pdo->prepare(
        'SELECT id, status, created_at
         FROM purchase_request_statuses
         WHERE purchase_request_id = :pr_id
         ORDER BY created_at DESC, id DESC'
    );
    $stmt->execute([':pr_id' => $prId]);

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['id'] = (int) ($row['id'] ?? 0);
        $row['status'] = (string) ($row['status'] ?? '');
        $row['created_at'] = (string) ($row['created_at'] ?? '');
    }
    unset($row);

    return $rows;
}

function respondJson(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) {
        respondJson(400, ['ok' => false, 'message' => 'Invalid record id']);
    }

    $pr = findAccessiblePurchaseRequest($pdo, $id, 'id, pr_no');
    if ($pr === null) {
        respondJson(404, ['ok' => false, 'message' => 'Record not found']);
    }

    $statuses = fetchStatusHistory($pdo, $id);
    respondJson(200, [
        'ok' => true,
        'record_id' => (int) $pr['id'],
        'pr_no' => (string) ($pr['pr_no'] ?? ''),
        'latest_status' => (string) ($statuses[0]['status'] ?? ''),
        'statuses' => $statuses,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $prId = isset($_POST['pr_id']) ? (int) $_POST['pr_id'] : 0;
        $statusId = isset($_POST['status_id']) ? (int) $_POST['status_id'] : 0;

        if ($prId <= 0 || $statusId <= 0) {
            respondJson(400, ['ok' => false, 'message' => 'Invalid PR id or status id']);
        }

        $pr = findAccessiblePurchaseRequest($pdo, $prId, 'id, pr_no');
        if ($pr === null) {
            respondJson(404, ['ok' => false, 'message' => 'Record not found']);
        }

        $findStmt = $pdo->prepare(
            'SELECT id
             FROM purchase_request_statuses
             WHERE id = :status_id
               AND purchase_request_id = :pr_id
             LIMIT 1'
        );
        $findStmt->execute([
            ':status_id' => $statusId,
            ':pr_id' => $prId,
        ]);

        if ($findStmt->fetch() === false) {
            respondJson(404, ['ok' => false, 'message' => 'Status history entry not found']);
        }

        try {
            $deleteStmt = $pdo->prepare('DELETE FROM purchase_request_statuses WHERE id = :status_id');
            $deleteStmt->execute([':status_id' => $statusId]);
        } catch (Throwable $e) {
            respondJson(500, ['ok' => false, 'message' => 'Failed to delete status']);
        }

        $statuses = fetchStatusHistory($pdo, $prId);
        respondJson(200, [
            'ok' => true,
            'message' => 'Status history entry deleted.',
            'record_id' => $prId,
            'latest_status' => (string) ($statuses[0]['status'] ?? ''),
            'statuses' => $statuses,
        ]);
    }

    $prId = isset($_POST['pr_id']) ? (int) $_POST['pr_id'] : 0;
    $status = trim((string) ($_POST['status'] ?? ''));

    if ($prId <= 0) {
        respondJson(400, ['ok' => false, 'message' => 'Invalid PR id']);
    }

    if ($status === '') {
        respondJson(400, ['ok' => false, 'message' => 'Status is required']);
    }

    $statusLength = function_exists('mb_strlen') ? mb_strlen($status, 'UTF-8') : strlen($status);
    if ($statusLength > 255) {
        respondJson(400, ['ok' => false, 'message' => 'Status must be 255 characters or fewer']);
    }

    $pr = findAccessiblePurchaseRequest($pdo, $prId, 'id, pr_no');
    if ($pr === null) {
        respondJson(404, ['ok' => false, 'message' => 'Record not found']);
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO purchase_request_statuses (purchase_request_id, user_id, status)
             VALUES (:purchase_request_id, :user_id, :status)'
        );
        $stmt->execute([
            ':purchase_request_id' => $prId,
            ':user_id' => currentUserId(),
            ':status' => $status,
        ]);
    } catch (Throwable $e) {
        respondJson(500, ['ok' => false, 'message' => 'Failed to save status']);
    }

    $statuses = fetchStatusHistory($pdo, $prId);
    respondJson(200, [
        'ok' => true,
        'message' => 'Status saved successfully.',
        'record_id' => $prId,
        'latest_status' => (string) ($statuses[0]['status'] ?? $status),
        'statuses' => $statuses,
    ]);
}

respondJson(405, ['ok' => false, 'message' => 'Method not allowed']);
