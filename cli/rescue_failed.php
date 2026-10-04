<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
flowerpot_config();
db_init_tables();
foreach (db()->query("SELECT status, COUNT(*) c FROM videos GROUP BY status") as $r) {
    echo $r['status'], '=', $r['c'], PHP_EOL;
}
echo "--- failed 明细 ---\n";
foreach (db()->query("SELECT id, filename, substr(error_message,1,60) e FROM videos WHERE status='failed'") as $r) {
    echo $r['id'], ' ', $r['filename'], ' => ', $r['e'], PHP_EOL;
}
// 429/网络类失败 → 标回 pending（存量抢救）
$n = db()->exec("UPDATE videos SET status='pending', error_message='智谱限流临时失败（存量抢救），待自动重试'
                 WHERE status='failed' AND (error_message LIKE '%429%' OR error_message LIKE '%限流%' OR error_message LIKE '%超时%')");
echo "标回 pending 的存量 failed: {$n}\n";
// 永久失败剩余
foreach (db()->query("SELECT id, filename, substr(error_message,1,60) e FROM videos WHERE status='failed'") as $r) {
    echo "永久failed: {$r['id']} {$r['filename']} => {$r['e']}\n";
}
