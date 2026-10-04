<?php

/** 素材库页面 */ ?>
<div class="d-flex align-items-center mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-collection"></i> 素材库 - </h1>
    <label for="project" class="visually-hidden">工程</label>
    <select class="form-select fs-3" name="project" style="width:fit-content">
        <option value="">全部</option>
        <?php foreach ($projects as $p): ?>
            <option value="<?= (int)$p['id'] ?>" <?= (int)($params['project'] ?? $current_project) === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
        <?php endforeach; ?>
    </select>
</div>

<!-- 筛选栏：HTMX 联动 -->
<form id="filter-form" class="card bg-body-tertiary mb-4" hx-get="<?= htmlspecialchars(base_url('/api/videos')) ?>" hx-target="#video-list" hx-trigger="input delay:400ms from:form, change from:form, submit" hx-indicator="#load-indicator">
    <div class="card-body row g-2 align-items-center">

        <div class="col-md-4">
            <label for="q">搜索</label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="search" class="form-control" name="q" placeholder="搜索文件名/描述/标签…" value="<?= htmlspecialchars($params['q'] ?? '') ?>">
            </div>
        </div>
        <div class="col-md-2">
            <label for="status">状态</label>
            <select class="form-select" name="status">
                <?php foreach (['done' => '已分析', 'analyzed' => '已处理(已分析+失败)', 'pending' => '待分析', 'analyzing' => '分析中', 'failed' => '失败', 'all' => '全部'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= ($params['status'] ?? 'done') === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="scene_type">场景</label>
            <select class="form-select" name="scene_type">
                <option value="">全部</option>
                <?php foreach (['人物活动', '风景', '产品特写', '会议', '室内', '抽象', '其他'] as $st): ?>
                    <option <?= ($params['scene_type'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="mood">情绪</label>
            <select class="form-select" name="mood">
                <option value="">全部</option>
                <?php foreach (['欢快', '严肃', '紧张', '温馨', '宏大', '悲伤', '中性'] as $m): ?>
                    <option <?= ($params['mood'] ?? '') === $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="sort">排序</label>
            <select class="form-select" name="sort">
                <?php foreach (['appeal' => '吸引力', 'quality' => '质量', 'duration' => '时长', 'filename' => '文件名', 'newest' => '最新'] as $k => $label): ?>
                    <option value="<?= $k ?>" <?= ($params['sort'] ?? 'appeal') === $k ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label for="">质量大于等于</label>
            <input type="number" class="form-control" name="quality_min" min="0" max="10" placeholder="质量≥" value="<?= htmlspecialchars($params['quality_min'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label for="">吸引大于等于</label>
            <input type="number" class="form-control" name="appeal_min" min="0" max="10" placeholder="吸引≥" value="<?= htmlspecialchars($params['appeal_min'] ?? '') ?>">
        </div>

        <div class="col-auto">
            <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" role="switch" id="highlight-switch" name="highlight" value="1" <?= !empty($params['highlight']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="highlight-switch" title="质量和吸引力 ≥ <?= fp_highlight_min() ?> 的高分素材">只看高光</label>
            </div>
        </div>
        <div class="col-auto ms-auto">
            <span id="load-indicator" class="htmx-indicator"><i class="bi bi-arrow-repeat spin"></i></span>
            <button type="reset" class="btn btn-outline-secondary" onclick="setTimeout(()=>htmx.trigger('#filter-form','submit'),50)">重置</button>
        </div>
    </div>
</form>

<div id="video-list" hx-get="<?= htmlspecialchars(base_url('/api/videos')) ?>" hx-trigger="load" hx-swap="innerHTML">
    <div class="text-center text-body-secondary py-5"><i class="bi bi-arrow-repeat"></i> 加载中…</div>
</div>

<!-- 详情弹窗 -->
<div class="modal fade" id="detail-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" id="detail-modal-content"></div>
    </div>
</div>

<style>
    .spin {
        display: inline-block;
        animation: spin 1s linear infinite
    }

    @keyframes spin {
        to {
            transform: rotate(360deg)
        }
    }
</style>