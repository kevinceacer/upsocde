<?php
require __DIR__ . '/lib.php';
installTables();

if (currentUser()) {
    redirect(basePath('profile.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $account = clean($_POST['account'] ?? '', 190);
    $password = (string)($_POST['password'] ?? '');

    $stmt = db()->prepare("
        SELECT * FROM users
        WHERE username=? OR email=?
        LIMIT 1
    ");
    $stmt->execute([$account, $account]);
    $user = $stmt->fetch();

    if (
        !$user
        || (int)$user['status'] !== 1
        || !password_verify($password, $user['password_hash'])
    ) {
        flash('error', '账号或密码错误。');
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        flash('success', '登录成功。');
        redirect(basePath('ups.php'));
    }
}

require __DIR__ . '/header.php';
?>
<div class="card" style="max-width:580px;margin:auto">
<h1>用户登录</h1>
<form method="post">
<?= csrfField() ?>
<p><label>用户名或邮箱</label><input name="account" required></p>
<p><label>密码</label><input type="password" name="password" required></p>
<button type="submit">登录</button>
</form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
