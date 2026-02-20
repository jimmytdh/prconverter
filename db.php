<?php

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
    if (!is_dir($baseDir)) {
        mkdir($baseDir, 0777, true);
    }

    $dbPath = $baseDir . DIRECTORY_SEPARATOR . 'app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            file_name TEXT NOT NULL,
            fund_cluster TEXT,
            pr_no TEXT,
            responsibility_center_code TEXT,
            request_date TEXT,
            unit TEXT,
            item_description TEXT,
            quantity REAL,
            unit_cost REAL,
            total_cost REAL,
            requested_by TEXT,
            designation1 TEXT,
            approved_by TEXT,
            designation2 TEXT,
            raw_text TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_request_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            purchase_request_id INTEGER NOT NULL,
            stock_property_no TEXT,
            unit TEXT,
            item_description TEXT,
            quantity REAL,
            unit_cost REAL,
            total_cost REAL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE INDEX IF NOT EXISTS idx_purchase_request_items_prid
         ON purchase_request_items(purchase_request_id)'
    );

    return $pdo;
}
