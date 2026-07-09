<?php
require __DIR__ . '/lib.php';
installTables();
header('Content-Type: application/json; charset=utf-8');

function apiResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$requiredKey = trim((string)(appConfig()['api_key'] ?? ''));
$providedKey = trim((string)($_REQUEST['api_key'] ?? ''));
if ($requiredKey !== '' && !hash_equals($requiredKey, $providedKey)) {
    apiResponse(['success' => false, 'error' => 'API key无效'], 403);
}

$action = clean($_REQUEST['action'] ?? '', 30);

try {
    if ($action === 'init') {
        apiResponse(['success' => true]);
    }

    if ($action === 'save') {
        $number = clean($_POST['tracking_number'] ?? '', 50);
        if ($number === '') {
            apiResponse(['success' => false, 'error' => '单号不能为空'], 422);
        }

        $stmt = db()->prepare("
            INSERT IGNORE INTO tracking_info
            (tracking_number,country,city,state,service,shipped_date,delivered_time)
            VALUES (?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $number,
            clean($_POST['country'] ?? '', 20) ?: null,
            clean($_POST['city'] ?? '', 100) ?: null,
            clean($_POST['state'] ?? '', 50) ?: null,
            clean($_POST['service'] ?? '', 100) ?: null,
            clean($_POST['shipped_date'] ?? '', 50) ?: null,
            clean($_POST['delivered_time'] ?? '', 100) ?: null,
        ]);

        $inserted = $stmt->rowCount() === 1;
        apiResponse([
            'success' => true,
            'status' => $inserted ? 'inserted' : 'duplicate',
            'duplicate' => !$inserted,
        ]);
    }

    if ($action === 'export') {
        $rows = db()->query("
            SELECT tracking_number,country,city,state,service,
                   shipped_date,delivered_time,created_at
            FROM tracking_info ORDER BY id ASC
        ")->fetchAll();
        apiResponse(['success' => true, 'data' => $rows]);
    }

    apiResponse(['success' => false, 'error' => '不支持的action'], 400);
} catch (Throwable $e) {
    apiResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
