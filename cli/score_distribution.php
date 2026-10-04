<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
flowerpot_config();

echo "=== 质量分分布 ===\n";
foreach (db()->query("SELECT quality, COUNT(*) c FROM videos WHERE status='done' GROUP BY quality ORDER BY quality")->fetchAll() as $r) {
    echo str_pad((string)$r['quality'], 4) . str_repeat('█', (int)$r['c']) . " ({$r['c']})\n";
}
echo "\n=== 吸引力分布 ===\n";
foreach (db()->query("SELECT appeal, COUNT(*) c FROM videos WHERE status='done' GROUP BY appeal ORDER BY appeal")->fetchAll() as $r) {
    echo str_pad((string)$r['appeal'], 4) . str_repeat('█', (int)$r['c']) . " ({$r['c']})\n";
}
$avg = db()->query("SELECT AVG(quality) q, AVG(appeal) a, COUNT(*) c FROM videos WHERE status='done'")->fetch();
echo "\n平均: quality=" . round($avg['q'], 2) . " appeal=" . round($avg['a'], 2) . " (共{$avg['c']}个)\n";
