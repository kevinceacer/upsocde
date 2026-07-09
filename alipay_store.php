<?php
declare(strict_types=1);

/**
 * 旧版兼容层。
 *
 * 新版已统一使用现有 settings、recharge_orders、users 和 balance_logs 表，
 * 不再创建独立的 alipay_config / alipay_orders 表。
 */
require_once __DIR__ . '/lib.php';

function alipayDb(): PDO
{
    return db();
}

function alipayConfigGet(string $key, string $default = ''): string
{
    return setting($key, $default);
}

function alipayConfigSet(string $key, string $value): void
{
    setSetting($key, $value);
}

function alipaySecretGet(string $key, string $default = ''): string
{
    $value = secretSetting($key);
    return $value === '' ? $default : $value;
}

function alipaySecretExists(string $key): bool
{
    return secretSetting($key) !== '';
}

function alipaySecretSet(string $key, string $value): void
{
    setSecretSetting($key, $value);
}

function alipayConfigDelete(string $key): void
{
    setSetting($key, '');
}
