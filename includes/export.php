<?php
/**
 * Flowerpot - 导出功能
 * CSV / EDL / FCPXML 导出
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/** CSV 导出（真下载） */
function export_script_csv(int $scriptId): void
{
    $s = script_get($scriptId);
    if (!$s) { http_response_code(404); exit('script not found'); }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="script_' . $scriptId . '.csv"');
    $fp = fopen('php://output', 'w');
    fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM，Excel 中文不乱码
    fputcsv($fp, ['行号', '原文', '类型', '关键词', 'AI建议画面', '匹配素材文件名', '匹配素材路径'], ',', '"', '\\');

    foreach ($s['lines'] as $line) {
        // 与网页展示一致：实时匹配 top3（matched_video_id 字段当前无人写入，不作为依据）
        $keywords = json_decode($line['keywords'] ?? '[]', true) ?: [];
        $matches = match_videos_for_line((int)$s['project_id'], array_merge([$line['text']], $keywords), 3);
        fputcsv($fp, [
            $line['line_number'],
            $line['text'],
            $line['line_type'],
            implode(' ', $keywords),
            $line['ai_suggestion'],
            implode(' | ', array_map(fn($m) => $m['video']['filename'], $matches)),
            implode(' | ', array_map(fn($m) => $m['video']['path'], $matches)),
        ], ',', '"', '\\');
    }
    fclose($fp);
    exit;
}
