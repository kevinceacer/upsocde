<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// 兼容旧地址：支付宝配置已经合并到系统管理后台。
redirect(basePath('admin/settings.php'));
