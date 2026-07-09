<?php
require __DIR__ . '/lib.php';

$error = '';
$installed = false;

try {
    installTables();
    $installed = (int)db()->query(
        "SELECT COUNT(*) FROM users WHERE role='admin'"
    )->fetchColumn() > 0;
} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($installed) {
        exit('系统已经安装。');
    }

    $username = clean($_POST['username'] ?? '', 50);
    $email = clean($_POST['email'] ?? '', 190);
    $password = (string)($_POST['password'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{4,50}$/', $username)) {
        $error = '管理员用户名只能使用字母、数字、下划线，长度4-50位。';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式错误。';
    } elseif (strlen($password) < 8) {
        $error = '密码至少8位。';
    } else {
        try {
            $stmt = db()->prepare("
                INSERT INTO users
                (username,email,password_hash,role,balance)
                VALUES (?,?,?,'admin',0)
            ");
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $installed = true;
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="card" style="max-width:700px;margin:auto">
<h1>系统安装</h1>
<?php if ($error): ?><div class="flash error"><?= h($error) ?></div><?php endif; ?>

<?php if ($installed): ?>
<div class="flash success">数据表和管理员已经创建完成。</div>
<div class="actions">
<a class="btn" href="<?= h(basePath('login.php')) ?>">进入登录</a>
</div>
<p class="muted">安装后建议删除或重命名 install.php。</p>
<?php else: ?>
<form method="post">
<?= csrfField() ?>
<div class="grid2">
<div><label>管理员用户名</label><input name="username" required></div>
<div><label>管理员邮箱</label><input type="email" name="email" required></div>
<div><label>管理员密码</label><input type="password" name="password" minlength="8" required></div>
</div>
<p><button type="submit">安装并创建管理员</button></p>
</form>
<?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
