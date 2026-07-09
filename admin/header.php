<?php
require_once dirname(__DIR__) . '/lib.php';
installTables();
$admin = requireAdmin();
$siteName = setting('site_name', 'UPS 单号查询');
$flashes = pullFlashes();
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理后台</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f4f6f8;color:#18212f;font-family:Arial,"Microsoft YaHei",sans-serif}a{color:#155eef;text-decoration:none}
.top{background:#111827;color:#fff}.topin{max-width:1450px;margin:auto;padding:14px 18px;display:flex;gap:16px;align-items:center;flex-wrap:wrap}.top a{color:#fff}.nav{display:flex;gap:13px;margin-left:auto;flex-wrap:wrap}
.wrap{max-width:1450px;margin:24px auto;padding:0 15px}.card{background:#fff;border:1px solid #e3e8ef;border-radius:12px;padding:20px;margin-bottom:18px}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
input,select,textarea,button{width:100%;min-height:42px;padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px}textarea{min-height:170px}label{display:block;margin:0 0 6px;color:#475467}
button,.btn{display:inline-flex;width:auto;align-items:center;justify-content:center;background:#111827;color:#fff;border:0;border-radius:8px;padding:9px 14px;cursor:pointer}.btn.light{background:#fff;color:#111827;border:1px solid #cbd5e1}
.table{overflow:auto;border:1px solid #e5e7eb;border-radius:10px}table{border-collapse:collapse;width:100%;min-width:950px}th,td{padding:11px 12px;border-bottom:1px solid #e5e7eb;text-align:left;white-space:nowrap;font-size:14px}th{background:#f8fafc}.flash{padding:12px;margin-bottom:12px;border-radius:8px}.success{background:#ecfdf3;color:#067647}.error{background:#fef3f2;color:#b42318}.info{background:#eff8ff;color:#175cd3}.stat{font-size:27px;font-weight:700}.muted{color:#667085}.actions{display:flex;gap:8px;flex-wrap:wrap}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:550px){.grid{grid-template-columns:1fr}}
</style></head><body>
<header class="top"><div class="topin"><strong><?= h($siteName) ?> 后台</strong><nav class="nav">
<a href="<?= h(basePath('admin/index.php')) ?>">概览</a>
<a href="<?= h(basePath('admin/settings.php')) ?>">系统/支付宝</a>
<a href="<?= h(basePath('admin/cards.php')) ?>">卡密</a>
<a href="<?= h(basePath('admin/users.php')) ?>">用户</a>
<a href="<?= h(basePath('admin/orders.php')) ?>">订单</a>
<a href="<?= h(basePath('index.php')) ?>">返回前台</a>
</nav></div></header><main class="wrap">
<?php foreach($flashes as $f): ?><div class="flash <?= h($f['type']) ?>"><?= h($f['message']) ?></div><?php endforeach; ?>
