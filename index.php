<?php
/**
 * Flowerpot - Web 入口 + 微型路由器
 */

require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/database.php';
require __DIR__ . '/includes/search.php';
require __DIR__ . '/includes/ai_client.php';
require __DIR__ . '/includes/classifier.php';
require __DIR__ . '/includes/export.php';
require __DIR__ . '/includes/scanner.php';
require __DIR__ . '/includes/scan_process.php';
require __DIR__ . '/includes/health_check.php';

flowerpot_config();

// PHP 内置服务器：静态文件直接由服务器返回，不走 PHP（Laravel 下用 .htaccess 已同理处理）
if (PHP_SAPI === 'cli-server') {
    $staticPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $baseDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($baseDir !== '' && str_starts_with($staticPath, $baseDir)) {
        $staticPath = substr($staticPath, strlen($baseDir)) ?: '/';
    }
    $file = __DIR__ . ($staticPath === '/' ? '/index.php' : $staticPath);
    if (is_file($file)) {
        return false; // 内置服务器直接返回该文件
    }
    // 缩略图友好 URL：/thumbnails/x.jpg -> public/thumbnails/x.jpg（手动吐文件）
    if (preg_match('#^/thumbnails/(.+)\.(jpg|jpeg|png|webp)$#i', $staticPath, $m)
        && is_file($real = __DIR__ . '/public/thumbnails/' . $m[1] . '.' . strtolower($m[2]))) {
        header('Content-Type: ' . match (strtolower($m[2])) {
            'png' => 'image/png', 'webp' => 'image/webp', default => 'image/jpeg',
        });
        readfile($real);
        exit;
    }
}
db_init_tables();

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($path, $base)) {
    $path = substr($path, strlen($base)) ?: '/';
}
$path = rtrim($path, '/') ?: '/';

// API 路由
if (str_starts_with($path, '/api/')) {
    handle_api(substr($path, 5));
    exit;
}

// 页面路由
$routes = [
    '/' => 'home',
    '/projects' => 'projects',
    '/library' => 'library',
    '/classify' => 'classify',
    '/scan' => 'scan',
];

// 工程创建/更新（POST）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/projects') {
    $name = trim($_POST['name'] ?? '');
    $folders = array_filter(array_map('trim', explode("\n", $_POST['folders'] ?? '')));
    if ($name !== '') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid > 0) {
            project_update_folders($pid, $folders);
        } else {
            $pid = project_create($name, $folders);
        }
        header('Location: ' . base_url('/projects?created=' . $pid));
        exit;
    }
}

// 文稿分类（POST）：整篇一次性发 AI，可能耗时，放宽超时
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $path === '/classify') {
    set_time_limit((int)(flowerpot_config()['app']['max_execution_time'] ?? 300));
    $projectId = (int)($_POST['project_id'] ?? 0);
    $text = trim($_POST['text'] ?? '');
    $name = trim($_POST['name'] ?? '');
    if ($projectId > 0 && $text !== '') {
        $scriptId = classify_text($projectId, $name, $text);
        $q = http_build_query(['project' => $projectId, 'script' => $scriptId ?? 0, $scriptId ? 'ok' : 'err' => 1]);
        header('Location: ' . base_url('/classify?' . $q));
        exit;
    }
    header('Location: ' . base_url('/classify?project=' . $projectId . '&err=empty'));
    exit;
}

$page = $routes[$path] ?? '404';
render_page($page);

/* ---------- API ---------- */

function handle_api(string $action): void
{
    switch ($action) {
        case 'videos':            // GET 列表（筛选+分页），返回 HTML 片段
            render_partial('partials/video-list', search_videos($_GET));
            break;
        case 'video':             // GET 单个详情 modal
            $id = (int)($_GET['id'] ?? 0);
            render_partial('partials/video-detail', ['video' => video_by_id($id)]);
            break;
        case 'video/source':      // POST 手动设置来源（详情弹窗下拉）
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $okv = video_set_source((int)($_POST['id'] ?? 0), trim($_POST['source'] ?? ''));
                echo $okv ? '<span class="badge text-bg-success">已保存</span>' : '<span class="badge text-bg-danger">失败</span>';
            }
            break;
        case 'video/delete':      // POST 删除素材（不碰视频源文件）
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => video_delete((int)($_POST['id'] ?? 0))]);
            }
            break;
        case 'project/delete':    // POST 删除工程及全部素材
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                header('Content-Type: application/json');
                echo json_encode(['ok' => project_delete((int)($_POST['id'] ?? 0))]);
            }
            break;
        case 'scan/one':          // POST 处理工程下一个待分析视频（浏览器 JS 循环驱动）
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                set_time_limit(0);
                header('Content-Type: application/json');
                $pid = (int)($_POST['project_id'] ?? 0);
                $force = !empty($_POST['force']);
                $project = project_get($pid);
                if (!$project) {
                    echo json_encode(['finished' => true, 'error' => "工程 {$pid} 不存在"]);
                    break;
                }
                $pick = scan_pick_next($project, $force);
                if ($pick['next'] === null) {
                    echo json_encode(['finished' => true, 'total' => $pick['total']]);
                    break;
                }
                $f = $pick['next']['file'];
                $result = scan_process_one_video($f, $pick['next']['videoId']);
                echo json_encode([
                    'finished' => false,
                    'file' => $f['filename'],
                    'ok' => $result['ok'],
                    'error' => $result['error'] ?? null,
                    'quality' => $result['analysis']['quality'] ?? null,
                    'appeal' => $result['analysis']['appeal'] ?? null,
                    'total' => $pick['total'],
                    'remaining' => $pick['pending'] - 1,
                ], JSON_UNESCAPED_UNICODE);
            }
            break;
        case 'line':              // POST 修改行类型（HTMX）
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                script_line_update_type((int)($_POST['id'] ?? 0), trim($_POST['type'] ?? ''));
                echo '<span class="badge text-bg-success">已保存</span>';
            }
            break;
        case 'export/csv':        // POST 真下载（传统表单提交，非 HTMX）
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                export_script_csv((int)($_POST['script_id'] ?? 0));
            }
            break;
        default:
            http_response_code(404);
            echo 'not found';
    }
}

/* ---------- 渲染 ---------- */

function render_page(string $page): void
{
    $tplFile = __DIR__ . "/tpl/{$page}.php";
    if (!is_file($tplFile)) {
        http_response_code(404);
        $page = '404';
        $tplFile = __DIR__ . '/tpl/404.php';
    }

    // 页面数据
    $data = match ($page) {
        'home' => home_data(),
        'projects' => projects_data(),
        'library' => ['projects' => project_all(), 'current_project' => (int)($_GET['project'] ?? 0)],
        'classify' => classify_page_data(),
        'scan' => ['projects' => array_map(function ($p) { $p['counts'] = project_count_videos((int)$p['id']); return $p; }, project_all())],
        default => [],
    };
    extract($data, EXTR_SKIP);

    ob_start();
    require $tplFile;
    $content = ob_get_clean();

    require __DIR__ . '/tpl/_wrapper.php';
}

function render_partial(string $partial, array $data): void
{
    extract($data, EXTR_SKIP);
    require __DIR__ . "/tpl/{$partial}.php";
}

function home_data(): array
{
    $stats = ['total' => 0, 'done' => 0, 'pending' => 0, 'failed' => 0, 'high' => 0];
    foreach (db()->query('SELECT status, COUNT(*) c FROM videos GROUP BY status') as $row) {
        $stats[$row['status']] = (int)$row['c'];
        $stats['total'] += (int)$row['c'];
    }
    $stats['high'] = (int)db()->query("SELECT COUNT(*) c FROM videos WHERE quality >= " . fp_highlight_min() . " AND appeal >= " . fp_highlight_min())->fetch()['c'];

    // 各工程概览
    $projects = project_all();
    foreach ($projects as &$p) {
        $p['counts'] = project_count_videos((int)$p['id']);
    }
    return ['stats' => $stats, 'projects' => $projects];
}

function projects_data(): array
{
    $projects = project_all();
    foreach ($projects as &$p) {
        $p['counts'] = project_count_videos((int)$p['id']);
    }
    return ['projects' => $projects, 'edit' => isset($_GET['edit']) ? project_get((int)$_GET['edit']) : null];
}

function classify_page_data(): array
{
    $projectId = (int)($_GET['project'] ?? 0);
    $scriptId = (int)($_GET['script'] ?? 0);
    return [
        'projects' => project_all(),
        'project_id' => $projectId,
        'script' => $scriptId > 0 ? script_get($scriptId) : null,
        'scripts' => $projectId > 0 ? scripts_for_project($projectId) : [],
        'ok' => isset($_GET['ok']),
        'err' => $_GET['err'] ?? null,
    ];
}
