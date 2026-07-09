<?php
require dirname(__DIR__) . '/lib.php';
installTables();
$admin = requireAdmin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $userId = (int)($_POST['user_id'] ?? 0);
    $amount = round((float)($_POST['amount'] ?? 0), 2);
    $remark = clean($_POST['remark'] ?? '管理员调整余额', 255);

    if ($userId <= 0 || $amount == 0.0) {
        flash('error', '用户或调整金额不正确。');
    } else {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            addBalance(
                $pdo,
                $userId,
                $amount,
                'admin_adjust',
                'ADMIN-' . $admin['id'],
                $remark
            );
            $pdo->commit();
            flash('success', '用户余额调整成功。');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', $e->getMessage());
        }
    }
    redirect(basePath('admin/users.php'));
}

$q = clean($_GET['q'] ?? '', 100);
if ($q !== '') {
    $stmt = db()->prepare("
        SELECT * FROM users
        WHERE username LIKE ? OR email LIKE ?
        ORDER BY id DESC LIMIT 300
    ");
    $stmt->execute(['%'.$q.'%', '%'.$q.'%']);
    $users = $stmt->fetchAll();
} else {
    $users = db()->query("SELECT * FROM users ORDER BY id DESC LIMIT 300")->fetchAll();
}

require __DIR__ . '/header.php';
?>
<div class="card">
<h1>用户与余额</h1>
<form method="get" class="actions"><input style="max-width:320px" name="q" value="<?= h($q) ?>" placeholder="用户名或邮箱"><button>搜索</button></form>
</div>
<div class="card"><div class="table"><table>
<thead><tr><th>ID</th><th>用户名</th><th>邮箱</th><th>余额</th><th>角色</th><th>注册时间</th><th>调整余额</th></tr></thead>
<tbody><?php foreach($users as $u): ?><tr>
<td><?= (int)$u['id'] ?></td><td><?= h($u['username']) ?></td><td><?= h($u['email']) ?></td><td>¥<?= h(money($u['balance'])) ?></td><td><?= h($u['role']) ?></td><td><?= h($u['created_at']) ?></td>
<td><form method="post" class="actions"><?= csrfField() ?><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>"><input style="width:110px" type="number" step="0.01" name="amount" placeholder="+10 或 -10" required><input style="width:180px" name="remark" placeholder="备注"><button>调整</button></form></td>
</tr><?php endforeach; ?></tbody></table></div></div>
<?php require __DIR__ . '/footer.php'; ?>
