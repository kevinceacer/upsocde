<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
date_default_timezone_set($config['timezone'] ?? 'Asia/Shanghai');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($config['session_name'] ?? 'UPS_MEMBER_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function appConfig(): array
{
    global $config;
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = appConfig()['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'],
        (int)$cfg['port'],
        $cfg['database'],
        $cfg['charset']
    );

    $pdo = new PDO($dsn, $cfg['username'], $cfg['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}


/**
 * 获取现有主表字段的真实整数类型。
 *
 * MySQL 外键要求两边类型完全一致，包括 INT/BIGINT 和 UNSIGNED。
 * 旧 tracking_info.id 如果是 INT（有符号），关联字段也必须使用 INT。
 */
function getIntegerColumnType(
    PDO $pdo,
    string $tableName,
    string $columnName,
    string $fallback = 'INT UNSIGNED'
): string {
    $stmt = $pdo->prepare("
        SELECT COLUMN_TYPE
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ");
    $stmt->execute([$tableName, $columnName]);

    $columnType = strtolower(trim((string)$stmt->fetchColumn()));

    // 只接受整数类型，防止异常内容被拼接到建表 SQL。
    if (
        preg_match(
            '/^(tinyint|smallint|mediumint|int|bigint)(\(\d+\))?( unsigned)?$/i',
            $columnType
        )
    ) {
        return strtoupper($columnType);
    }

    return $fallback;
}

function installTables(): void
{
    $pdo = db();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tracking_info (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tracking_number VARCHAR(50) NOT NULL,
            country VARCHAR(20) DEFAULT NULL,
            city VARCHAR(100) DEFAULT NULL,
            state VARCHAR(50) DEFAULT NULL,
            service VARCHAR(100) DEFAULT NULL,
            shipped_date VARCHAR(50) DEFAULT NULL,
            delivered_time VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_tracking_number (tracking_number),
            KEY idx_country_city_state (country, city, state),
            KEY idx_delivered_time (delivered_time)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            role ENUM('user','admin') NOT NULL DEFAULT 'user',
            status TINYINT NOT NULL DEFAULT 1,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_username (username),
            UNIQUE KEY uk_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value LONGTEXT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * tracking_info 已存在时，读取其 id 的真实类型。
     * 这样不会修改或删除原 tracking_info 表及其中的数据。
     */
    $trackingIdType = getIntegerColumnType(
        $pdo,
        'tracking_info',
        'id',
        'INT UNSIGNED'
    );

    $userIdType = getIntegerColumnType(
        $pdo,
        'users',
        'id',
        'INT UNSIGNED'
    );

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS purchase_records (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userIdType} NOT NULL,
            tracking_id {$trackingIdType} NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_user_tracking (user_id, tracking_id),
            KEY idx_user_created (user_id, created_at),
            CONSTRAINT fk_purchase_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE,
            CONSTRAINT fk_purchase_tracking FOREIGN KEY (tracking_id)
                REFERENCES tracking_info(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS card_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code_hash CHAR(64) NOT NULL,
            code_hint VARCHAR(20) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status ENUM('unused','used','disabled') NOT NULL DEFAULT 'unused',
            used_by {$userIdType} DEFAULT NULL,
            used_at DATETIME DEFAULT NULL,
            created_by {$userIdType} NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_code_hash (code_hash),
            KEY idx_card_status (status),
            CONSTRAINT fk_card_used_by FOREIGN KEY (used_by)
                REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_card_created_by FOREIGN KEY (created_by)
                REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS recharge_orders (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_no VARCHAR(40) NOT NULL,
            user_id {$userIdType} NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            method ENUM('card','alipay','admin') NOT NULL,
            status ENUM('pending','paid','closed','failed') NOT NULL DEFAULT 'pending',
            trade_no VARCHAR(100) DEFAULT NULL,
            qr_code TEXT DEFAULT NULL,
            paid_at DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_order_no (order_no),
            KEY idx_user_status (user_id, status),
            CONSTRAINT fk_recharge_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS balance_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id {$userIdType} NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            balance_after DECIMAL(12,2) NOT NULL,
            type VARCHAR(40) NOT NULL,
            related_no VARCHAR(100) DEFAULT NULL,
            remark VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_balance_user (user_id, created_at),
            CONSTRAINT fk_balance_user FOREIGN KEY (user_id)
                REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $defaults = [
        'site_name' => 'UPS 单号查询',
        'tracking_price' => '1.00',
        'alipay_enabled' => '0',
        'alipay_gateway' => 'https://openapi.alipay.com/gateway.do',
        'alipay_app_id' => '',
        'alipay_private_key' => '',
        'alipay_public_key' => '',
    ];

    $stmt = $pdo->prepare("
        INSERT IGNORE INTO settings (setting_key, setting_value)
        VALUES (:k, :v)
    ");
    foreach ($defaults as $key => $value) {
        $stmt->execute([':k' => $key, ':v' => $value]);
    }
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function clean($value, int $length = 190): string
{
    return mb_substr(trim((string)$value), 0, $length, 'UTF-8');
}

function money($value): string
{
    return number_format((float)$value, 2, '.', '');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function basePath(string $path = ''): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $base = preg_replace('#/admin/[^/]+$#', '', $script);
    $base = preg_replace('#/[^/]+$#', '', $base);
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function absoluteUrl(string $path): string
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . basePath($path);
}

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}

function verifyCsrf(): void
{
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['csrf'] ?? ''), $token)) {
        http_response_code(419);
        exit('请求已过期，请返回后重新提交。');
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function pullFlashes(): array
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $items;
}

function currentUser(): ?array
{
    static $cached = false;
    static $user = null;

    if ($cached) {
        return $user;
    }
    $cached = true;

    $id = (int)($_SESSION['user_id'] ?? 0);
    if ($id <= 0) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT id, username, email, balance, role, status, created_at
        FROM users WHERE id = ?
    ");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['status'] !== 1) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = $row;
    return $user;
}

function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        flash('error', '请先登录。');
        redirect(basePath('login.php'));
    }
    return $user;
}

function requireAdmin(): array
{
    $user = requireLogin();
    if (($user['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('无权访问。');
    }
    return $user;
}

function setting(string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $stmt = db()->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    $cache[$key] = ($value === false) ? $default : (string)$value;
    return $cache[$key];
}

function setSetting(string $key, string $value): void
{
    $stmt = db()->prepare("
        INSERT INTO settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([$key, $value]);
}

function encryptSecret(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    $key = hash('sha256', (string)appConfig()['app_secret'], true);
    $iv = random_bytes(16);
    $cipher = openssl_encrypt(
        $plaintext,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    if ($cipher === false) {
        throw new RuntimeException('密钥加密失败');
    }

    return base64_encode($iv . $cipher);
}

function decryptSecret(string $encoded): string
{
    if ($encoded === '') {
        return '';
    }

    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 17) {
        return '';
    }

    $key = hash('sha256', (string)appConfig()['app_secret'], true);
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);

    $plain = openssl_decrypt(
        $cipher,
        'AES-256-CBC',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return $plain === false ? '' : $plain;
}

function secretSetting(string $key): string
{
    return decryptSecret(setting($key));
}

function setSecretSetting(string $key, string $value): void
{
    setSetting($key, encryptSecret($value));
}

function maskTracking(string $number): string
{
    $length = strlen($number);
    if ($length <= 8) {
        return substr($number, 0, 2) . str_repeat('*', max(1, $length - 4))
            . substr($number, -2);
    }

    return substr($number, 0, 4)
        . str_repeat('*', max(4, $length - 8))
        . substr($number, -4);
}

function createOrderNo(string $prefix): string
{
    return strtoupper($prefix)
        . date('YmdHis')
        . strtoupper(bin2hex(random_bytes(4)));
}

function addBalance(
    PDO $pdo,
    int $userId,
    float $amount,
    string $type,
    string $relatedNo = '',
    string $remark = ''
): float {
    $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $old = $stmt->fetchColumn();

    if ($old === false) {
        throw new RuntimeException('用户不存在');
    }

    $newBalance = round((float)$old + $amount, 2);
    if ($newBalance < 0) {
        throw new RuntimeException('余额不足');
    }

    $stmt = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
    $stmt->execute([money($newBalance), $userId]);

    $stmt = $pdo->prepare("
        INSERT INTO balance_logs
        (user_id, amount, balance_after, type, related_no, remark)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        money($amount),
        money($newBalance),
        $type,
        $relatedNo ?: null,
        $remark ?: null,
    ]);

    return $newBalance;
}

function completeRechargeOrder(string $orderNo, string $tradeNo = ''): bool
{
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("
            SELECT * FROM recharge_orders WHERE order_no = ? FOR UPDATE
        ");
        $stmt->execute([$orderNo]);
        $order = $stmt->fetch();

        if (!$order) {
            throw new RuntimeException('充值订单不存在');
        }

        if ($order['status'] === 'paid') {
            $pdo->commit();
            return true;
        }

        if ($order['status'] !== 'pending') {
            throw new RuntimeException('订单状态不可支付');
        }

        addBalance(
            $pdo,
            (int)$order['user_id'],
            (float)$order['amount'],
            'recharge_' . $order['method'],
            $orderNo,
            '充值到账'
        );

        $stmt = $pdo->prepare("
            UPDATE recharge_orders
            SET status='paid', trade_no=?, paid_at=NOW()
            WHERE id=?
        ");
        $stmt->execute([$tradeNo ?: null, $order['id']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
