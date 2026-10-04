<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
flowerpot_config();
foreach (db()->query("SELECT COUNT(*) c, substr(path,1,3) p FROM videos WHERE project_id=2 GROUP BY substr(path,1,3)") as $r) {
    echo $r['p'], ' => ', $r['c'], PHP_EOL;
}
echo "--- 盘上实际扫描根前缀（工程folders_arr）---\n";
$p = project_get(2);
foreach ($p['folders_arr'] as $f) echo substr($f, 0, 3), ' ', $f, PHP_EOL;
