<?php
/**
 * Flowerpot - EasyClaw 素材扫描接口
 *
 * 双模式：CLI（原有用法不变）+ HTTP（EasyClaw 通过 HTTP 调用）
 *
 * HTTP 接口（全部返回 JSON）：
 *   GET  ?action=projects                          列出所有工程（含统计）
 *   GET  ?action=pick&id=1[&limit=5][&force=1]     领取待分析素材（自动抽帧，返回帧图片 URL）
 *   POST action=analyze                            提交分析结果入库
 *   POST action=fail                               标记素材失败（可指定 retryable）
 *   GET  ?action=stats[&id=1]                      查看统计
 *   POST action=clean-tmp                          清理已完成视频的临时帧
 *
 * 认证：请求需携带 token 参数（与 config.php 中 easyclaw.token 一致）
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require dirname(__DIR__) . '/includes/database.php';
require dirname(__DIR__) . '/includes/scanner.php';
require dirname(__DIR__) . '/includes/scan_process.php';

db_init_tables();

$isCli = (PHP_SAPI === 'cli');

if ($isCli) {
    // ===== CLI 模式（保留原有行为） =====
    require dirname(__DIR__) . '/includes/ai_client.php';
    run_cli();
} else {
    // ===== HTTP 模式（EasyClaw 调用） =====
    run_http();
}

/* ================================================================
 *  HTTP 模式
 * ================================================================ */

function run_http(): void
{
    header('Content-Type: application/json; charset=utf-8');

    $config = flowerpot_config();
    $token = $config['easyclaw']['token'] ?? '';

    // 认证：token 可通过 GET 参数或 Authorization header 传递
    $provided = $_GET['token'] ?? $_POST['token']
        ?? preg_replace('/^Bearer\s+/i', '', $_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if ($token !== '' && $provided !== $token) {
        http_json(401, ['error' => 'unauthorized', 'message' => 'token 无效']);
        return;
    }

    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'projects':
                handle_projects();
                break;
            case 'pick':
                handle_pick();
                break;
            case 'analyze':
                handle_analyze();
                break;
            case 'fail':
                handle_fail();
                break;
            case 'stats':
                handle_stats();
                break;
            case 'clean-tmp':
                handle_clean_tmp();
                break;
            default:
                http_json(400, ['error' => 'unknown_action', 'message' => "未知 action: {$action}",
                    'available' => ['projects', 'pick', 'analyze', 'fail', 'stats', 'clean-tmp']]);
        }
    } catch (Throwable $e) {
        http_json(500, ['error' => 'internal', 'message' => $e->getMessage()]);
    }
}

/** GET ?action=projects — 列出所有工程 */
function handle_projects(): void
{
    $projects = [];
    foreach (project_all() as $p) {
        $c = project_count_videos($p['id']);
        // 额外统计 pending / failed
        $stmt = db()->prepare("SELECT
            SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) as failed,
            SUM(CASE WHEN status='analyzing' THEN 1 ELSE 0 END) as analyzing
            FROM videos WHERE project_id = ?");
        $stmt->execute([$p['id']]);
        $extra = $stmt->fetch();

        $projects[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'folders' => $p['folders_arr'],
            'total' => (int)$c['total'],
            'done' => (int)$c['done'],
            'pending' => (int)($extra['pending'] ?? 0),
            'failed' => (int)($extra['failed'] ?? 0),
            'analyzing' => (int)($extra['analyzing'] ?? 0),
        ];
    }
    http_json(200, ['projects' => $projects]);
}

/** GET ?action=pick&id=X&limit=N&force=0|1 — 领取待分析素材 */
function handle_pick(): void
{
    $projectId = (int)($_GET['id'] ?? 0);
    $limit = min(20, max(1, (int)($_GET['limit'] ?? 5)));
    $force = !empty($_GET['force']);

    $project = project_get($projectId);
    if (!$project) {
        http_json(404, ['error' => 'project_not_found', 'message' => "工程 {$projectId} 不存在"]);
        return;
    }
    if (!$project['folders_arr']) {
        http_json(400, ['error' => 'no_folders', 'message' => '该工程没有配置扫描文件夹']);
        return;
    }

    // 1. 扫描所有文件夹，收集视频文件
    $files = [];
    foreach ($project['folders_arr'] as $folder) {
        try {
            $files = array_merge($files, scan_folder($folder));
        } catch (RuntimeException $e) {
            // 目录不可读，跳过
        }
    }

    // 2. 筛选 pending 素材
    $pending = [];
    foreach ($files as $file) {
        $videoId = video_upsert($file, $projectId);

        // 检查是否需要处理
        $row = db()->prepare('SELECT status, file_mtime FROM videos WHERE id = ?');
        $row->execute([$videoId]);
        $row = $row->fetch();

        $needProcess = $force
            || $row['status'] !== 'done'
            || (int)$row['file_mtime'] !== (int)$file['file_mtime'];

        if ($needProcess) {
            $pending[] = ['file' => $file, 'videoId' => $videoId, 'current_status' => $row['status']];
        }
    }

    // 3. 取前 limit 条
    $batch = array_slice($pending, 0, $limit);

    // 4. 对每条：probe 元数据 + 抽帧 + 标记 analyzing
    $items = [];
    foreach ($batch as $item) {
        $file = $item['file'];
        $videoId = $item['videoId'];

        // 标记为 analyzing
        db()->prepare("UPDATE videos SET status = 'analyzing' WHERE id = ?")->execute([$videoId]);

        // probe 元数据
        $meta = probe_video($file['path']);
        if ($meta) {
            db()->prepare('UPDATE videos SET duration=?, width=?, height=?, codec=?, bitrate=?, fps=? WHERE id=?')
                ->execute([$meta['duration'], $meta['width'], $meta['height'], $meta['codec'], $meta['bitrate'], $meta['fps'], $videoId]);
        }

        // 抽帧
        $duration = $meta ? $meta['duration'] : 0;
        $frameRelPaths = extract_frames($file['path'], $videoId, $duration);

        // 构造帧的 URL + 本地绝对路径（EasyClaw 可直接读取本地路径）
        $frameUrls = [];
        $frameFiles = [];
        foreach ($frameRelPaths as $i => $rel) {
            if ($rel === null) {
                $frameUrls[] = null;
                $frameFiles[] = null;
            } else {
                // rel 形如 "data/tmp/frames/123_1.jpg"，转成 /flowerpot/data/tmp/frames/123_1.jpg
                $frameUrls[] = root_url('/' . $rel);
                // 本地绝对路径（Windows 反斜杠）
                $abs = str_replace('/', '\\', FLOWERPOT_ROOT . '/' . $rel);
                $frameFiles[] = $abs;
            }
        }

        $items[] = [
            'video_id' => $videoId,
            'filename' => $file['filename'],
            'path' => $file['path'],
            'filesize' => $file['filesize'],
            'file_mtime' => $file['file_mtime'],
            'duration' => $meta ? $meta['duration'] : null,
            'width' => $meta ? $meta['width'] : null,
            'height' => $meta ? $meta['height'] : null,
            'frames' => $frameUrls,
            'frame_files' => $frameFiles,
            'frame_count' => count(array_filter($frameUrls)),
        ];
    }

    http_json(200, [
        'project_id' => $projectId,
        'project_name' => $project['name'],
        'total_videos' => count($files),
        'total_pending' => count($pending),
        'returned' => count($items),
        'items' => $items,
    ]);
}

/** POST action=analyze — 提交分析结果 */
function handle_analyze(): void
{
    $input = json_post();
    if (!$input) {
        http_json(400, ['error' => 'invalid_json', 'message' => '请求体需为 JSON']);
        return;
    }

    $videoId = (int)($input['video_id'] ?? 0);
    $analysis = $input['analysis'] ?? null;

    if (!$videoId || !$analysis || !is_array($analysis)) {
        http_json(400, ['error' => 'missing_fields', 'message' => '需要 video_id 和 analysis']);
        return;
    }

    // 校验 video 存在
    $stmt = db()->prepare('SELECT id, status FROM videos WHERE id = ?');
    $stmt->execute([$videoId]);
    $row = $stmt->fetch();
    if (!$row) {
        http_json(404, ['error' => 'video_not_found']);
        return;
    }

    // 提取缩略图（从已有帧中选 frame_2）
    $frameStmt = db()->prepare('SELECT frame_1_path, frame_2_path, frame_3_path FROM videos WHERE id = ?');
    // 帧路径在 pick 阶段已写入？不，extract_frames 返回的是临时路径，还没入库。
    // 这里从 tmp 目录找帧文件
    $config = flowerpot_config();
    $tmpDir = $config['paths']['frames_tmp_folder'];
    $framePaths = [];
    $thumbRel = null;
    $firstFrameRel = null;
    for ($i = 1; $i <= 3; $i++) {
        $f = $tmpDir . '/' . $videoId . '_' . $i . '.jpg';
        if (is_file($f)) {
            $root = str_replace('\\', '/', dirname(__DIR__));
            $rel = str_replace('\\', '/', $f);
            $rel = ltrim(str_replace($root . '/', '', $rel), '/');
            $framePaths[] = $rel;
            if ($firstFrameRel === null) {
                $firstFrameRel = $rel; // 兜底缩略图来源（frame_2 可能缺失）
            }
            if ($i === 2) {
                // 把 frame_2 提升为缩略图
                $thumbRel = promote_thumbnail($videoId, $rel);
            }
        } else {
            $framePaths[] = null;
        }
    }

    if ($thumbRel === null) {
        $thumbRel = $firstFrameRel; // frame_2 缺失时退回首帧
    }

    // 写入分析结果
    video_update_analysis($videoId, $analysis, $thumbRel, $framePaths);

    http_json(200, [
        'ok' => true,
        'video_id' => $videoId,
        'message' => '分析结果已入库',
    ]);
}

/** POST action=fail — 标记素材失败 */
function handle_fail(): void
{
    $input = json_post();
    if (!$input) {
        http_json(400, ['error' => 'invalid_json', 'message' => '请求体需为 JSON']);
        return;
    }

    $videoId = (int)($input['video_id'] ?? 0);
    $error = $input['error'] ?? '未知错误';
    $retryable = !empty($input['retryable']);

    if (!$videoId) {
        http_json(400, ['error' => 'missing_video_id']);
        return;
    }

    if ($retryable) {
        // 限流/临时失败：标回 pending，下次 pick 还会给我
        db()->prepare("UPDATE videos SET status = 'pending', error_message = ? WHERE id = ?")
            ->execute([$error, $videoId]);
        http_json(200, ['ok' => true, 'video_id' => $videoId, 'status' => 'pending', 'message' => '已标回 pending 待重试']);
    } else {
        video_mark_failed($videoId, $error);
        http_json(200, ['ok' => true, 'video_id' => $videoId, 'status' => 'failed', 'message' => '已标记失败']);
    }
}

/** GET ?action=stats — 查看统计 */
function handle_stats(): void
{
    $only = isset($_GET['id']) ? (int)$_GET['id'] : null;

    $result = ['projects' => []];
    $totalHigh = 0;

    foreach (project_all() as $p) {
        if ($only !== null && $p['id'] !== $only) continue;
        $c = project_count_videos($p['id']);

        $where = "WHERE project_id = " . (int)$p['id'];
        $h = fp_highlight_min(); $high = db()->query("SELECT COUNT(*) c FROM videos {$where} AND quality >= {$h} AND appeal >= {$h}")->fetch()['c'];
        $totalHigh += (int)$high;

        $result['projects'][] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'total' => (int)$c['total'],
            'done' => (int)$c['done'],
            'pending' => (int)$c['total'] - (int)$c['done'],
            'highlights' => (int)$high,
        ];
    }
    $result['total_highlights'] = $totalHigh;

    http_json(200, $result);
}

/** POST action=clean-tmp — 清理临时帧 */
function handle_clean_tmp(): void
{
    $deleted = clean_tmp_frames();
    http_json(200, ['ok' => true, 'deleted' => $deleted, 'message' => "已清理 {$deleted} 个临时帧文件"]);
}

/* ================================================================
 *  CLI 模式（保留原有行为）
 * ================================================================ */

function run_cli(): void
{
    $args = array_slice($argv, 1);

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
        echo "  php cli/scan_easyclaw.php --project=1     扫描工程1（增量）\n";
        echo "  php cli/scan_easyclaw.php --project=1 --force\n";
        echo "  php cli/scan_easyclaw.php --projects      列出工程\n";
        echo "  php cli/scan_easyclaw.php --stats         统计\n";
        echo "  php cli/scan_easyclaw.php --clean-tmp     清理临时帧\n";
        echo "\nHTTP 模式（EasyClaw 调用）:\n";
        echo "  GET  ?action=projects\n";
        echo "  GET  ?action=pick&id=1&limit=5\n";
        echo "  POST action=analyze  {video_id, analysis}\n";
        echo "  POST action=fail     {video_id, error, retryable}\n";
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

    require dirname(__DIR__) . '/includes/ai_client.php';

    $processed = 0; $failed = 0; $skipped = 0;

    foreach ($files as $i => $file) {
        $num = sprintf('[%d/%d]', $i + 1, count($files));
        $videoId = video_upsert($file, $projectId);

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
}

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

/* ================================================================
 *  辅助函数
 * ================================================================ */

function http_json(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 项目根的 URL 前缀（脚本在 cli/ 子目录，需往上跳一层） */
function root_url(string $path = '/'): string
{
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    $root = preg_replace('~/cli$~', '', $scriptDir);
    return $root . $path;
}

function json_post(): ?array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}
