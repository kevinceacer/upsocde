<?php
require __DIR__ . '/lib.php';
installTables();
$user = requireLogin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'card') {
    verifyCsrf();
    $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)($_POST['card_code'] ?? '')));
    $hash = hash('sha256', $code);
    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare("SELECT * FROM card_codes WHERE code_hash=? FOR UPDATE");
        $stmt->execute([$hash]);
        $card = $stmt->fetch();

        if (!$card || $card['status'] !== 'unused') {
            throw new RuntimeException('卡密无效或已经使用。');
        }

        $orderNo = createOrderNo('CARD');
        $stmt = $pdo->prepare("\n            INSERT INTO recharge_orders(order_no,user_id,amount,method,status,paid_at)\n            VALUES (?,?,?,'card','paid',NOW())\n        ");
        $stmt->execute([$orderNo, (int)$user['id'], $card['amount']]);

        addBalance(
            $pdo,
            (int)$user['id'],
            (float)$card['amount'],
            'recharge_card',
            $orderNo,
            '卡密充值'
        );

        $stmt = $pdo->prepare("\n            UPDATE card_codes\n            SET status='used',used_by=?,used_at=NOW()\n            WHERE id=?\n        ");
        $stmt->execute([(int)$user['id'], $card['id']]);

        $pdo->commit();
        flash('success', '卡密充值成功，到账 ¥' . money($card['amount']) . '。');
        redirect(basePath('recharge.php'));
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect(basePath('recharge.php'));
    }
}

$alipayEnabled = setting('alipay_enabled', '0') === '1';
$alipayReady = $alipayEnabled
    && trim(setting('alipay_app_id')) !== ''
    && trim(secretSetting('alipay_private_key')) !== ''
    && trim(secretSetting('alipay_public_key')) !== '';

require __DIR__ . '/header.php';
?>
<style>
.alipay-form-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}
.alipay-form-row button{min-width:165px;height:42px}
.alipay-message{display:none;margin-top:14px;padding:11px 13px;border-radius:8px;line-height:1.6}
.alipay-message.show{display:block}.alipay-message.success{background:#ecfdf3;color:#067647}.alipay-message.error{background:#fef3f2;color:#b42318}.alipay-message.info{background:#eff8ff;color:#175cd3}
.alipay-qr-panel{display:none;margin-top:18px;padding:18px;border:1px solid #dbe3ee;border-radius:12px;background:#f8fafc;text-align:center}
.alipay-qr-panel.show{display:block}.alipay-qrcode{width:256px;min-height:256px;margin:0 auto;padding:10px;border:1px solid #e5e7eb;border-radius:12px;background:#fff;display:flex;align-items:center;justify-content:center}
.alipay-qrcode canvas{display:block;max-width:100%;height:auto}.alipay-order-info{margin-top:13px;color:#475467;line-height:1.8;word-break:break-all}.alipay-countdown{margin-top:8px;color:#b54708;font-weight:700}.alipay-open{margin-top:12px}.alipay-open a{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:9px 15px;border-radius:8px;background:#1677ff;color:#fff}.alipay-tip{margin-top:10px;color:#667085;font-size:13px}
@media(max-width:620px){.alipay-form-row{grid-template-columns:1fr}.alipay-form-row button{width:100%}.alipay-qrcode{width:230px;min-height:230px}}
</style>

<div class="grid2">
    <div class="card">
        <h2>卡密充值</h2>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="card">
            <p>
                <label>充值卡密</label>
                <input name="card_code" placeholder="输入后台生成的卡密" required>
            </p>
            <button type="submit">立即兑换</button>
        </form>
    </div>

    <div class="card">
        <h2>支付宝当面付</h2>

        <?php if ($alipayReady): ?>
            <form
                id="alipay-form"
                method="post"
                action="<?= h(basePath('alipay_create.php')) ?>"
                data-status-url="<?= h(basePath('alipay_status.php')) ?>"
            >
                <?= csrfField() ?>

                <div class="alipay-form-row">
                    <div>
                        <label>充值金额（元）</label>
                        <input
                            id="alipay-amount"
                            type="number"
                            name="amount"
                            min="1"
                            max="10000"
                            step="0.01"
                            value="10.00"
                            required
                        >
                    </div>
                    <button id="alipay-submit" type="submit">生成支付宝二维码</button>
                </div>
            </form>

            <div id="alipay-message" class="alipay-message" role="status"></div>

            <div id="alipay-qr-panel" class="alipay-qr-panel">
                <div id="alipay-qrcode" class="alipay-qrcode"></div>
                <div id="alipay-order-info" class="alipay-order-info"></div>
                <div id="alipay-countdown" class="alipay-countdown"></div>
                <div class="alipay-open">
                    <a id="alipay-open-link" href="#" rel="nofollow">在支付宝中打开</a>
                </div>
                <div class="alipay-tip">电脑端请使用支付宝扫码，手机端可点击上方按钮。</div>
            </div>
        <?php elseif ($alipayEnabled): ?>
            <div class="flash error">支付宝已开启，但 App ID、应用私钥或支付宝公钥尚未配置完整。</div>
        <?php else: ?>
            <div class="flash info">管理员尚未开启支付宝当面付。</div>
        <?php endif; ?>
    </div>
</div>

<?php if ($alipayReady): ?>
<script src="<?= h(basePath('assets/qrcode.min.js')) ?>"></script>
<script>
(function () {
    'use strict';

    const form = document.getElementById('alipay-form');
    const submitButton = document.getElementById('alipay-submit');
    const messageBox = document.getElementById('alipay-message');
    const qrPanel = document.getElementById('alipay-qr-panel');
    const qrContainer = document.getElementById('alipay-qrcode');
    const orderInfo = document.getElementById('alipay-order-info');
    const countdownBox = document.getElementById('alipay-countdown');
    const openLink = document.getElementById('alipay-open-link');
    const statusUrl = form.dataset.statusUrl;

    let orderNo = '';
    let statusTimer = null;
    let countdownTimer = null;
    let secondsLeft = 0;
    let checking = false;

    function stopTimers() {
        if (statusTimer !== null) {
            clearInterval(statusTimer);
            statusTimer = null;
        }
        if (countdownTimer !== null) {
            clearInterval(countdownTimer);
            countdownTimer = null;
        }
    }

    function showMessage(text, type) {
        messageBox.textContent = text;
        messageBox.className = 'alipay-message show ' + (type || 'info');
    }

    function formatTime(seconds) {
        const minutes = Math.floor(seconds / 60);
        const rest = seconds % 60;
        return String(minutes).padStart(2, '0') + ':' + String(rest).padStart(2, '0');
    }

    function startCountdown(totalSeconds) {
        secondsLeft = Math.max(1, Number(totalSeconds || 600));
        countdownBox.textContent = '二维码有效时间：' + formatTime(secondsLeft);

        countdownTimer = setInterval(function () {
            secondsLeft -= 1;
            countdownBox.textContent = '二维码有效时间：' + formatTime(Math.max(0, secondsLeft));

            if (secondsLeft <= 0) {
                stopTimers();
                showMessage('二维码已过期，请重新生成。', 'error');
                submitButton.disabled = false;
                submitButton.textContent = '重新生成二维码';
            }
        }, 1000);
    }

    async function parseJsonResponse(response) {
        const raw = await response.text();
        try {
            return JSON.parse(raw);
        } catch (error) {
            throw new Error('服务器返回异常：' + raw.substring(0, 200));
        }
    }

    async function checkStatus() {
        if (!orderNo || checking) {
            return;
        }

        checking = true;
        try {
            const response = await fetch(
                statusUrl + '?out_trade_no=' + encodeURIComponent(orderNo),
                {
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'same-origin'
                }
            );
            const data = await parseJsonResponse(response);

            if (!data.success) {
                throw new Error(data.message || '查询支付状态失败');
            }

            if (data.paid) {
                stopTimers();
                showMessage('支付成功，充值金额已经到账。', 'success');
                countdownBox.textContent = '订单已支付';
                submitButton.disabled = true;
                submitButton.textContent = '支付成功';

                const balance = document.querySelector('.balance');
                if (balance && data.balance) {
                    balance.textContent = '余额：¥' + data.balance;
                }

                setTimeout(function () {
                    window.location.reload();
                }, 1600);
            } else if (data.status === 'closed' || data.status === 'failed') {
                stopTimers();
                showMessage('订单已关闭，请重新生成二维码。', 'error');
                submitButton.disabled = false;
                submitButton.textContent = '重新生成二维码';
            }
        } catch (error) {
            console.error(error);
        } finally {
            checking = false;
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        stopTimers();
        orderNo = '';
        qrContainer.innerHTML = '';
        qrPanel.classList.remove('show');
        messageBox.className = 'alipay-message';

        submitButton.disabled = true;
        submitButton.textContent = '正在创建订单...';

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await parseJsonResponse(response);

            if (!response.ok || !data.success) {
                throw new Error(data.message || '支付宝订单创建失败');
            }

            if (!data.qr_code) {
                throw new Error('支付宝没有返回二维码内容');
            }

            if (typeof window.QRCode !== 'function') {
                throw new Error('二维码组件加载失败，请刷新页面重试');
            }

            orderNo = data.out_trade_no;

            new QRCode(qrContainer, {
                text: data.qr_code,
                width: 236,
                height: 236,
                correctLevel: QRCode.CorrectLevel.M
            });

            orderInfo.textContent = '充值金额：¥' + data.amount + '　订单号：' + data.out_trade_no;
            openLink.href = data.qr_code;
            qrPanel.classList.add('show');
            showMessage('二维码生成成功，请使用支付宝完成付款。', 'success');

            submitButton.disabled = false;
            submitButton.textContent = '重新生成二维码';

            startCountdown(data.expires_in || 600);
            statusTimer = setInterval(checkStatus, 5000);
            setTimeout(checkStatus, 1500);
        } catch (error) {
            showMessage(error.message || '二维码生成失败', 'error');
            submitButton.disabled = false;
            submitButton.textContent = '生成支付宝二维码';
        }
    });

    window.addEventListener('beforeunload', stopTimers);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
