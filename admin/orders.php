<?php
require __DIR__ . '/header.php';
$orders = db()->query("
    SELECT o.*,u.username
    FROM recharge_orders o
    JOIN users u ON u.id=o.user_id
    ORDER BY o.id DESC LIMIT 500
")->fetchAll();
$purchases = db()->query("
    SELECT p.*,u.username,t.tracking_number
    FROM purchase_records p
    JOIN users u ON u.id=p.user_id
    JOIN tracking_info t ON t.id=p.tracking_id
    ORDER BY p.id DESC LIMIT 500
")->fetchAll();
?>
<div class="card">
<h1>充值订单</h1>
<div class="table"><table>
<thead><tr><th>订单号</th><th>用户</th><th>金额</th><th>方式</th><th>状态</th><th>支付宝交易号</th><th>创建时间</th><th>支付时间</th></tr></thead>
<tbody><?php foreach($orders as $o): ?><tr>
<td><?= h($o['order_no']) ?></td><td><?= h($o['username']) ?></td><td>¥<?= h(money($o['amount'])) ?></td><td><?= h($o['method']) ?></td><td><?= h($o['status']) ?></td><td><?= h($o['trade_no']) ?></td><td><?= h($o['created_at']) ?></td><td><?= h($o['paid_at']) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
</div>
<div class="card">
<h2>单号购买记录</h2>
<div class="table"><table>
<thead><tr><th>用户</th><th>完整单号</th><th>金额</th><th>购买时间</th></tr></thead>
<tbody><?php foreach($purchases as $p): ?><tr>
<td><?= h($p['username']) ?></td><td><?= h($p['tracking_number']) ?></td><td>¥<?= h(money($p['amount'])) ?></td><td><?= h($p['created_at']) ?></td>
</tr><?php endforeach; ?></tbody></table></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
