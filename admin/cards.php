<?php
require dirname(__DIR__) . '/lib.php';
installTables();
$admin = requireAdmin();
$generated = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $quantity = max(1, min(500, (int)($_POST['quantity'] ?? 1)));
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', clean($_POST['prefix'] ?? 'UPS', 8)));

        if ($amount <= 0 || $amount > 100000) {
            flash('error', '卡密金额不正确。');
        } else {
            $stmt = db()->prepare("
                INSERT INTO card_codes(code_hash,code_hint,amount,created_by)
                VALUES (?,?,?,?)
            ");

            for ($i=0; $i<$quantity; $i++) {
                do {
                    $plain = $prefix
                        . '-'
                        . strtoupper(bin2hex(random_bytes(4)))
                        . '-'
                        . strtoupper(bin2hex(random_bytes(4)));
                    try {
                        $stmt->execute([
                            hash('sha256', preg_replace('/[^A-Z0-9]/', '', $plain)),
                            substr($plain, -8),
                            money($amount),
                            (int)$admin['id'],
                        ]);
                        $ok = true;
                    } catch (PDOException $e) {
                        $ok = false;
                    }
                } while (!$ok);
                $generated[] = $plain;
            }
        }
    }

    if ($action === 'disable') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = db()->prepare("
            UPDATE card_codes SET status='disabled'
            WHERE id=? AND status='unused'
        ");
        $stmt->execute([$id]);
        flash('success', '卡密已经停用。');
        redirect(basePath('admin/cards.php'));
    }
}

$cards = db()->query("
    SELECT c.*,u.username AS used_username
    FROM card_codes c
    LEFT JOIN users u ON u.id=c.used_by
    ORDER BY c.id DESC
    LIMIT 300
")->fetchAll();

require __DIR__ . '/header.php';
?>
<div class="card">
<h1>生成充值卡密</h1>
<form method="post">
<?= csrfField() ?><input type="hidden" name="action" value="generate">
<div class="grid">
<div><label>面额（元）</label><input type="number" name="amount" min="0.01" step="0.01" value="10.00" required></div>
<div><label>生成数量（最多500）</label><input type="number" name="quantity" min="1" max="500" value="10" required></div>
<div><label>前缀</label><input name="prefix" value="UPS" maxlength="8"></div>
</div>
<p><button type="submit">生成卡密</button></p>
</form>
<?php if ($generated): ?>
<h3>本次生成结果（只显示这一次，请立即保存）</h3>
<textarea class="codebox" style="width:100%;min-height:260px" readonly><?= h(implode("\n",$generated)) ?></textarea>
<?php endif; ?>
</div>

<div class="card">
<h2>卡密记录</h2>
<div class="table"><table>
<thead><tr><th>ID</th><th>卡密尾号</th><th>金额</th><th>状态</th><th>使用用户</th><th>使用时间</th><th>创建时间</th><th>操作</th></tr></thead>
<tbody><?php foreach($cards as $c): ?><tr>
<td><?= (int)$c['id'] ?></td><td><?= h($c['code_hint']) ?></td><td>¥<?= h(money($c['amount'])) ?></td><td><?= h($c['status']) ?></td><td><?= h($c['used_username']) ?></td><td><?= h($c['used_at']) ?></td><td><?= h($c['created_at']) ?></td>
<td><?php if($c['status']==='unused'): ?><form method="post"><?= csrfField() ?><input type="hidden" name="action" value="disable"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button type="submit">停用</button></form><?php endif; ?></td>
</tr><?php endforeach; ?></tbody>
</table></div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
