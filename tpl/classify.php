<?php /** 文稿分类页 @var array $projects int $project_id ?array $script array $scripts */ ?>
<h1 class="h3 mb-4"><i class="bi bi-file-text"></i> 文稿分类</h1>

<?php if ($err === 'empty'): ?>
<div class="alert alert-warning">请选择工程并粘贴文稿。</div>
<?php elseif ($err === '1'): ?>
<div class="alert alert-danger">AI 分类失败（限流/解析错误），请重试。</div>
<?php elseif ($ok): ?>
<div class="alert alert-success">分类完成并已保存。</div>
<?php endif; ?>

<?php if (!$projects): ?>
<div class="alert alert-warning">还没有工程，先去<a href="<?= htmlspecialchars(base_url('/projects')) ?>">「工程」页</a>创建。</div>
<?php return; endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <!-- 工程选择 + 文稿输入 -->
        <form method="post" onsubmit="const b=document.getElementById('classify-submit');b.innerHTML='&lt;i class=&quot;bi bi-arrow-repeat spin&quot;&gt;&lt;/i&gt; AI 拆句中…最长可能等 2 分钟（限流自动退避），请勿关闭页面';" action="<?= htmlspecialchars(base_url('/classify')) ?>" class="card mb-3">
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">工程</label>
                    <select class="form-select" name="project_id" required onchange="location='<?= htmlspecialchars(base_url('/classify?project=')) ?>'+this.value">
                        <?php foreach ($projects as $p): ?>
                        <option value="<?= (int)$p['id'] ?>" <?= $project_id === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">文稿名称 <span class="text-body-secondary small">可选</span></label>
                    <input type="text" class="form-control" name="name" placeholder="如：第一版配音稿">
                </div>
                <div class="mb-3">
                    <label class="form-label">粘贴配音文稿</label>
                    <textarea class="form-control" name="text" rows="10" required placeholder="把整篇配音文稿粘贴到这里…&#10;船大能远航，逐鹿中原。&#10;2026年销售额增长了200%…"></textarea>
                </div>
                <button type="submit" class="btn btn-primary" id="classify-submit"><i class="bi bi-magic"></i> AI 拆句并分类</button>
                <span class="text-body-secondary small ms-2">整篇一次性提交，扫描挂机期间可能需 1~2 分钟</span>
            </div>
        </form>

        <!-- 历史文稿 -->
        <?php if ($scripts): ?>
        <div class="card">
            <div class="card-header"><i class="bi bi-clock-history"></i> 该工程的历史文稿</div>
            <div class="list-group list-group-flush">
                <?php foreach ($scripts as $s): ?>
                <a class="list-group-item list-group-item-action <?= $script && (int)$script['id'] === (int)$s['id'] ? 'active' : '' ?>"
                   href="<?= htmlspecialchars(base_url('/classify?project=' . $project_id . '&script=' . $s['id'])) ?>">
                    <?= htmlspecialchars($s['name']) ?>
                    <small class="d-block text-body-secondary"><?= $s['created_at'] ?></small>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <?php if ($script): include __DIR__ . '/partials/script-result.php'; endif; ?>
    </div>
</div>
