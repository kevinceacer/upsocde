<?php
require __DIR__ . '/lib.php';
installTables();

$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(basePath('index.php'));
}

verifyCsrf();

$trackingId = (int)($_POST['tracking_id'] ?? 0);
$price = max(0.01, (float)setting('tracking_price', '1.00'));

if ($trackingId <= 0) {
    flash('error', '单号参数不正确。');
    redirect(basePath('index.php'));
}

$pdo = db();
$pdo->beginTransaction();

try {
    /*
     * 先锁住 tracking_info 当前行。
     * 多个用户同时点击购买时，只允许第一个事务完成购买。
     */
    $stmt = $pdo->prepare("
        SELECT id
        FROM tracking_info
        WHERE id = ?
        FOR UPDATE
    ");
    $stmt->execute([$trackingId]);

    if (!$stmt->fetch()) {
        throw new RuntimeException('该单号不存在。');
    }

    /*
     * 按 tracking_id 全局检查，不再只检查当前用户。
     * 任何用户买过后，其他用户都不能再次购买。
     */
    $stmt = $pdo->prepare("
        SELECT id, user_id
        FROM purchase_records
        WHERE tracking_id = ?
        LIMIT 1
    ");
    $stmt->execute([$trackingId]);
    $existingPurchase = $stmt->fetch();

    if ($existingPurchase) {
        $pdo->commit();

        flash(
            'info',
            '该单号已被购买并从前台下架，本次没有扣除余额。'
        );

        redirect(basePath('index.php'));
    }

    /*
     * 确认未售出以后才扣款。
     * addBalance 与购买记录都在同一个事务中，任何一步失败都会回滚。
     */
    addBalance(
        $pdo,
        (int)$user['id'],
        -$price,
        'purchase_tracking',
        'TRACK-' . $trackingId,
        '购买UPS完整单号'
    );

    $stmt = $pdo->prepare("
        INSERT INTO purchase_records (
            user_id,
            tracking_id,
            amount
        ) VALUES (?, ?, ?)
    ");
    $stmt->execute([
        (int)$user['id'],
        $trackingId,
        money($price),
    ]);

    $pdo->commit();

    flash(
        'success',
        '购买成功。该单号已从前台下架，可在个人中心查看完整单号。'
    );
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    flash('error', $e->getMessage());
}

redirect(basePath('index.php'));
