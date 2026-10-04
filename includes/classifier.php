<?php
/**
 * Flowerpot - 文稿分类器（绑定工程，单次请求整篇拆分）
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/**
 * 整篇文稿一次性发给 GLM-4.7-Flash 拆句+分类。
 * 成功返回 script_id；AI 解析失败返回 null（不中断，调用方自行标记）。
 */
function classify_text(int $projectId, string $name, string $text): ?int
{
    $prompt = <<<PROMPT
你是一个专题片剪辑助手。用户将提供一段配音文稿，你需要：
1. 按「适合一句配一个画面」的原则拆分成句子列表
2. 对每句话判断类型

类型定义（三选一，受控词表）：
1. material（有素材型）：描述具体的、可拍摄的实体或场景
2. data（数据型）：包含具体数字、增长数据、统计信息
3. abstract（抽象型）：宏大的、抽象的、无法用具体画面表现的词

输出格式（严格JSON数组，不要包含其他文字）：
[{"line_number": 1, "text": "句子原文", "type": "material", "keywords": ["关键词1", "关键词2"], "visual_suggestion": "建议画面", "abstract_category": ""},
 {"line_number": 2, "text": "句子原文", "type": "data", "keywords": ["关键词"], "visual_suggestion": "建议画面", "abstract_category": ""}]

注意：abstract_category 仅当 type 为 abstract 时填（如"宏大/希望/速度/文化/科技"），其他类型填空字符串。

输入文稿：
{$text}
PROMPT;

    // 文本模型，给足重试；不指定单模型，让 fallback 链生效（4.7 撞429自动切备用）
    $raw = zhipu_chat([['role' => 'user', 'content' => $prompt]], null, 6);
    $lines = $raw === null ? null : parse_ai_json($raw);
    if (!is_array($lines) || !isset($lines[0]['text'])) {
        return null;
    }

    // 入库
    $pdo = db();
    $pdo->prepare('INSERT INTO scripts (project_id, name, source_text) VALUES (?, ?, ?)')
        ->execute([$projectId, $name ?: '未命名文稿 ' . date('m-d H:i'), $text]);
    $scriptId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('INSERT INTO script_lines (script_id, line_number, text, line_type, keywords, ai_suggestion) VALUES (?, ?, ?, ?, ?, ?)');
    $n = 0;
    foreach ($lines as $line) {
        $type = in_array($line['type'] ?? '', ['material', 'data', 'abstract'], true) ? $line['type'] : 'unknown';
        $n++;
        $stmt->execute([
            $scriptId, $n,
            trim((string)($line['text'] ?? '')),
            $type,
            json_encode(array_slice((array)($line['keywords'] ?? []), 0, 6), JSON_UNESCAPED_UNICODE),
            trim((string)($line['visual_suggestion'] ?? '')),
        ]);
    }
    if ($n === 0) {
        $pdo->prepare('DELETE FROM scripts WHERE id = ?')->execute([$scriptId]);
        return null;
    }
    return $scriptId;
}

/** 保存用户手动修改的行类型 */
function script_line_update_type(int $lineId, string $type): bool
{
    if (!in_array($type, ['material', 'data', 'abstract', 'unknown'], true)) return false;
    db()->prepare('UPDATE script_lines SET line_type = ? WHERE id = ?')->execute([$type, $lineId]);
    return true;
}

/**
 * 按关键词在【当前工程】素材库中匹配素材（LIKE，MVP 简单匹配）。
 * 返回 top N：['video' => row, 'score' => int]
 */
function match_videos_for_line(int $projectId, array $keywords, int $limit = 3): array
{
    $keywords = array_values(array_filter($keywords, fn($k) => trim($k) !== ''));
    if (!$keywords) return [];

    $scoreParts = [];
    $likeArgs = [];
    foreach (array_slice($keywords, 0, 5) as $k) {
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $k) . '%';
        $scoreParts[] = "(CASE WHEN tags LIKE ? THEN 2 ELSE 0 END + CASE WHEN description LIKE ? THEN 2 ELSE 0 END + CASE WHEN scene_type LIKE ? THEN 1 ELSE 0 END + CASE WHEN mood LIKE ? THEN 1 ELSE 0 END)";
        array_push($likeArgs, $like, $like, $like, $like);
    }
    $scoreSql = implode(' + ', $scoreParts);

    // 注意：scoreSql 的占位符在 SELECT 和 WHERE 各出现一次，需按 SQL 顺序绑定两遍
    $args = array_merge($likeArgs, [$projectId], $likeArgs);

    $stmt = db()->prepare("SELECT v.*, ({$scoreSql}) AS match_score FROM videos v WHERE project_id = ? AND status = 'done' AND ({$scoreSql}) > 0 ORDER BY match_score DESC, appeal DESC LIMIT {$limit}");
    $stmt->execute($args);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $score = (int)$row['match_score'];
        unset($row['match_score']);
        $out[] = ['video' => $row, 'score' => $score];
    }
    return $out;
}

/** 取脚本（含行） */
function script_get(int $scriptId): ?array
{
    $stmt = db()->prepare('SELECT * FROM scripts WHERE id = ?');
    $stmt->execute([$scriptId]);
    $s = $stmt->fetch();
    if (!$s) return null;
    $stmt = db()->prepare('SELECT * FROM script_lines WHERE script_id = ? ORDER BY line_number');
    $stmt->execute([$scriptId]);
    $s['lines'] = $stmt->fetchAll();
    return $s;
}

function scripts_for_project(int $projectId): array
{
    $stmt = db()->prepare('SELECT * FROM scripts WHERE project_id = ? ORDER BY id DESC');
    $stmt->execute([$projectId]);
    return $stmt->fetchAll();
}

