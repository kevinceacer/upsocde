<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

// 兼容旧地址：支付二维码已经集成到用户充值页面。
redirect(basePath('recharge.php'));
