<?php
/**
 * CLI 自检：php cli/doctor.php          → 本地检查 + AI 连通
 *           php cli/doctor.php --no-ping → 只做本地检查（不调 API）
 */
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/ai_client.php';
require dirname(__DIR__) . '/includes/health_check.php';

flowerpot_config();

$ping = !in_array('--no-ping', $argv, true);
echo "花盆 · 环境自检\n================\n";

$health = fp_health_check($ping, true);
foreach ($health['checks'] as $c) {
    echo ($c['ok'] ? '  ✓ ' : '  ✗ ') . str_pad($c['name'], 18) . $c['msg'] . PHP_EOL;
}

// 附加：数据库与关键目录可写性（不参与 overall 结论，仅提示）
$dbFile = flowerpot_config()['database']['path'];
$dbOk = is_file($dbFile) ? is_writable($dbFile) : is_writable(dirname($dbFile));
echo ($dbOk ? '  ✓ ' : '  ✗ ') . str_pad('数据库', 18) . $dbFile . (!$dbOk ? '（不存在或不可写）' : '') . PHP_EOL;

foreach (['thumbnails', 'frames_tmp', 'exports'] as $k) {
    $dir = flowerpot_config()['paths'][$k . '_folder'] ?? '';
    $ok = is_dir($dir) && is_writable($dir);
    echo ($ok ? '  ✓ ' : '  ✗ ') . str_pad($k, 18) . $dir . (!$ok ? '（不存在或不可写）' : '') . PHP_EOL;
}

echo "\n" . ($health['ok'] ? '结论：一切正常 ✓' : '结论：存在问题 ✗（见上方 ✗ 项）') . PHP_EOL;
exit($health['ok'] ? 0 : 1);
