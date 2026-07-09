<?php
declare(strict_types=1);

require dirname(__DIR__) . '/lib.php';
require_once dirname(__DIR__) . '/alipay.php';

installTables();
requireAdmin();

const ALIPAY_SECRET_MASK = '******** 已保存，输入新内容可替换';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    try {
        $siteName = clean($_POST['site_name'] ?? '', 100) ?: 'UPS 单号查询';
        $price = max(0.01, round((float)($_POST['tracking_price'] ?? 1), 2));
        $gateway = clean($_POST['alipay_gateway'] ?? '', 255)
            ?: 'https://openapi.alipay.com/gateway.do';
        $appId = clean($_POST['alipay_app_id'] ?? '', 100);
        $privateKey = trim((string)($_POST['alipay_private_key'] ?? ''));
        $publicKey = trim((string)($_POST['alipay_public_key'] ?? ''));

        if (!filter_var($gateway, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('支付宝网关地址格式不正确。');
        }

        if (
            $privateKey !== ''
            && $privateKey !== ALIPAY_SECRET_MASK
            && alipayLoadPrivateKey($privateKey) === false
        ) {
            throw new RuntimeException('应用私钥格式不正确，请检查是否复制完整。');
        }

        if (
            $publicKey !== ''
            && $publicKey !== ALIPAY_SECRET_MASK
            && alipayLoadPublicKey($publicKey) === false
        ) {
            throw new RuntimeException('支付宝公钥格式不正确，请确认填写的是支付宝公钥，不是应用公钥。');
        }

        setSetting('site_name', $siteName);
        setSetting('tracking_price', money($price));
        setSetting('alipay_enabled', isset($_POST['alipay_enabled']) ? '1' : '0');
        setSetting('alipay_gateway', $gateway);
        setSetting('alipay_app_id', $appId);

        if (isset($_POST['clear_alipay_private_key'])) {
            setSetting('alipay_private_key', '');
        } elseif (
            $privateKey !== ''
            && $privateKey !== ALIPAY_SECRET_MASK
        ) {
            setSecretSetting('alipay_private_key', $privateKey);
        }

        if (isset($_POST['clear_alipay_public_key'])) {
            setSetting('alipay_public_key', '');
        } elseif (
            $publicKey !== ''
            && $publicKey !== ALIPAY_SECRET_MASK
        ) {
            setSecretSetting('alipay_public_key', $publicKey);
        }

        flash('success', '设置已经保存。密钥框显示遮罩代表数据库中已有密钥。');
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
    }

    redirect(basePath('admin/settings.php'));
}

$privateKeySaved = secretSetting('alipay_private_key') !== '';
$publicKeySaved = secretSetting('alipay_public_key') !== '';

require __DIR__ . '/header.php';
?>
<div class="card">
    <h1>系统与支付宝设置</h1>

    <form method="post" autocomplete="off">
        <?= csrfField() ?>

        <div class="grid">
            <div>
                <label>网站名称</label>
                <input
                    name="site_name"
                    value="<?= h(setting('site_name')) ?>"
                    required
                >
            </div>

            <div>
                <label>每条完整单号价格（元）</label>
                <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    name="tracking_price"
                    value="<?= h(setting('tracking_price', '1.00')) ?>"
                    required
                >
            </div>

            <div>
                <label>支付宝网关</label>
                <input
                    name="alipay_gateway"
                    value="<?= h(setting('alipay_gateway', 'https://openapi.alipay.com/gateway.do')) ?>"
                    required
                >
            </div>

            <div>
                <label>支付宝 App ID</label>
                <input
                    name="alipay_app_id"
                    value="<?= h(setting('alipay_app_id')) ?>"
                    autocomplete="off"
                >
            </div>
        </div>

        <p>
            <label>
                <input
                    style="width:auto;min-height:auto"
                    type="checkbox"
                    name="alipay_enabled"
                    value="1"
                    <?= setting('alipay_enabled') === '1' ? 'checked' : '' ?>
                >
                开启支付宝当面付
            </label>
        </p>

        <div class="grid" style="grid-template-columns:1fr 1fr">
            <div>
                <label>应用私钥</label>
                <textarea
                    name="alipay_private_key"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="支持带 PEM 头尾或纯密钥内容"
                ><?= h($privateKeySaved ? ALIPAY_SECRET_MASK : '') ?></textarea>

                <?php if ($privateKeySaved): ?>
                    <p style="margin:8px 0 0;color:#067647">✓ 应用私钥已加密保存</p>
                    <label style="margin-top:8px">
                        <input
                            style="width:auto;min-height:auto"
                            type="checkbox"
                            name="clear_alipay_private_key"
                            value="1"
                        >
                        清除已保存的应用私钥
                    </label>
                <?php else: ?>
                    <p style="margin:8px 0 0;color:#b42318">尚未保存应用私钥</p>
                <?php endif; ?>
            </div>

            <div>
                <label>支付宝公钥</label>
                <textarea
                    name="alipay_public_key"
                    autocomplete="off"
                    spellcheck="false"
                    placeholder="注意：这里填写支付宝公钥，不是应用公钥"
                ><?= h($publicKeySaved ? ALIPAY_SECRET_MASK : '') ?></textarea>

                <?php if ($publicKeySaved): ?>
                    <p style="margin:8px 0 0;color:#067647">✓ 支付宝公钥已加密保存</p>
                    <label style="margin-top:8px">
                        <input
                            style="width:auto;min-height:auto"
                            type="checkbox"
                            name="clear_alipay_public_key"
                            value="1"
                        >
                        清除已保存的支付宝公钥
                    </label>
                <?php else: ?>
                    <p style="margin:8px 0 0;color:#b42318">尚未保存支付宝公钥</p>
                <?php endif; ?>
            </div>
        </div>

        <p class="muted">
            异步通知地址：<?= h(absoluteUrl('alipay_notify.php')) ?>
        </p>
        <p class="muted">
            密钥不会以明文回显。显示“******** 已保存”表示保存成功；直接再次保存不会覆盖原密钥。
        </p>

        <button type="submit">保存设置</button>
    </form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
