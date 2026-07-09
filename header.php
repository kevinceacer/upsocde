<?php
require_once __DIR__ . '/lib.php';
$user = currentUser();
$siteName = setting('site_name', 'UPS 物流单号');
$flashes = pullFlashes();
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($siteName) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:#18212f;font-family:Arial,"Microsoft YaHei",sans-serif}
a{color:#155eef;text-decoration:none}.top{background:#111827;color:#fff}.topin{max-width:1450px;margin:auto;padding:14px 18px;display:flex;align-items:center;gap:18px}
.brand{font-size:20px;font-weight:700;color:#fff}.nav{display:flex;gap:14px;align-items:center;margin-left:auto;flex-wrap:wrap}.nav a{color:#fff}.balance{background:#273449;padding:7px 11px;border-radius:8px}
.wrap{max-width:1450px;margin:24px auto;padding:0 15px}.card{background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:20px;margin-bottom:18px;box-shadow:0 5px 18px rgba(16,24,40,.04)}
h1,h2,h3{margin-top:0}.grid{display:grid;grid-template-columns:repeat(5,minmax(140px,1fr));gap:12px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:14px}
label{display:block;margin-bottom:6px;color:#475467;font-size:14px}input,select,textarea,button{width:100%;min-height:42px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;font-size:15px;background:#fff}
textarea{min-height:130px;resize:vertical}button,.btn{display:inline-flex;align-items:center;justify-content:center;width:auto;min-height:40px;padding:9px 15px;border:0;border-radius:8px;background:#111827;color:#fff;cursor:pointer}
.btn.light{background:#fff;color:#111827;border:1px solid #cbd5e1}.btn.red{background:#b42318}.btn.green{background:#067647}.actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.flash{padding:12px 14px;border-radius:8px;margin-bottom:12px}.flash.success{background:#ecfdf3;color:#067647}.flash.error{background:#fef3f2;color:#b42318}.flash.info{background:#eff8ff;color:#175cd3}
.table{overflow:auto;border:1px solid #e5e7eb;border-radius:10px}table{width:100%;border-collapse:collapse;min-width:950px}th,td{padding:11px 12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap;font-size:14px}th{background:#f8fafc}
.mask{font-family:monospace;letter-spacing:1px}.full{font-family:monospace;font-weight:700;color:#067647}.muted{color:#667085}.price{font-weight:700;color:#b54708}.stat{font-size:26px;font-weight:700}.pagination{display:flex;gap:7px;justify-content:center;margin-top:16px;flex-wrap:wrap}.pagination a,.pagination span{padding:8px 11px;border:1px solid #d0d5dd;border-radius:7px;background:#fff}.pagination .on{background:#111827;color:#fff}
.codebox{font-family:monospace;background:#f8fafc;padding:14px;border-radius:8px;white-space:pre-wrap;word-break:break-all}
@media(max-width:950px){.grid{grid-template-columns:repeat(2,1fr)}.grid2{grid-template-columns:1fr}}@media(max-width:560px){.grid{grid-template-columns:1fr}.topin{align-items:flex-start}.nav{gap:9px}}
</style>
</head>
<body>
<header class="top"><div class="topin">
<a class="brand" href="<?= h(basePath('/')) ?>"><?= h($siteName) ?></a>
<nav class="nav">
<a href="<?= h(basePath('/ups.php')) ?>">查询</a>
<?php if ($user): ?>
<a href="<?= h(basePath('profile.php')) ?>">个人中心</a>
<a href="<?= h(basePath('recharge.php')) ?>">充值</a>
<span class="balance">余额：¥<?= h(money($user['balance'])) ?></span>
<?php if ($user['role'] === 'admin'): ?><a href="<?= h(basePath('admin/index.php')) ?>">后台</a><?php endif; ?>
<a href="<?= h(basePath('logout.php')) ?>">退出</a>
<?php else: ?>
<a href="<?= h(basePath('login.php')) ?>">登录</a>
<a href="<?= h(basePath('register.php')) ?>">注册</a>
<?php endif; ?>
</nav></div></header>
<main class="wrap">
<?php foreach ($flashes as $flash): ?>
<div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
<?php endforeach; ?>
