<?php
/**
 * analyze_subset.php — 定向分析指定文件夹/文件的子集（复用主流水线）
 * 用法:
 *   php cli/analyze_subset.php --folder="D:\Videos\示例素材" --limit=40
 *   php cli/analyze_subset.php --folder="..." --files=file1.mp4,file2.mp4
 *   php cli/analyze_subset.php --list="C:\path\list.txt"   (每行一个绝对路径)
 *   php cli/analyze_subset.php --folder="..." --pick=every:3 --limit=30
 * 说明：只分析 status!=done 的；遇到 "database is locked" 自动重试。
 */
if (PHP_SAPI !== 'cli') die('cli only');
require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/scanner.php';
require dirname(__DIR__) . '/includes/ai_client.php';
require dirname(__DIR__) . '/includes/scan_process.php';

$args = array_slice($argv, 1);
function arg($name, $args, $default = null) {
    foreach ($args as $a) if (preg_match('/^--' . $name . '=(.*)$/', $a, $m)) return $m[1];
    return $default;
}
$projectId = (int)(arg('project', $args, 2));
$folder    = arg('folder', $args);
$listFile  = arg('list', $args);
$filesArg  = arg('files', $args);
$limit     = (int)(arg('limit', $args, 0));
$every     = 1;
$pick      = arg('pick', $args);
if ($pick && preg_match('/every:(\d+)/', $pick, $m)) $every = (int)$m[1];
$dry       = in_array('--dry', $args, true);

db_init_tables();

$files = [];
if ($listFile) {
    foreach (file($listFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $p) {
        $p = trim($p);
        if ($p !== '' && is_file($p)) $files[] = ['path' => $p, 'filename' => basename($p)];
    }
} elseif ($folder) {
    $all = scan_folder($folder);            // 递归
    if ($filesArg) {
        $want = array_map('trim', explode(',', $filesArg));
        foreach ($all as $f) if (in_array($f['filename'], $want, true)) $files[] = $f;
    } else {
        // 按修改时间排序，均匀抽样
        usort($all, fn($a, $b) => strcmp($a['filename'], $b['filename']));
        $filtered = [];
        foreach ($all as $i => $f) if ($i % $every === 0) $filtered[] = $f;
        $files = $filtered;
    }
} else {
    fwrite(STDERR, "需要 --folder= 或 --list=\n"); exit(1);
}

if ($limit > 0) $files = array_slice($files, 0, $limit);

echo "候选文件: " . count($files) . "\n";
if ($dry) { foreach ($files as $f) echo "  {$f['filename']}\n"; exit(0); }

$done = 0; $skip = 0; $fail = 0;
foreach ($files as $i => $file) {
    $num = sprintf('[%d/%d]', $i + 1, count($files));
    // upsert with lock retry
    $videoId = null;
    for ($try = 0; $try < 8; $try++) {
        try { $videoId = video_upsert($file, $projectId); break; }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), 'locked') !== false) { usleep(500000); continue; }
            throw $e;
        }
    }
    if (!$videoId) { $fail++; echo "{$num} {$file['filename']} ❌ upsert failed\n"; continue; }

    $row = db()->prepare('SELECT status, file_mtime FROM videos WHERE id = ?');
    $row->execute([$videoId]); $row = $row->fetch();
    if ($row['status'] === 'done' && (int)$row['file_mtime'] === (int)$file['file_mtime']) {
        $skip++; continue;
    }

    echo "{$num} {$file['filename']} ... ";
    $res = null;
    for ($try = 0; $try < 5; $try++) {
        try { $res = scan_process_one_video($file, $videoId); break; }
        catch (PDOException $e) {
            if (strpos($e->getMessage(), 'locked') !== false) { usleep(800000); continue; }
            throw $e;
        }
    }
    if (!$res) { $fail++; echo "❌ exception\n"; continue; }
    if (!$res['ok']) { $fail++; echo "❌ {$res['error']}\n"; continue; }
    $a = $res['analysis']; $done++;
    echo "✅ q={$a['quality']} a={$a['appeal']} [{$a['scene_type']}] {$a['description']}\n";
}
echo "\n=== 完成  分析:{$done} 跳过:{$skip} 失败:{$fail} ===\n";
