<?php
/** 首页仪表盘 */
?>
<h1 class="h3 mb-4"><i class="bi bi-speedometer2"></i> 统计信息</h1>

<div class="row g-3 mb-4">
    <?php
    $cards = [
        ['total', '总素材', 'bi-folder', 'primary'],
        ['done', '已分析', 'bi-check-circle', 'success'],
        ['pending', '待分析', 'bi-hourglass-split', 'warning'],
        ['failed', '失败', 'bi-x-circle', 'danger'],
        ['high', '高光素材', 'bi-stars', 'info'],
    ];
    foreach ($cards as [$key, $label, $icon, $color]): ?>
    <div class="col-6 col-md">
        <div class="card border border-<?= $color ?> h-100">
            <div class="card-body">
                <div class="d-flex">
                <div class="fs-2 text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>

                    <div class="flex-grow-1 ps-3">
                        <div class="small"><?= $label ?></div>
   <div class="fs-3 fw-bold"><?= (int)$stats[$key] ?></div>
                    </div>
                </div>
             
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if ($projects): ?>
<h2 class="h5 mb-3"><i class="bi bi-kanban"></i> 工程概览</h2>
<div class="row g-3 mb-4">
    <?php foreach ($projects as $p): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-kanban"></i> <?= htmlspecialchars($p['name']) ?></h6>
                <p class="small text-body-secondary">
                    <?= (int)$p['counts']['total'] ?> 个素材 · <?= (int)$p['counts']['done'] ?> 已分析
                </p>
                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(base_url('/library?project=' . $p['id'])) ?>">打开素材库</a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="d-flex gap-2">
    <a class="btn btn-primary" href="<?= htmlspecialchars(base_url('/library')) ?>"><i class="bi bi-collection"></i> 进入素材库</a>
    <a class="btn btn-secondary" href="<?= htmlspecialchars(base_url('/projects')) ?>"><i class="bi bi-plus-lg"></i> 新建工程</a>
    <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(base_url('/scan')) ?>"><i class="bi bi-search"></i> 扫描</a>
</div>
