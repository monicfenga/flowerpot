<?php
/**
 * Flowerpot - 单视频处理流水线（CLI 与 Web 扫描共用）
 * 元数据 → 抽帧 → AI 分析 → 缩略图 → 入库
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/**
 * 处理单个视频（含完整 AI 分析）。
 * 返回:
 *   ok=true  => ['ok'=>true, 'analysis'=>array, 'thumbRel'=>?string]
 *   ok=false => ['ok'=>false, 'error'=>string]
 */
function scan_process_one_video(array $file, int $videoId): array
{
    db()->prepare("UPDATE videos SET status = 'analyzing' WHERE id = ?")->execute([$videoId]);

    // 1. 元数据
    $meta = probe_video($file['path']);
    if ($meta === null) {
        video_mark_failed($videoId, 'ffprobe 解析失败');
        return ['ok' => false, 'error' => 'ffprobe 解析失败'];
    }
    db()->prepare('UPDATE videos SET duration=?, width=?, height=?, codec=?, bitrate=?, fps=? WHERE id=?')
        ->execute([$meta['duration'], $meta['width'], $meta['height'], $meta['codec'], $meta['bitrate'], $meta['fps'], $videoId]);

    // 2. 抽帧
    $frameRelPaths = extract_frames($file['path'], $videoId, $meta['duration']);
    $frameAbsPaths = array_map(
        fn($rel) => $rel === null ? null : dirname(__DIR__) . '/' . $rel,
        $frameRelPaths
    );
    if (count(array_filter($frameAbsPaths)) === 0) {
        video_mark_failed($videoId, 'ffmpeg 抽帧失败');
        return ['ok' => false, 'error' => 'ffmpeg 抽帧失败'];
    }

    // 3. AI 分析（节流/指数退避在 ai_client 内部处理）
    $analysis = zhipu_analyze_frames($frameAbsPaths);
    if ($analysis === null) {
        if (ai_last_error_retryable()) {
            // 限流/网络类临时失败：标回 pending，下轮增量扫描自动重试（不永久占用 failed）
            db()->prepare("UPDATE videos SET status = 'pending', error_message = '智谱限流/网络临时失败，待自动重试' WHERE id = ?")
                ->execute([$videoId]);
            return ['ok' => false, 'error' => '智谱限流，已标回 pending 待自动重试'];
        }
        video_mark_failed($videoId, 'AI 分析失败（超时/限流/解析错误）');
        return ['ok' => false, 'error' => 'AI 分析失败（超时/限流/解析错误）'];
    }

    // 4. 缩略图（frame_2 移入 thumbnails）
    $thumbRel = promote_thumbnail($videoId, $frameRelPaths[1] ?? null);

    // 5. 写入分析结果（含来源标签并入）
    video_update_analysis($videoId, $analysis, $thumbRel, $frameRelPaths);

    return ['ok' => true, 'analysis' => $analysis, 'thumbRel' => $thumbRel];
}

/**
 * 收集工程下所有视频并挑出下一个待处理的。
 * 返回 ['total'=>N, 'pending'=>N, 'next'=>['file'=>array,'videoId'=>int]|null]
 * pending 判定：status!=done，或 mtime 变了；--force 时全部算 pending。
 */
function scan_pick_next(array $project, bool $force): array
{
    $files = [];
    foreach ($project['folders_arr'] as $folder) {
        try {
            $files = array_merge($files, scan_folder($folder));
        } catch (RuntimeException $e) {
            // 目录不存在/不可读：跳过（Web 界面看不到错误，忽略即可）
        }
    }

    $pending = 0;
    $next = null;
    foreach ($files as $file) {
        $videoId = video_upsert($file, (int)$project['id']);
        $row = db()->prepare('SELECT status, file_mtime FROM videos WHERE id = ?');
        $row->execute([$videoId]);
        $row = $row->fetch();
        $isPending = $force || $row['status'] !== 'done' || (int)$row['file_mtime'] !== (int)$file['file_mtime'];
        if (!$isPending) continue;
        $pending++;
        if ($next === null) $next = ['file' => $file, 'videoId' => $videoId];
    }

    return ['total' => count($files), 'pending' => $pending, 'next' => $next];
}
