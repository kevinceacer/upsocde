<?php
declare(strict_types=1);

require_once __DIR__ . '/alipay.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function alipayStatusJson(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

try {
    installTables();
    $user = requireLogin();

    $outTradeNo = trim((string)($_GET['out_trade_no'] ?? ''));
    if ($outTradeNo === '') {
        alipayStatusJson([
            'success' => false,
            'message' => '缺少订单号',
        ], 400);
    }

    $stmt = db()->prepare("\n        SELECT *\n        FROM recharge_orders\n        WHERE order_no = ? AND user_id = ? AND method = 'alipay'\n        LIMIT 1\n    ");
    $stmt->execute([$outTradeNo, (int)$user['id']]);
    $order = $stmt->fetch();

    if (!$order) {
        alipayStatusJson([
            'success' => false,
            'message' => '订单不存在',
        ], 404);
    }

    if ((string)$order['status'] === 'pending') {
        try {
            $response = alipayRequest(
                'alipay.trade.query',
                ['out_trade_no' => $outTradeNo]
            );

            $tradeStatus = (string)($response['trade_status'] ?? '');
            $tradeNo = (string)($response['trade_no'] ?? '');
            $remoteAmount = money((float)($response['total_amount'] ?? 0));
            $localAmount = money((float)$order['amount']);

            if (
                in_array($tradeStatus, ['TRADE_SUCCESS', 'TRADE_FINISHED'], true)
                && hash_equals($localAmount, $remoteAmount)
            ) {
                completeRechargeOrder($outTradeNo, $tradeNo);
            } elseif ($tradeStatus === 'TRADE_CLOSED') {
                $close = db()->prepare("\n                    UPDATE recharge_orders\n                    SET status = 'closed'\n                    WHERE order_no = ? AND user_id = ? AND status = 'pending'\n                ");
                $close->execute([$outTradeNo, (int)$user['id']]);
            }
        } catch (Throwable $queryError) {
            // 未支付时支付宝可能返回“交易不存在”，保持 pending 即可。
            alipayLog(
                'query_status.log',
                '订单=' . $outTradeNo . '; ' . $queryError->getMessage()
            );
        }

        $stmt->execute([$outTradeNo, (int)$user['id']]);
        $order = $stmt->fetch();
    }

    $balanceStmt = db()->prepare('SELECT balance FROM users WHERE id = ?');
    $balanceStmt->execute([(int)$user['id']]);
    $balance = money((float)$balanceStmt->fetchColumn());

    alipayStatusJson([
        'success' => true,
        'out_trade_no' => $outTradeNo,
        'status' => (string)$order['status'],
        'paid' => (string)$order['status'] === 'paid',
        'amount' => money((float)$order['amount']),
        'balance' => $balance,
    ]);
} catch (Throwable $e) {
    alipayLog('status_error.log', $e->getMessage());
    alipayStatusJson([
        'success' => false,
        'message' => $e->getMessage(),
    ], 400);
}
