<?php
/**
 * Flowerpot - CLI 素材扫描（按工程）
 *
 * 用法:
 *   php cli/scan.php --project=1            扫描工程1的所有文件夹（增量，跳过已分析）
 *   php cli/scan.php --project=1 --force    强制重新分析
 *   php cli/scan.php --projects             列出所有工程
 *   php cli/scan.php --stats [project_id]   查看统计
 *   php cli/scan.php --clean-tmp            清理已完成视频的临时帧
 */

if (PHP_SAPI !== 'cli') die('cli only');

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/scanner.php';
require dirname(__DIR__) . '/includes/ai_client.php';
require dirname(__DIR__) . '/includes/scan_process.php';

$args = array_slice($argv, 1);
db_init_tables();

// --projects 列表
if (in_array('--projects', $args, true)) {
    foreach (project_all() as $p) {
        $c = project_count_videos($p['id']);
        echo "[{$p['id']}] {$p['name']}  ({$c['total']} 个素材, {$c['done']} 已分析)\n";
        foreach ($p['folders_arr'] as $f) echo "      - {$f}\n";
    }
    exit(0);
}

// --clean-tmp
if (in_array('--clean-tmp', $args, true)) {
    echo "已清理 " . clean_tmp_frames() . " 个临时帧文件\n";
    exit(0);
}

// --project=ID
$projectId = null;
foreach ($args as $arg) {
    if (preg_match('/^--project=(\d+)$/', $arg, $m)) $projectId = (int)$m[1];
}

$force = in_array('--force', $args, true);

// --stats
if (in_array('--stats', $args, true)) {
    $only = null;
    foreach ($args as $arg) if (ctype_digit($arg)) $only = (int)$arg;
    show_stats($only);
    exit(0);
}

if ($projectId === null) {
    echo "用法:\n";
    echo "  php cli/scan.php --project=1     扫描工程1（增量）\n";
    echo "  php cli/scan.php --project=1 --force\n";
    echo "  php cli/scan.php --projects      列出工程\n";
    echo "  php cli/scan.php --stats         统计\n";
    echo "  php cli/scan.php --clean-tmp     清理临时帧\n";
    exit(1);
}

$project = project_get($projectId);
if (!$project) {
    fwrite(STDERR, "错误: 工程 {$projectId} 不存在（用 --projects 查看）\n");
    exit(1);
}

echo "工程: [{$project['id']}] {$project['name']}\n";
if (!$project['folders_arr']) {
    echo "该工程还没有配置扫描文件夹（在 Web 界面「工程」页编辑）\n";
    exit(1);
}

// 收集所有工程文件夹的视频
$files = [];
foreach ($project['folders_arr'] as $folder) {
    try {
        $found = scan_folder($folder);
        echo "  {$folder}: " . count($found) . " 个视频\n";
        $files = array_merge($files, $found);
    } catch (RuntimeException $e) {
        fwrite(STDERR, "  ⚠️ {$folder}: {$e->getMessage()}\n");
    }
}
echo "共 " . count($files) . " 个视频文件\n";
if (!$files) exit(0);

$processed = 0; $failed = 0; $skipped = 0;

foreach ($files as $i => $file) {
    $num = sprintf('[%d/%d]', $i + 1, count($files));

    // 入库/取ID（工程隔离：同路径可存在于不同工程）
    $videoId = video_upsert($file, $projectId);

    // 增量扫描：mtime 未变且已分析完成则立即跳过（不耗配额）
    if (!$force) {
        $row = db()->prepare('SELECT status, file_mtime FROM videos WHERE id = ?');
        $row->execute([$videoId]);
        $row = $row->fetch();
        if ($row['status'] === 'done' && (int)$row['file_mtime'] === (int)$file['file_mtime']) {
            $skipped++;
            echo "{$num} {$file['filename']} ⏭️  已分析，跳过\n";
            continue;
        }
    }

    // 节流（自适应指数间隔，429自动加倍）在 ai_client 内部统一处理，CLI/Web 共享状态
    echo "{$num} {$file['filename']} ... ";

    $result = scan_process_one_video($file, $videoId);
    if (!$result['ok']) {
        $failed++;
        echo "❌ {$result['error']}\n";
        continue;
    }

    $analysis = $result['analysis'];
    $processed++;
    echo "✅ quality={$analysis['quality']} appeal={$analysis['appeal']} {$analysis['scene_type']} — {$analysis['description']}\n";
}

echo "\n=== 完成 ===\n";
echo "分析: {$processed}  失败: {$failed}  跳过: {$skipped}\n";

function show_stats(?int $only = null): void
{
    $where = $only ? "WHERE project_id = {$only}" : '';
    $h = fp_highlight_min(); $high = db()->query("SELECT COUNT(*) c FROM videos {$where}" . ($where ? ' AND' : ' WHERE') . " quality >= {$h} AND appeal >= {$h}")->fetch()['c'];
    echo "高光素材 (quality≥8 且 appeal≥8): {$high}\n\n";

    foreach (project_all() as $p) {
        if ($only !== null && $p['id'] !== $only) continue;
        $c = project_count_videos($p['id']);
        echo "=== [{$p['id']}] {$p['name']} ===\n";
        echo "总素材: {$c['total']}  已分析: {$c['done']}\n";
    }
}
