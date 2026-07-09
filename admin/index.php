<?php
require __DIR__ . '/header.php';
$stats = [
    '用户数' => (int)db()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
    '物流数据' => (int)db()->query("SELECT COUNT(*) FROM tracking_info")->fetchColumn(),
    '已购买单号' => (int)db()->query("SELECT COUNT(*) FROM purchase_records")->fetchColumn(),
    '未使用卡密' => (int)db()->query("SELECT COUNT(*) FROM card_codes WHERE status='unused'")->fetchColumn(),
];
$paid = db()->query("SELECT COALESCE(SUM(amount),0) FROM recharge_orders WHERE status='paid'")->fetchColumn();
?>
<div class="grid">
<?php foreach($stats as $name=>$value): ?><div class="card"><div class="muted"><?= h($name) ?></div><div class="stat"><?= number_format($value) ?></div></div><?php endforeach; ?>
</div>
<div class="card"><div class="muted">累计充值入账</div><div class="stat">¥<?= h(money($paid)) ?></div></div>
<?php require __DIR__ . '/footer.php'; ?>
