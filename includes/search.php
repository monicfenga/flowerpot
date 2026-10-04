<?php
/**
 * Flowerpot - 搜索/筛选查询
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/**
 * 构建并执行视频搜索。$params: q, scene_type, mood, quality_min, appeal_min, sort, page, per_page
 */
function search_videos(array $params): array
{
    $where = [];
    $args = [];

    // 工程隔离
    $projectId = (int)($params['project'] ?? 0);
    if ($projectId > 0) {
        $where[] = 'project_id = ?';
        $args[] = $projectId;
    }

    // 状态筛选（默认排除 pending：只看已分析 done）
    $status = trim($params['status'] ?? 'done');
    if ($status !== '' && $status !== 'all') {
        if ($status === 'analyzed') {          // 已分析 = done + failed（已处理过）
            $where[] = "status != 'pending' AND status != 'analyzing'";
        } else {
            $where[] = 'status = ?';
            $args[] = $status;
        }
    }

    // 关键词搜索：LIKE（FTS5 中文分词不可用，见 SPEC）
    $q = trim($params['q'] ?? '');
    if ($q !== '') {
        $where[] = "(filename LIKE ? OR description LIKE ? OR tags LIKE ? OR mood LIKE ?)";
        $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
        array_push($args, $like, $like, $like, $like);
    }

    // 受控词表筛选
    foreach (['scene_type' => 'scene_type = ?', 'mood' => 'mood = ?'] as $key => $clause) {
        $v = trim($params[$key] ?? '');
        if ($v !== '' && $v !== '全部') {
            $where[] = $clause;
            $args[] = $v;
        }
    }

    // 评分下限
    foreach (['quality_min' => 'quality >= ?', 'appeal_min' => 'appeal >= ?'] as $key => $clause) {
        $v = $params[$key] ?? '';
        if ($v !== '' && is_numeric($v)) {
            $where[] = $clause;
            $args[] = (int)$v;
        }
    }

    // 只看高光素材：质量和吸引力均达阈值（config.php highlight_min）
    if (!empty($params['highlight'])) {
        $h = fp_highlight_min();
        $where[] = "(quality >= {$h} AND appeal >= {$h})";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // 排序（白名单，防注入）
    $sortMap = [
        'appeal' => 'appeal DESC, quality DESC',
        'quality' => 'quality DESC, appeal DESC',
        'duration' => 'duration DESC',
        'filename' => 'filename ASC',
        'newest' => 'created_at DESC',
    ];
    $orderSql = $sortMap[$params['sort'] ?? 'appeal'] ?? $sortMap['appeal'];

    // 分页
    $perPage = min(max((int)($params['per_page'] ?? 24), 6), 96);
    $page = max((int)($params['page'] ?? 1), 1);
    $offset = ($page - 1) * $perPage;

    $pdo = db();
    $countStmt = $pdo->prepare("SELECT COUNT(*) AS c FROM videos {$whereSql}");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT * FROM videos {$whereSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
    $stmt->execute($args);
    $videos = $stmt->fetchAll();

    return [
        'videos' => $videos,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(ceil($total / $perPage), 1),
        'params' => $params,
    ];
}

function video_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM videos WHERE id = ?');
    $stmt->execute([$id]);
    $v = $stmt->fetch();
    return $v ?: null;
}
