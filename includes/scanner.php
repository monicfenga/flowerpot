<?php
/**
 * Flowerpot - 素材扫描器
 * 目录遍历 + ffprobe 元数据提取 + ffmpeg 抽帧
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/**
 * Windows 命令行路径包裹。
 * 不要用 escapeshellarg()：Windows 下遇中文/非ASCII字符在某些PHP版本返回空串。
 * 注意：Windows 文件名不允许的非法字符由 scanner 负责在上层过滤。
 */
function win_path(string $path): string
{
    return '"' . str_replace('"', '', str_replace('/', '\\', $path)) . '"';
}

const VIDEO_EXTENSIONS = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'm4v', 'wmv', 'flv', 'ts'];

/** 遍历目录，收集视频文件（绝对路径，统一正斜杠） */
function scan_folder(string $folder): array
{
    $folder = rtrim(str_replace('\\', '/', $folder), '/');
    if (!is_dir($folder)) {
        throw new RuntimeException("目录不存在: {$folder}");
    }

    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $ext = strtolower($file->getExtension());
        if (in_array($ext, VIDEO_EXTENSIONS, true)) {
            $files[] = [
                'path' => str_replace('\\', '/', $file->getPathname()),
                'filename' => $file->getFilename(),
                'extension' => $ext,
                'filesize' => $file->getSize(),
                'file_mtime' => $file->getMTime(),
            ];
        }
    }
    sort($files);
    return $files;
}

/** ffprobe 提取元数据（stderr 捕获进输出以便调试） */
function probe_video(string $path): ?array
{
    $config = flowerpot_config();
    $cmd = $config['ffmpeg']['ffprobe_path']
        . ' -v error -print_format json -show_format -show_streams '
        . win_path($path) . ' 2>&1';

    $output = shell_exec($cmd);
    if ($output === null) return null;

    $json = json_decode($output, true);
    if (!isset($json['format'])) return null;

    $video = null;
    foreach ($json['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video') { $video = $stream; break; }
    }

    return [
        'duration' => (float)($json['format']['duration'] ?? 0),
        'bitrate' => (int)($json['format']['bit_rate'] ?? 0),
        'width' => (int)($video['width'] ?? 0),
        'height' => (int)($video['height'] ?? 0),
        'codec' => $video['codec_name'] ?? null,
        'fps' => fps_to_float($video['avg_frame_rate'] ?? '0/1'),
    ];
}

function fps_to_float(string $rate): float
{
    if (str_contains($rate, '/')) {
        [$num, $den] = array_map('floatval', explode('/', $rate));
        return $den > 0 ? round($num / $den, 3) : 0.0;
    }
    return (float)$rate;
}

/**
 * 抽取3帧到临时目录 data/tmp/frames/{video_id}_{n}.jpg
 * 返回帧的相对路径数组（相对项目根）；失败返回空数组
 */
function extract_frames(string $videoPath, int $videoId, float $duration): array
{
    if ($duration <= 0) return [];

    $config = flowerpot_config();
    $tmpDir = $config['paths']['frames_tmp_folder'];
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);

    $framePaths = [];
    foreach ($config['analysis']['frame_positions'] as $i => $ratio) {
        // 避开超出时长的时间点（比如 ratio * duration 超尾）
        $sec = min($duration * $ratio, max($duration - 0.2, 0));
        $outFile = $tmpDir . '/' . $videoId . '_' . ($i + 1) . '.jpg';

        $cmd = $config['ffmpeg']['ffmpeg_path']
            . ' -y -ss ' . number_format($sec, 3, '.', '')
            . ' -i ' . win_path($videoPath)
            . ' -vframes 1 -q:v ' . $config['analysis']['thumbnail_quality']
            . ' ' . win_path($outFile) . ' 2>&1';

        shell_exec($cmd);
        if (is_file($outFile) && filesize($outFile) > 0) {
            // 存相对路径（相对项目根，统一正斜杠）
            $root = str_replace('\\', '/', dirname(__DIR__));
            $rel = str_replace('\\', '/', $outFile);
            $framePaths[] = ltrim(str_replace($root . '/', '', $rel), '/');
        } else {
            $framePaths[] = null;
        }
    }
    return $framePaths;
}

/** 把 frame_2 移动为缩略图 public/thumbnails/{video_id}.jpg，返回相对 public/ 的路径 */
function promote_thumbnail(int $videoId, ?string $frame2Rel): ?string
{
    if ($frame2Rel === null) return null;
    $config = flowerpot_config();
    $src = __DIR__ . '/..' . '/' . $frame2Rel;
    $thumbDir = $config['paths']['thumbnails_folder'];
    if (!is_dir($thumbDir)) mkdir($thumbDir, 0777, true);

    $dest = $thumbDir . '/' . $videoId . '.jpg';
    if (rename($src, $dest)) {
        return 'public/thumbnails/' . $videoId . '.jpg'; // 相对项目根（URL可直接拼接）
    }
    return null;
}

/**
 * 清理临时帧：只删 status='done' 的视频对应的帧文件（不碰 pending/analyzing）
 * 返回删除的文件数
 */
function clean_tmp_frames(): int
{
    $config = flowerpot_config();
    $stmt = db()->query("
        SELECT id, frame_1_path, frame_2_path, frame_3_path
        FROM videos
        WHERE status = 'done' AND (frame_1_path IS NOT NULL OR frame_2_path IS NOT NULL OR frame_3_path IS NOT NULL)
    ");

    $deleted = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $allNull = true;
        foreach (['frame_1_path', 'frame_2_path', 'frame_3_path'] as $field) {
            $rel = $row[$field];
            if ($rel === null) continue;
            $file = __DIR__ . '/..' . '/' . $rel;
            if (is_file($file) && unlink($file)) $deleted++;
        }
        db()->prepare('UPDATE videos SET frame_1_path = NULL, frame_2_path = NULL, frame_3_path = NULL WHERE id = ?')
            ->execute([$row['id']]);
    }
    return $deleted;
}
