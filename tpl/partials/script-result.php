<?php /** 分类结果 @var array $script */ ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-check"></i> <?= htmlspecialchars($script['name']) ?></span>
        <!-- 传统表单提交 → 真下载 -->
        <form method="post" action="<?= htmlspecialchars(base_url('/api/export/csv')) ?>">
            <input type="hidden" name="script_id" value="<?= (int)$script['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> 导出 CSV</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th style="width:3em">#</th>
                    <th>原文</th>
                    <th style="width:9em">类型</th>
                    <th style="width:22em">AI 建议 / 匹配素材</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($script['lines'] as $line):
                $keywords = json_decode($line['keywords'] ?? '[]', true) ?: [];
                $matches = match_videos_for_line((int)$script['project_id'], array_merge([$line['text']], $keywords));
                ?>
                <tr>
                    <td class="text-body-secondary"><?= (int)$line['line_number'] ?></td>
                    <td class="small"><?= htmlspecialchars($line['text']) ?></td>
                    <td>
                        <select class="form-select form-select-sm" data-line="<?= (int)$line['id'] ?>"
                                hx-post="<?= htmlspecialchars(base_url('/api/line')) ?>"
                                hx-vals='js:{id: this.dataset.line, type: this.value}'
                                hx-target="#line-save-<?= (int)$line['id'] ?>">
                            <?php foreach (['material' => '素材型', 'data' => '数据型', 'abstract' => '抽象型', 'unknown' => '未分类'] as $k => $label): ?>
                            <option value="<?= $k ?>" <?= $line['line_type'] === $k ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span id="line-save-<?= (int)$line['id'] ?>" class="d-inline-block small"></span>
                    </td>
                    <td class="small">
                        <?php if ($line['ai_suggestion']): ?><div class="text-body-secondary mb-1"><i class="bi bi-lightbulb"></i> <?= htmlspecialchars($line['ai_suggestion']) ?></div><?php endif; ?>
                        <?php foreach (array_slice($matches, 0, 3) as $m): $v = $m['video']; ?>
                            <img src="<?= htmlspecialchars($v['thumbnail_path'] ? base_url('/' . $v['thumbnail_path']) : '') ?>"
                                 class="rounded me-1" width="64" height="36" style="object-fit:cover;background:#222"
                                 title="<?= htmlspecialchars($v['filename'] . ' (' . $m['score'] . '分)') ?>" alt="<?= htmlspecialchars($v['filename']) ?>">
                        <?php endforeach; ?>
                        <?php if (!$matches): ?><span class="text-body-secondary">无匹配素材</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
