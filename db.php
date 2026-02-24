<?php

declare(strict_types=1);

function loadDotEnv(string $path): void
{
    static $loaded = false;
    if ($loaded || !is_file($path)) {
        return;
    }
    $loaded = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $parts = explode('=', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $key = trim($parts[0]);
        if ($key === '') {
            continue;
        }

        $value = trim($parts[1]);
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    loadDotEnv(__DIR__ . DIRECTORY_SEPARATOR . '.env');

    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'converter_db';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            file_name VARCHAR(255) NOT NULL,
            fund_cluster TEXT,
            pr_no VARCHAR(100),
            responsibility_center_code VARCHAR(100),
            request_date VARCHAR(50),
            unit VARCHAR(100),
            item_description TEXT,
            quantity DECIMAL(18,4),
            unit_cost DECIMAL(18,4),
            total_cost DECIMAL(18,4),
            requested_by VARCHAR(255),
            designation1 VARCHAR(255),
            approved_by VARCHAR(255),
            designation2 VARCHAR(255),
            raw_text LONGTEXT,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS purchase_request_items (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            purchase_request_id BIGINT UNSIGNED NOT NULL,
            stock_property_no VARCHAR(100),
            unit VARCHAR(100),
            item_description TEXT,
            quantity DECIMAL(18,4),
            unit_cost DECIMAL(18,4),
            total_cost DECIMAL(18,4),
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            CONSTRAINT fk_purchase_request_items_pr
                FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $indexExistsStmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.statistics
         WHERE table_schema = :schema
           AND table_name = :table_name
           AND index_name = :index_name
         LIMIT 1'
    );
    $indexExistsStmt->execute([
        ':schema' => $name,
        ':table_name' => 'purchase_request_items',
        ':index_name' => 'idx_purchase_request_items_prid',
    ]);
    if ($indexExistsStmt->fetchColumn() === false) {
        $pdo->exec('CREATE INDEX idx_purchase_request_items_prid ON purchase_request_items(purchase_request_id)');
    }

    return $pdo;
}
