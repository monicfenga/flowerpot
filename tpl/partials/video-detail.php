<?php /** @var ?array $video 视频详情 */ ?>
<?php if (!$video): ?>
<div class="modal-header"><h5 class="modal-title">未找到</h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body">素材不存在。</div>
<?php return; endif;

$thumb = $video['thumbnail_path'] ? base_url('/' . $video['thumbnail_path']) : null;
$tags = json_decode($video['tags'] ?? '[]', true) ?: [];
?>
<div class="modal-header">
    <h5 class="modal-title text-truncate"><i class="bi bi-film"></i> <?= htmlspecialchars($video['filename']) ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <?php if ($thumb): ?>
    <img class="img-fluid rounded mb-3" src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($video['description'] ?? '') ?>">
    <?php endif; ?>
    <table class="table table-sm">
        <tr><th style="width:6em">描述</th><td><?= htmlspecialchars($video['description'] ?? '-') ?></td></tr>
        <tr><th>质量/吸引</th><td><?= $video['quality'] ?? '-' ?> / <?= $video['appeal'] ?? '-' ?></td></tr>
        <tr><th>场景/情绪</th><td><?= htmlspecialchars(($video['scene_type'] ?? '-') . ' · ' . ($video['mood'] ?? '-')) ?></td></tr>
        <tr><th>来源</th>
            <td>
                <select class="form-select form-select-sm d-inline-block" style="width:auto" data-vid="<?= (int)$video['id'] ?>"
                        hx-post="<?= htmlspecialchars(base_url('/api/video/source')) ?>"
                        hx-vals='js:{id: this.dataset.vid, source: this.value}'
                        hx-target="#source-save">
                    <option value="">（无）</option>
                    <?php foreach (['航拍','手机','稳定器','运动相机','AE输出'] as $src): ?>
                    <option <?= ($video['source'] ?? '') === $src ? 'selected' : '' ?>><?= $src ?></option>
                    <?php endforeach; ?>
                </select>
                <span id="source-save" class="small"></span>
            </td>
        </tr>
        <tr><th>标签</th><td><?php foreach ($tags as $t): ?><span class="badge text-bg-secondary"><?= htmlspecialchars($t) ?></span> <?php endforeach; ?></td></tr>
        <tr><th>规格</th><td><?= $video['width'] ?>×<?= $video['height'] ?> · <?= gmdate('i:s', (int)round($video['duration'] ?? 0)) ?> · <?= htmlspecialchars($video['codec'] ?? '?') ?></td></tr>
        <tr><th>路径</th><td class="small text-break text-body-secondary"><?= htmlspecialchars($video['path']) ?></td></tr>
        <?php if ($video['error_message']): ?>
        <tr><th>错误</th><td class="small text-danger"><?= htmlspecialchars($video['error_message']) ?></td></tr>
        <?php endif; ?>
    </table>
    <div class="text-end">
        <button class="btn btn-outline-danger btn-sm"
                onclick="if(confirm('删除该素材记录？(缩略图/帧一并删，视频源文件不动)')) deleteVideo(<?= (int)$video['id'] ?>)">
            <i class="bi bi-trash"></i> 删除素材
        </button>
    </div>
</div>
<script>
async function deleteVideo(id) {
    const fd = new FormData(); fd.append('id', id);
    const r = await fetch(<?= json_encode(base_url('/api/video/delete')) ?>, {method: 'POST', body: fd});
    const j = await r.json();
    if (j.ok) { bootstrap.Modal.getInstance(document.getElementById('detail-modal'))?.hide(); setTimeout(() => location.reload(), 300); }
    else alert('删除失败');
}
</script>
