<?php /** 扫描页：浏览器驱动，点按钮即扫 @var array $projects */ ?>
<?php foreach ($projects as $p): $c = project_count_videos((int)$p['id']); ?>
<div class="card mb-3" id="scan-card-<?= (int)$p['id'] ?>">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h5 class="mb-0"><?= htmlspecialchars($p['name']) ?>
                <span class="badge text-bg-secondary"><?= (int)$c['total'] ?> 素材 / <?= (int)$c['done'] ?> 已分析</span>
            </h5>
            <div class="btn-group btn-group-sm">
                <button class="btn btn-primary" onclick="startScan(<?= (int)$p['id'] ?>, false)" id="btn-<?= (int)$p['id'] ?>">
                    <i class="bi bi-play-fill"></i> 开始扫描
                </button>
                <button class="btn btn-outline-warning" onclick="startScan(<?= (int)$p['id'] ?>, true)" id="btnf-<?= (int)$p['id'] ?>">
                    强制重新分析
                </button>
            </div>
        </div>
        <div class="small text-body-secondary mb-2">
            <?php foreach ($p['folders_arr'] as $f): ?><div><i class="bi bi-folder"></i> <?= htmlspecialchars($f) ?></div><?php endforeach; ?>
            <?php if (!$p['folders_arr']): ?><div class="text-warning">未配置文件夹，去「工程」页编辑</div><?php endif; ?>
        </div>
        <div class="progress mb-2" style="height:20px; display:none" id="barwrap-<?= (int)$p['id'] ?>">
            <div class="progress-bar progress-bar-striped" id="bar-<?= (int)$p['id'] ?>" style="width:0%"></div>
        </div>
        <pre class="bg-body p-2 rounded mb-0 small" style="max-height:200px; overflow-y:auto; display:none" id="log-<?= (int)$p['id'] ?>"></pre>
    </div>
</div>
<?php endforeach; ?>

<?php if (!$projects): ?>
<div class="alert alert-warning">还没有工程，先去<a href="<?= htmlspecialchars(base_url('/projects')) ?>">「工程」页</a>创建。</div>
<?php endif; ?>

<script>
const scanState = {};   // pid => {running, stop, force, processed, total}

async function startScan(pid, force) {
    if (scanState[pid]?.running) return;
    scanState[pid] = {running: true, stop: false, force, processed: 0, total: 0};
    const log = document.getElementById('log-' + pid);
    const bar = document.getElementById('bar-' + pid);
    const barwrap = document.getElementById('barwrap-' + pid);
    const btn = document.getElementById('btn-' + pid);
    barwrap.style.display = ''; log.style.display = '';
    log.textContent = ''; bar.classList.add('progress-bar-animated');
    btn.classList.replace('btn-primary', 'btn-danger');
    btn.innerHTML = '<i class="bi bi-stop-fill"></i> 停止';
    btn.onclick = () => { scanState[pid].stop = true; };

    const fd = new FormData();
    fd.append('project_id', pid);
    while (!scanState[pid].stop) {
        fd.set('force', scanState[pid].force ? '1' : '');
        try {
            const r = await fetch(<?= json_encode(base_url('/api/scan/one')) ?>, {method: 'POST', body: fd});
            const j = await r.json();
            if (j.error) log.textContent += '⚠️ ' + j.error + '\n';
            if (j.finished) {
                log.textContent += scanState[pid].stop ? '⏹ 已手动停止\n' : '🎉 全部完成\n';
                break;
            }
            scanState[pid].total = j.total || scanState[pid].total || 0;
            if (j.ok) {
                scanState[pid].processed++;
                log.textContent += `✅ [${j.file}] q=${j.quality} a=${j.appeal}\n`;
            } else {
                scanState[pid].processed++;
                log.textContent += `❌ [${j.file}] ${j.error}\n`;
            }
            log.scrollTop = log.scrollHeight;
            const done = scanState[pid].processed, total = Math.max(scanState[pid].total, done);
            bar.style.width = total ? (100 * done / total).toFixed(1) + '%' : '0%';
            bar.textContent = `${done} / ${total}（剩 ${j.remaining ?? '?'}）`;
        } catch (e) {
            log.textContent += '⚠️ 网络错误，5秒后重试: ' + e.message + '\n';
            log.scrollTop = log.scrollHeight;
            await new Promise(r => setTimeout(r, 5000));
        }
    }
    bar.classList.remove('progress-bar-animated');
    btn.classList.replace('btn-danger', 'btn-primary');
    btn.innerHTML = '<i class="bi bi-play-fill"></i> 继续扫描';
    btn.onclick = () => startScan(pid, false);
    scanState[pid].running = false;
}
</script>

<div class="card">
    <div class="card-body">
        <p class="text-body-secondary small mb-0">
            <i class="bi bi-info-circle"></i>
            扫描在浏览器打开期间进行（每段视频一次 AI 请求，限流自动指数退避，关标签页=暂停，重开可续）。
            临时帧清理：<code>php cli/scan.php --clean-tmp</code>
        </p>
    </div>
</div>
