<?php
/**
 * Flowerpot - SQLite 数据库操作（含工程隔离）
 */

if (!defined('FLOWERPOT')) die('direct access denied');

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $config = flowerpot_config();
        $dbPath = $config['database']['path'];
        $dir = dirname($dbPath);
        if (!is_dir($dir)) mkdir($dir, 0777, true);

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
    }
    return $pdo;
}

/** 安全加列（表已存在时） */
function db_ensure_column(string $table, string $column, string $ddl): void
{
    $cols = db()->query("PRAGMA table_info({$table})")->fetchAll();
    foreach ($cols as $c) {
        if ($c['name'] === $column) return;
    }
    db()->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$ddl}");
}

function db_init_tables(): void
{
    $pdo = db();

    // 工程
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            folders TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS videos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            path TEXT NOT NULL,
            filename TEXT,
            extension TEXT,
            filesize INTEGER,
            file_mtime INTEGER,
            source TEXT,
            duration REAL,
            width INTEGER,
            height INTEGER,
            codec TEXT,
            bitrate INTEGER,
            fps REAL,
            thumbnail_path TEXT,
            frame_1_path TEXT,
            frame_2_path TEXT,
            frame_3_path TEXT,
            tags TEXT,
            quality INTEGER,
            appeal INTEGER,
            description TEXT,
            scene_type TEXT,
            mood TEXT,
            status TEXT DEFAULT 'pending',
            error_message TEXT,
            analyzed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // 旧库迁移：补列（必须在建索引之前）
    db_ensure_column('videos', 'project_id', 'INTEGER REFERENCES projects(id) ON DELETE CASCADE');
    db_ensure_column('videos', 'source', 'TEXT');

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_status ON videos(status)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_quality ON videos(quality)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_appeal ON videos(appeal)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_scene_type ON videos(scene_type)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_mtime ON videos(file_mtime)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_videos_project ON videos(project_id)");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scripts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            name TEXT NOT NULL,
            source_text TEXT,
            audio_path TEXT,
            duration REAL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS script_lines (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            script_id INTEGER NOT NULL,
            line_number INTEGER NOT NULL,
            start_time REAL,
            end_time REAL,
            text TEXT NOT NULL,
            line_type TEXT DEFAULT 'unknown',
            keywords TEXT,
            matched_video_id INTEGER,
            match_score REAL,
            ai_suggestion TEXT,
            source_in REAL,
            source_out REAL,
            user_action TEXT,
            notes TEXT,
            FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE CASCADE,
            FOREIGN KEY (matched_video_id) REFERENCES videos(id) ON DELETE SET NULL
        )
    ");

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_script_lines_script ON script_lines(script_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_script_lines_type ON script_lines(line_type)");
    db_ensure_column('scripts', 'project_id', 'INTEGER REFERENCES projects(id) ON DELETE CASCADE');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS scan_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,
            folder_path TEXT NOT NULL,
            status TEXT DEFAULT 'pending',
            total_files INTEGER DEFAULT 0,
            processed_files INTEGER DEFAULT 0,
            failed_files INTEGER DEFAULT 0,
            skipped_files INTEGER DEFAULT 0,
            worker_id TEXT,
            retry_count INTEGER DEFAULT 0,
            max_retries INTEGER DEFAULT 3,
            started_at DATETIME,
            completed_at DATETIME,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )
    ");
    db_ensure_column('scan_jobs', 'project_id', 'INTEGER REFERENCES projects(id) ON DELETE CASCADE');
}

/* ---------- 工程 ---------- */

function project_create(string $name, array $folders): int
{
    db()->prepare('INSERT INTO projects (name, folders) VALUES (?, ?)')
        ->execute([trim($name), json_encode(array_values(array_filter(array_map('trim', $folders))), JSON_UNESCAPED_UNICODE)]);
    return (int)db()->lastInsertId();
}

function project_all(): array
{
    $rows = db()->query('SELECT * FROM projects ORDER BY id')->fetchAll();
    foreach ($rows as &$r) $r['folders_arr'] = json_decode($r['folders'] ?? '[]', true) ?: [];
    return $rows;
}

function project_get(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if ($p) $p['folders_arr'] = json_decode($p['folders'] ?? '[]', true) ?: [];
    return $p ?: null;
}

function project_update_folders(int $id, array $folders): void
{
    db()->prepare("UPDATE projects SET folders = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([json_encode(array_values(array_filter(array_map('trim', $folders))), JSON_UNESCAPED_UNICODE), $id]);
}

function project_count_videos(int $id): array
{
    $stmt = db()->prepare("SELECT COUNT(*) c, SUM(CASE WHEN status='done' THEN 1 ELSE 0 END) d FROM videos WHERE project_id = ?");
    $stmt->execute([$id]);
    $r = $stmt->fetch();
    return ['total' => (int)($r['c'] ?? 0), 'done' => (int)($r['d'] ?? 0)];
}

/* ---------- 视频 ---------- */

/**
 * 入库/取ID。同一路径在不同工程中允许各存一条记录。
 */
function video_upsert(array $data, int $projectId): int
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id, file_mtime FROM videos WHERE path = ? AND project_id = ?');
    $stmt->execute([$data['path'], $projectId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $pdo->prepare('UPDATE videos SET file_mtime = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$data['file_mtime'], $existing['id']]);
        return (int)$existing['id'];
    }

    $pdo->prepare("
        INSERT INTO videos (project_id, path, filename, extension, filesize, file_mtime, source)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $projectId, $data['path'], $data['filename'], $data['extension'],
        $data['filesize'], $data['file_mtime'],
        infer_source($data['path']),
    ]);

    return (int)$pdo->lastInsertId();
}

/**
 * 从路径推断素材来源：按 config 的 source_keywords 匹配（不区分大小写）。
 * 手机/稳定器/运动相机/航拍/AE输出等，关键词表可在 config.php 自定义。
 */
function infer_source(string $path): ?string
{
    $lower = strtolower($path);
    foreach (flowerpot_config()['source_keywords'] ?? [] as $kw => $label) {
        if ($kw !== '' && str_contains($lower, strtolower($kw))) return $label;
    }
    return null;
}

/** 手动设置素材来源（详情弹窗下拉），并同步增删 tags 里的来源标签 */
function video_set_source(int $id, string $source): bool
{
    $allowed = array_unique(array_merge([''], array_values(flowerpot_config()['source_keywords'] ?? [])));
    if (!in_array($source, $allowed, true)) return false;

    $stmt = db()->prepare('SELECT source, tags FROM videos WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return false;

    $tags = json_decode($row['tags'] ?? '[]', true) ?: [];
    // 移除旧来源标签
    if ($row['source']) $tags = array_values(array_diff($tags, [$row['source']]));
    // 加入新来源标签
    if ($source !== '' && !in_array($source, $tags, true)) $tags[] = $source;

    db()->prepare('UPDATE videos SET source = ?, tags = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
        ->execute([$source ?: null, json_encode($tags, JSON_UNESCAPED_UNICODE), $id]);
    return true;
}

function video_update_analysis(int $id, array $analysis, string $thumbPath, array $framePaths): void
{
    $pdo = db();

    // 来源标签并入 tags（AI 标签 + 自动来源标签）
    $tags = $analysis['tags'] ?? [];
    $row = $pdo->prepare('SELECT source FROM videos WHERE id = ?');
    $row->execute([$id]);
    $source = $row->fetch()['source'] ?? null;
    if ($source && !in_array($source, $tags, true)) $tags[] = $source;

    $pdo->prepare("
        UPDATE videos SET
            thumbnail_path = ?, frame_1_path = ?, frame_2_path = ?, frame_3_path = ?,
            tags = ?, quality = ?, appeal = ?, description = ?, scene_type = ?, mood = ?,
            status = 'done', analyzed_at = CURRENT_TIMESTAMP,
            updated_at = CURRENT_TIMESTAMP, error_message = NULL
        WHERE id = ?
    ")->execute([
        $thumbPath, $framePaths[0], $framePaths[1], $framePaths[2],
        json_encode($tags, JSON_UNESCAPED_UNICODE),
        $analysis['quality'] ?? null, $analysis['appeal'] ?? null,
        $analysis['description'] ?? null, $analysis['scene_type'] ?? null,
        $analysis['mood'] ?? null, $id,
    ]);
}

/** 删除单个素材：缩略图+临时帧文件+DB行。离线盘上的视频文件本身不动 */
function video_delete(int $id): bool
{
    $stmt = db()->prepare('SELECT thumbnail_path, frame_1_path, frame_2_path, frame_3_path FROM videos WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) return false;

    $root = dirname(__DIR__);
    foreach (['thumbnail_path', 'frame_1_path', 'frame_2_path', 'frame_3_path'] as $field) {
        if ($row[$field] && is_file($root . '/' . $row[$field])) @unlink($root . '/' . $row[$field]);
    }
    db()->prepare('DELETE FROM videos WHERE id = ?')->execute([$id]);
    return true;
}

/** 删除整个工程及其所有素材（含缩略图/帧文件）和文稿 */
function project_delete(int $id): bool
{
    $rows = db()->prepare('SELECT id FROM videos WHERE project_id = ?');
    $rows->execute([$id]);
    foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $vid) video_delete((int)$vid);
    db()->prepare('DELETE FROM script_lines WHERE script_id IN (SELECT id FROM scripts WHERE project_id = ?)')->execute([$id]);
    db()->prepare('DELETE FROM scripts WHERE project_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
    return true;
}

function video_mark_failed(int $id, string $error): void
{
    db()->prepare("UPDATE videos SET status = 'failed', error_message = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$error, $id]);
}
