<?php
require __DIR__ . '/lib.php';
installTables();

if (currentUser()) {
    redirect(basePath('profile.php'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $username = clean($_POST['username'] ?? '', 50);
    $email = clean($_POST['email'] ?? '', 190);
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_]{4,50}$/', $username)) {
        flash('error', '用户名只能使用字母、数字和下划线，长度4-50位。');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash('error', '邮箱格式不正确。');
    } elseif (strlen($password) < 8) {
        flash('error', '密码至少8位。');
    } elseif ($password !== $confirm) {
        flash('error', '两次输入的密码不一致。');
    } else {
        try {
            $stmt = db()->prepare("
                INSERT INTO users (username,email,password_hash)
                VALUES (?,?,?)
            ");
            $stmt->execute([
                $username,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
            ]);
            $_SESSION['user_id'] = (int)db()->lastInsertId();
            session_regenerate_id(true);
            flash('success', '注册成功。');
            redirect(basePath('profile.php'));
        } catch (PDOException $e) {
            flash('error', '用户名或邮箱已经存在。');
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="card" style="max-width:620px;margin:auto">
<h1>用户注册</h1>
<form method="post">
<?= csrfField() ?>
<p><label>用户名</label><input name="username" required maxlength="50"></p>
<p><label>邮箱</label><input type="email" name="email" required></p>
<p><label>密码</label><input type="password" name="password" minlength="8" required></p>
<p><label>确认密码</label><input type="password" name="confirm" minlength="8" required></p>
<button type="submit">注册</button>
</form>
</div>
<?php require __DIR__ . '/footer.php'; ?>
