<?php
/**
 * Flowerpot - 引导文件：加载配置，定义常量
 */

define('FLOWERPOT', true);
define('FLOWERPOT_ROOT', dirname(__DIR__));

function flowerpot_config(): array
{
    static $config = null;
    if ($config === null) {
        $configFile = FLOWERPOT_ROOT . '/config.php';
        if (!file_exists($configFile)) {
            fwrite(STDERR, "错误: 缺少 config.php。请先复制 config.example.php 为 config.php\n");
            exit(1);
        }
        $config = require $configFile;
        load_env(FLOWERPOT_ROOT . '/.env');
    }
    return $config;
}

/** 解析 .env（去引号、trim、跳过注释） */
function load_env(string $envFile): void
{
    if (!file_exists($envFile)) return;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (!str_contains($line, '=')) continue;

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // 去除引号包裹
        $value = trim($value, "\"'");

        if ($key !== '') putenv($key . '=' . $value);
    }
}

/** 生成 URL（兼容 Laragon 子目录部署 /flowerpot/） */
function base_url(string $path = '/'): string
{
    $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $base . $path;
}

/** 高光素材阈值（config.php highlight_min，全站唯一来源） */
function fp_highlight_min(): int
{
    return (int)(flowerpot_config()['highlight_min'] ?? 8);
}
