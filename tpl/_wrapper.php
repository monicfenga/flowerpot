<!DOCTYPE html>
<html lang="zh-CN" data-bs-theme="dark">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($this_title ?? 'Flowerpot') ?> · 花盆</title>
    <!-- Bootstrap 5.3 -->
    <link href="<?= htmlspecialchars(flowerpot_config()['cdn_base'] ?? 'https://cdn.jsdelivr.net/npm') ?>/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="<?= htmlspecialchars(flowerpot_config()['cdn_base'] ?? 'https://cdn.jsdelivr.net/npm') ?>/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <!-- HTMX -->
    <script src="<?= htmlspecialchars(flowerpot_config()['cdn_base'] ?? 'https://cdn.jsdelivr.net/npm') ?>/htmx.org@2.0.4/dist/htmx.min.js"></script>
    <style>
        /* ── 花盆主题：荧光绿 × 近黑 · 移动App风（参考 tiffinbox）── */
        :root {
            --fp-accent: #C1FF72;
            /* 主色 lime（tiffinbox同款） */
            --fp-accent-hi: #d9ff9e;
            /* hover 再亮一档 */
            --fp-accent-deep: #10b981;
            /* 辅助：渐变深端/进度条尾部 */
            --fp-bg: rgb(10 10 12);
            --fp-card: rgb(24 24 27 / .6);
            --fp-border: rgb(39 39 42);
            --fp-text-hi: rgb(255 255 255);
            --fp-text: rgb(209 213 219);
            --fp-radius: 1.1rem;
            /* 大圆角 */
        }

        body {
            background: var(--fp-bg);
            color: var(--fp-text);
            font-size: .95rem;
            letter-spacing: .01em;
        }

        /* 页面主标题：超大号粗体（App风） */
        h1.h3 {
            font-size: 1.7rem;
            font-weight: 800;
            color: var(--fp-text-hi);
            letter-spacing: -.01em;
        }

        h5,
        .card-title {
            font-weight: 700;
            color: var(--fp-text-hi);
        }

        /* ── 主菜单：自绘顶栏，非 Bootstrap 结构 ── */
        .fp-topbar {
            position: sticky;
            top: 0;
            z-index: 1030;
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.65rem 1rem 0 1rem;
/*             padding: .65rem clamp(1rem, 4vw, 2rem);
            background: rgb(10 10 12 / .78);
            backdrop-filter: blur(14px) saturate(1.2); 
            border-bottom: 1px solid rgb(255 255 255 / .05);
            */
            margin-bottom: 1.5rem;
        }

        .fp-brand {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-weight: 800;
            font-size: 1.05rem;
            color: var(--fp-text-hi);
            text-decoration: none;
            letter-spacing: -.01em;
            background: rgba(0,0,0, 0.75);
            border-radius: 999px;
            padding: .25rem 0.5rem;
        }

        .fp-brand i {
            color: var(--fp-accent);
            font-size: 1.2rem;
        }

        .fp-brand small {
            color: var(--fp-text);
            opacity: .55;
            font-weight: 500;
        }

        .fp-nav {
            display: flex;
            align-items: center;
            gap: .15rem;
            margin-left: auto;
            background: rgba(0,0,0, 0.75);
            border: 1px solid rgba(255, 255, 255 , .06);
            border-radius: 999px;
            padding: .25rem;
        }

        .fp-nav a {
            display: flex;
            align-items: center;
            gap: .35rem;
            color: var(--fp-text);
            text-decoration: none;
            font-weight: 600;
            font-size: .88rem;
            padding: .3rem .85rem;
            border-radius: 999px;
            transition: color .15s ease, background .15s ease;
            white-space: nowrap;
        }

        .fp-nav a i {
            font-size: .95rem;
            opacity: .75;
        }

        .fp-nav a:hover {
            color: var(--fp-text-hi);
            background: rgb(255 255 255 / .05);
        }

        .fp-nav a[aria-current="page"] {
            color: #000;
            background: var(--fp-accent);
        }

        .fp-nav a[aria-current="page"] i {
            opacity: 1;
        }

        /* 窄屏：图标优先，文字收缩 */
        @media (max-width: 720px) {
            .fp-nav a span.fp-nav-label {
                display: none;
            }

            .fp-nav a {
                padding: .3rem .6rem;
            }
        }

        /* 卡片：大圆角、无硬边框、柔和阴影、宽松内边距 */
        .card,
        .modal-content {
            background: var(--fp-card);
            border: 1px solid rgb(39 39 42 / .6);
            border-radius: var(--fp-radius);
            box-shadow: 0 8px 28px rgb(0 0 0 / .45);
            backdrop-filter: blur(8px);
        }

        .card-body {
            padding: 1.1rem;
        }

        /* 输入框/按钮：胶囊化 */
        .form-control,
        .form-select,
        pre {
            background: rgb(10 10 12 / .65);
            color: var(--fp-text);
            border: 1px solid var(--fp-border);
            border-radius: .8rem;
        }

        .btn {
            border-radius: 999px;
            font-weight: 700;
            padding-inline: 1rem;
        }

        .btn-sm {
            padding-inline: .7rem;
        }

        /* 主色统一走 Bootstrap 的 primary 变量集（模板里一律用 btn-primary/btn-outline-primary） */
        :root {
            --bs-primary: #10b981;
            --bs-primary-rgb: 16, 185, 129;
        }

        .btn-primary {
            --bs-btn-color: #000;
            --bs-btn-bg: var(--fp-accent);
            background: var(--fp-accent);
            border: none;
            --bs-btn-hover-color: #000;
            --bs-btn-hover-bg: var(--fp-accent-hi);
            --bs-btn-hover-border-color: var(--fp-accent-hi);
            --bs-btn-focus-shadow-rgb: 193, 255, 114;
            --bs-btn-active-color: #000;
            --bs-btn-active-bg: var(--fp-accent-hi);
            --bs-btn-active-border-color: var(--fp-accent-hi);
            --bs-btn-disabled-color: #000;
            --bs-btn-disabled-bg: var(--fp-accent);
            --bs-btn-disabled-border-color: var(--fp-accent);
            --bs-gradient: none;
        }

        .btn-outline-primary {
            --bs-btn-color: var(--fp-accent);
            --bs-btn-border-color: var(--fp-accent);
            --bs-btn-hover-color: #000;
            --bs-btn-hover-bg: var(--fp-accent);
            --bs-btn-hover-border-color: var(--fp-accent);
            --bs-btn-focus-shadow-rgb: 193, 255, 114;
            --bs-btn-active-color: #000;
            --bs-btn-active-bg: var(--fp-accent);
            --bs-btn-active-border-color: var(--fp-accent);
            --bs-btn-disabled-color: var(--fp-accent);
            --bs-btn-disabled-bg: transparent;
            --bs-btn-disabled-border-color: var(--fp-accent);
            --bs-gradient: none;
        }

        .text-primary {
            color: var(--fp-accent) !important;
        }

        .bg-primary {
            background-color: var(--fp-accent) !important;
        }

        .badge {
            border-radius: 999px;
            font-weight: 600;
        }

        a {
            color: var(--fp-accent);
        }

        a:hover {
            color: var(--fp-accent-hi);
        }

        .progress {
            background: rgb(39 39 42);
            border-radius: 999px;
        }

        .progress-bar {
            background: linear-gradient(90deg, var(--fp-accent-deep), var(--fp-accent));
            border-radius: 999px;
        }

        .video-card img.thumb {
            width: 100%;
            aspect-ratio: 16/9;
            object-fit: cover;
            background: #1a1a1c;
        }

        /* 高光素材：金色描边+角标 */
        .card.highlight {
            border: 1.5px solid rgb(255 193 7 / .8);
            box-shadow: 0 0 16px rgb(255 193 7 / .2);
        }

        .card.highlight::before {
            content: "★ 高光";
            position: absolute;
            top: .6rem;
            left: .6rem;
            z-index: 2;
            background: rgb(255 193 7 / .95);
            color: #000;
            font-size: .7rem;
            font-weight: 800;
            padding: .12rem .55rem;
            border-radius: 999px;
        }

        .video-card {
            position: relative;
        }

        .score-badge {
            font-size: .75rem;
        }

        .no-thumb {
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: #555;
            border-radius: var(--fp-radius) var(--fp-radius) 0 0;
        }

        /* 分页：走主题变量，去 Bootstrap 默认蓝 */
        .pagination {
            --bs-pagination-color: var(--fp-text);
            --bs-pagination-bg: rgb(10 10 12 / .65);
            --bs-pagination-border-width: 1px;
            --bs-pagination-border-color: var(--fp-border);
            --bs-pagination-border-radius: 999px;
            --bs-pagination-hover-color: var(--fp-text-hi);
            --bs-pagination-hover-bg: rgb(255 255 255 / .06);
            --bs-pagination-hover-border-color: var(--fp-border);
            --bs-pagination-focus-color: var(--fp-text-hi);
            --bs-pagination-focus-bg: rgb(255 255 255 / .06);
            --bs-pagination-focus-box-shadow: 0 0 0 .2rem rgb(193 255 114 / .25);
            --bs-pagination-active-color: #000;
            --bs-pagination-active-bg: var(--fp-accent);
            --bs-pagination-active-border-color: var(--fp-accent);
            --bs-pagination-disabled-color: var(--fp-text);
            --bs-pagination-disabled-opacity: .4;
            --bs-pagination-disabled-bg: transparent;
            --bs-pagination-disabled-border-color: var(--fp-border);
            gap: .35rem;
        }

        .pagination .page-link {
            border-radius: 999px;
            font-weight: 600;
        }

        /* 自带 gap，抵消 Bootstrap 的负 margin 拼接 */
        .pagination .page-item + .page-item .page-link {
            margin-left: 0;
        }

        tr[x-cloak],
        .x-cloak {
            display: none;
        }
    </style>
</head>
<body>
<?php
// 主菜单：自绘顶栏（active 态按当前路径判定，不依赖 Bootstrap 组件）
$fp_path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$fp_items = [
    ['/', '', 'bi-house', '首页'],
    ['/projects', 'projects', 'bi-kanban', '工程'],
    ['/library', 'library', 'bi-collection', '素材库'],
    ['/classify', 'classify', 'bi-file-text', '文稿分类'],
    ['/scan', 'scan', 'bi-search', '扫描'],
];
?>
<header class="fp-topbar">
    <a class="fp-brand" href="<?= htmlspecialchars(base_url('/')) ?>">
        <i class="bi bi-flower1"></i> 花盆 <small class="d-none d-md-inline">Flowerpot</small>
    </a>
    <nav class="fp-nav">
        <?php foreach ($fp_items as [$href, $seg, $icon, $label]):
            $active = $seg === '' ? $fp_path === '' : str_starts_with($fp_path, trim($seg, '/'));
        ?>
            <a href="<?= htmlspecialchars(base_url($href)) ?>"<?= $active ? ' aria-current="page"' : '' ?>>
                <i class="bi <?= $icon ?>"></i><span class="fp-nav-label"><?= $label ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
</header>
<?php
// 环境自检横幅：异常时顶部黄条提示（结果缓存10分钟，不阻埼页面渲染）
$health = fp_health_check(ping: true);
if (!$health['ok']):
    $hint = fp_health_hint($health);
?>
    <div class="container-fluid mt-3">
        <div class="alert alert-warning d-flex align-items-center gap-2 mb-0" role="alert">
            <i class="bi bi-exclamation-triangle fs-5"></i>
            <div class="flex-grow-1">
                <strong>环境自检发现问题</strong> —— <?= $hint ?>
                <span class="text-body-secondary small d-block">修复后运行 <code>php cli/doctor.php</code> 复验</span>
            </div>
        </div>
    </div>
<?php endif; ?>


<main class="container-fluid pb-5">
    <?= $content ?>
</main>

<footer class="text-center text-muted py-4 small">
    花生在土里，你的素材种在花盆里 · &copy; 2026 Geticer 为您倾力打造
</footer>

<!-- Alpine.js（局部交互） -->
<script defer src="<?= htmlspecialchars(flowerpot_config()['cdn_base'] ?? 'https://cdn.jsdelivr.net/npm') ?>/alpinejs@3.14.3/dist/cdn.min.js"></script>
<!-- Bootstrap JS（弹窗等） -->
<script src="<?= htmlspecialchars(flowerpot_config()['cdn_base'] ?? 'https://cdn.jsdelivr.net/npm') ?>/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>

</html>