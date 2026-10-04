<?php
/** 单个视频卡片 */
function view_video_card(array $v): string
{
    $id = (int)$v['id'];
    $thumb = $v['thumbnail_path'] ? base_url('/' . $v['thumbnail_path']) : null;
    $tags = json_decode($v['tags'] ?? '[]', true) ?: [];
    $h = fp_highlight_min(); $isHighlight = ($v['quality'] ?? 0) >= $h && ($v['appeal'] ?? 0) >= $h;
    ob_start(); ?>
    <div class="col-6 col-md-4 col-lg-3 col-xl-2">
        <div class="card h-100 video-card<?= $isHighlight ? ' highlight' : '' ?>">
            <?php if ($thumb): ?>
            <img class="thumb card-img-top" src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($v['description'] ?? $v['filename']) ?>" loading="lazy">
            <?php else: ?>
            <div class="thumb card-img-top no-thumb"><i class="bi bi-film fs-1"></i></div>
            <?php endif; ?>
            <div class="card-body py-2 px-2">
                <div class="d-flex justify-content-between small mb-1">
                    <span class="badge bg-warning bg-opacity-25 score-badge" bs-trigger="tooltip" title="质量"><i class="bi bi-star"></i> <?= $v['quality'] ?? '-' ?></span>
                    <span class="badge bg-danger bg-opacity-25 score-badge" bs-trigger="tooltip" title="吸引力"><i class="bi bi-heart"></i> <?= $v['appeal'] ?? '-' ?></span>
                    <span class="text-body-secondary" bs-trigger="tooltip" title="时长"><i class="bi bi-clock"></i> <?= gmdate('i:s', (int)round($v['duration'] ?? 0)) ?></span>
                </div>
                <div class="small text-truncate" title="<?= htmlspecialchars($v['filename']) ?>"><?= htmlspecialchars($v['filename']) ?></div>
                <div class="small text-body-secondary text-truncate"><?= htmlspecialchars($v['description'] ?? ($v['status'] ?? '')) ?></div>
                <div class="mt-1">
                    <?php foreach (array_slice($tags, 0, 3) as $t): ?>
                    <span class="badge text-bg-secondary score-badge"><?= htmlspecialchars($t) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="card-footer bg-transparent py-1 px-2 text-end">
                <button class="btn btn-sm btn-outline-info py-0" hx-get="<?= htmlspecialchars(base_url('/api/video')) ?>?id=<?= $id ?>"
                        hx-target="#detail-modal-content" data-bs-toggle="modal" data-bs-target="#detail-modal">
                    <i class="bi bi-info-circle"></i> 详情
                </button>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}
