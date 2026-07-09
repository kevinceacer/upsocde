<?php
require __DIR__ . '/lib.php';
installTables();
$user = requireLogin();

$stmt = db()->prepare("
    SELECT p.amount,p.created_at,t.tracking_number,t.country,t.city,t.state,t.delivered_time
    FROM purchase_records p
    JOIN tracking_info t ON t.id=p.tracking_id
    WHERE p.user_id=?
    ORDER BY p.id DESC
    LIMIT 100
");
$stmt->execute([(int)$user['id']]);
$purchases = $stmt->fetchAll();

$stmt = db()->prepare("
    SELECT * FROM balance_logs
    WHERE user_id=?
    ORDER BY id DESC
    LIMIT 100
");
$stmt->execute([(int)$user['id']]);
$logs = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>
<div class="grid2">
<div class="card"><div class="muted">当前余额</div><div class="stat">¥<?= h(money($user['balance'])) ?></div><p><a class="btn" href="<?= h(basePath('recharge.php')) ?>">立即充值</a></p></div>
<div class="card"><div class="muted">账号</div><h2><?= h($user['username']) ?></h2><div><?= h($user['email']) ?></div></div>
</div>

<div class="card">
<h2>已购买单号</h2>
<div class="table"><table>
<thead><tr><th>完整单号</th><th>国家</th><th>城市</th><th>州</th><th>妥投时间</th><th>支付金额</th><th>购买时间</th></tr></thead>
<tbody>
<?php if (!$purchases): ?><tr><td colspan="7" class="muted">暂无购买记录</td></tr><?php endif; ?>
<?php foreach ($purchases as $row): ?><tr>
<td class="full"><?= h($row['tracking_number']) ?></td><td><?= h($row['country']) ?></td><td><?= h($row['city']) ?></td><td><?= h($row['state']) ?></td><td><?= h($row['delivered_time']) ?></td><td>¥<?= h(money($row['amount'])) ?></td><td><?= h($row['created_at']) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
</div>

<div class="card">
<h2>余额明细</h2>
<div class="table"><table>
<thead><tr><th>变动</th><th>变动后余额</th><th>类型</th><th>备注</th><th>时间</th></tr></thead>
<tbody>
<?php if (!$logs): ?><tr><td colspan="5" class="muted">暂无余额记录</td></tr><?php endif; ?>
<?php foreach ($logs as $row): ?><tr>
<td><?= (float)$row['amount'] >= 0 ? '+' : '' ?>¥<?= h(money($row['amount'])) ?></td><td>¥<?= h(money($row['balance_after'])) ?></td><td><?= h($row['type']) ?></td><td><?= h($row['remark']) ?></td><td><?= h($row['created_at']) ?></td>
</tr><?php endforeach; ?>
</tbody></table></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
