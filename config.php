<?php
declare(strict_types=1);

return [
    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'ups',
        'username' => 'ups',
        'password' => 'ups',
        'charset'  => 'utf8mb4',
    ],

    // 必须长期保持不变，用于加密后台填写的支付宝私钥。
    'app_secret' => 'b77426eaa26ca44b5e9a97220eea4dc3b656a2f3bd6bd9c1cbea757d95f8ef51',

    // 留空则不验证，兼容现有 Python 入库脚本。
    'api_key' => '',

    'session_name' => 'UPS_MEMBER_SESSION',
    'timezone' => 'Asia/Shanghai',
];
