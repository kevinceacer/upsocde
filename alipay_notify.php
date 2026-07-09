<?php
declare(strict_types=1);

require_once __DIR__ . '/alipay.php';

header('Content-Type: text/plain; charset=utf-8');

function alipayNotifyFailure(string $reason): never
{
    alipayLog('notify_error.log', $reason);
    echo 'failure';
    exit;
}

try {
    installTables();

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        alipayNotifyFailure('通知请求不是 POST');
    }

    $params = $_POST;

    if (!alipayVerify($params)) {
        alipayNotifyFailure('异步通知验签失败');
    }

    $appId = trim((string)($params['app_id'] ?? ''));
    $expectedAppId = trim(setting('alipay_app_id'));
    if (
        $appId === ''
        || $expectedAppId === ''
        || !hash_equals($expectedAppId, $appId)
    ) {
        alipayNotifyFailure('异步通知 App ID 不匹配');
    }

    $outTradeNo = trim((string)($params['out_trade_no'] ?? ''));
    $tradeNo = trim((string)($params['trade_no'] ?? ''));
    $tradeStatus = trim((string)($params['trade_status'] ?? ''));
    $totalAmount = money((float)($params['total_amount'] ?? 0));

    if ($outTradeNo === '') {
        alipayNotifyFailure('异步通知缺少商户订单号');
    }

    if (!in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)) {
        alipayLog(
            'notify_status.log',
            '订单=' . $outTradeNo . '; 状态=' . $tradeStatus
        );
        echo 'success';
        exit;
    }

    $stmt = db()->prepare("\n        SELECT order_no, amount, method, status\n        FROM recharge_orders\n        WHERE order_no = ?\n        LIMIT 1\n    ");
    $stmt->execute([$outTradeNo]);
    $order = $stmt->fetch();

    if (!$order) {
        alipayNotifyFailure('本地充值订单不存在：' . $outTradeNo);
    }

    if ((string)$order['method'] !== 'alipay') {
        alipayNotifyFailure('订单支付方式不匹配：' . $outTradeNo);
    }

    $expectedAmount = money((float)$order['amount']);
    if (!hash_equals($expectedAmount, $totalAmount)) {
        alipayNotifyFailure(
            '订单金额不一致，本地=' . $expectedAmount
            . '，支付宝=' . $totalAmount
        );
    }

    completeRechargeOrder($outTradeNo, $tradeNo);

    alipayLog(
        'notify_success.log',
        '订单=' . $outTradeNo . '; 支付宝交易号=' . $tradeNo
    );

    echo 'success';
} catch (Throwable $e) {
    alipayNotifyFailure($e->getMessage());
}
