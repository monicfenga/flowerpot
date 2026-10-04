<?php /** 工程管理页 @var array $projects @var ?array $edit */ ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-kanban"></i> 工程</h1>
</div>

<div class="row g-4">
    <!-- 工程列表 -->
    <div class="col-lg-7">
        <?php if (!$projects): ?>
        <div class="text-center text-body-secondary py-5">
            <i class="bi bi-kanban fs-1 d-block mb-2"></i>
            还没有工程。在右侧创建第一个吧。
        </div>
        <?php endif; ?>

        <?php foreach ($projects as $p): ?>
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between">
                    <h5 class="mb-1"><?= htmlspecialchars($p['name']) ?></h5>
                    <div class="btn-group btn-group-sm">
                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(base_url('/projects?edit=' . $p['id'])) ?>"><i class="bi bi-pencil"></i></a>
                        <a class="btn btn-outline-primary" href="<?= htmlspecialchars(base_url('/library?project=' . $p['id'])) ?>"><i class="bi bi-collection"></i> 素材库</a>
                        <button class="btn btn-outline-danger" onclick="deleteProject(<?= (int)$p['id'] ?>, '<?= htmlspecialchars(addslashes($p['name'])) ?>', <?= (int)$p['counts']['total'] ?>)"><i class="bi bi-trash"></i></button>
                    </div>
                </div>
                <?php foreach ($p['folders_arr'] as $f): ?>
                <div class="small text-body-secondary"><i class="bi bi-folder"></i> <?= htmlspecialchars($f) ?></div>
                <?php endforeach; ?>
                <div class="mt-2 small">
                    <span class="badge text-bg-secondary"><?= (int)$p['counts']['total'] ?> 个素材</span>
                    <span class="badge text-bg-success"><?= (int)$p['counts']['done'] ?> 已分析</span>
                </div>
                <div class="mt-2 small">
                    <span class="text-body-secondary">扫描入口在「扫描素材」页</span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- 新建/编辑表单 -->
    <div class="col-lg-5">
        <form method="post" action="<?= htmlspecialchars(base_url('/projects')) ?>" class="card">
            <div class="card-body">
                <h5 class="card-title mb-3">
                    <?= $edit ? '编辑工程' : '新建工程' ?>
                    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
                </h5>
                <div class="mb-3">
                    <label class="form-label">工程名称</label>
                    <input type="text" class="form-control" name="name" required
                           placeholder="如：2026专题片" value="<?= htmlspecialchars($edit['name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">扫描文件夹 <span class="text-body-secondary small">一行一个</span></label>
                    <textarea class="form-control font-monospace" name="folders" rows="6"
                              placeholder="D:/2026专题片/A机&#10;D:/2026专题片/B机&#10;E:/航拍/2026专题片&#10;D:/2026专题片/AE输出"><?= htmlspecialchars(implode("\n", $edit['folders_arr'] ?? [])) ?></textarea>
                    <div class="form-text">不同摄像机、航拍机、AE工程输出各占一行。路径含「航拍/无人机/drone」会自动打「航拍」来源标签。</div>
                </div>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> 保存</button>
                    <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= htmlspecialchars(base_url('/projects')) ?>">取消</a><?php endif; ?>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
async function deleteProject(pid, name, count) {
    if (!confirm(`确定删除工程「${name}」？\n其下 ${count} 个素材的记录、缩略图、帧文件将一并删除（视频源文件不动），不可恢复。`)) return;
    const fd = new FormData(); fd.append('id', pid);
    const r = await fetch(<?= json_encode(base_url('/api/project/delete')) ?>, {method: 'POST', body: fd});
    const j = await r.json();
    if (j.ok) location.reload(); else alert('删除失败');
}
</script>
