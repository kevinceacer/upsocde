<?php
declare(strict_types=1);

require_once __DIR__ . '/alipay.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function alipayJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    redirect(basePath('recharge.php'));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alipayJson([
        'success' => false,
        'message' => '只允许 POST 请求',
    ], 405);
}

try {
    installTables();
    $user = requireLogin();
    verifyCsrf();

    if (setting('alipay_enabled', '0') !== '1') {
        throw new RuntimeException('支付宝当面付尚未开启');
    }

    if (
        trim(setting('alipay_app_id')) === ''
        || trim(secretSetting('alipay_private_key')) === ''
        || trim(secretSetting('alipay_public_key')) === ''
    ) {
        throw new RuntimeException('支付宝参数尚未配置完整');
    }

    $amountInput = trim((string)($_POST['amount'] ?? ''));
    if ($amountInput === '' || !is_numeric($amountInput)) {
        throw new RuntimeException('充值金额格式错误');
    }

    $amountFloat = round((float)$amountInput, 2);
    if ($amountFloat < 1 || $amountFloat > 10000) {
        throw new RuntimeException('充值金额必须在 1 至 10000 元之间');
    }

    $amount = money($amountFloat);
    $outTradeNo = createOrderNo('ALI');
    $subject = setting('site_name', 'UPS 单号查询') . ' - 账户充值';

    $stmt = db()->prepare("\n        INSERT INTO recharge_orders\n        (order_no, user_id, amount, method, status)\n        VALUES (?, ?, ?, 'alipay', 'pending')\n    ");
    $stmt->execute([
        $outTradeNo,
        (int)$user['id'],
        $amount,
    ]);

    try {
        $response = alipayRequest(
            'alipay.trade.precreate',
            [
                'out_trade_no' => $outTradeNo,
                'total_amount' => $amount,
                'subject' => $subject,
                'timeout_express' => '10m',
            ],
            absoluteUrl('alipay_notify.php')
        );

        $qrCode = trim((string)($response['qr_code'] ?? ''));
        if ($qrCode === '') {
            throw new RuntimeException('支付宝返回成功，但没有返回二维码内容');
        }

        $stmt = db()->prepare("\n            UPDATE recharge_orders\n            SET qr_code = ?\n            WHERE order_no = ? AND user_id = ?\n        ");
        $stmt->execute([
            $qrCode,
            $outTradeNo,
            (int)$user['id'],
        ]);
    } catch (Throwable $e) {
        $stmt = db()->prepare("\n            UPDATE recharge_orders\n            SET status = 'failed'\n            WHERE order_no = ? AND status = 'pending'\n        ");
        $stmt->execute([$outTradeNo]);
        throw $e;
    }

    alipayJson([
        'success' => true,
        'message' => '支付宝订单创建成功',
        'out_trade_no' => $outTradeNo,
        'amount' => $amount,
        'qr_code' => $qrCode,
        'expires_in' => 600,
        'status_url' => basePath('alipay_status.php'),
    ]);
} catch (Throwable $e) {
    alipayLog('create_error.log', $e->getMessage());

    alipayJson([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
