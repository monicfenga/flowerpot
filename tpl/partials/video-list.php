<?php /** @var array $videos $total $page $total_pages $params */ ?>
<div class="d-flex justify-content-between align-items-center mb-2 text-body-secondary small">
    <span>共 <?= (int)$total ?> 个素材</span>
    <span>第 <?= (int)$page ?> / <?= (int)$total_pages ?> 页</span>
</div>

<?php if (!$videos): ?>
<div class="text-center text-body-secondary py-5">
    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
    没有匹配的素材。先去跑 <code>php cli/scan.php</code> 扫描吧。
</div>
<?php return; endif; ?>

<div class="row g-3">
<?php require __DIR__ . '/video-card.php';
foreach ($videos as $v) {
echo view_video_card($v);
} ?>
</div>

<?php if ($total_pages > 1): ?>
<nav class="mt-4">
    <ul class="pagination pagination-sm justify-content-center">
        <?php for ($p = 1; $p <= $total_pages; $p++):
            if ($total_pages > 9 && abs($p - $page) > 3 && $p !== 1 && $p !== $total_pages) continue; ?>
        <li class="page-item <?= $p === $page ? 'active' : '' ?>">
            <button class="page-link" hx-get="<?= htmlspecialchars(base_url('/api/videos')) ?>?<?= http_build_query(array_merge($params, ['page' => $p])) ?>"
                    hx-target="#video-list"><?= $p ?></button>
        </li>
        <?php endfor; ?>
    </ul>
</nav>
<?php endif; ?>
