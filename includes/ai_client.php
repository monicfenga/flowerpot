<?php
/**
 * Flowerpot - 智谱 AI 客户端
 * 含 429/超时重试、受控超时、JSON 解析容错
 */

if (!defined('FLOWERPOT')) die('direct access denied');

/**
 * AI 请求节流（共享状态）：免费模型配额型限流，CLI 与 Web 共用同一状态文件。
 * - 两次请求之间至少隔 interval 秒；
 * - 撞 429 → interval 翻倍（自适应指数，上限 300s）；连续成功 → ×0.7 衰减（下限 8s）；
 * - 返回实际等待的秒数（供 Web 端改为客户端等待）。
 */
function ai_throttle_state_file(): string
{
    return __DIR__ . '/../data/ai_throttle.json';
}

/** 日志输出：CLI 走 stderr，Web 走 error_log（不污染响应体） */
function ai_log(string $msg): void
{
    if (PHP_SAPI === 'cli') fwrite(STDERR, $msg);
    else error_log($msg);
}

/** 记录最近一次 AI 调用结果（供上层区分「限流失败」和「永久失败」） */
function ai_note_error(?int $httpCode, string $err = ''): void
{
    $GLOBALS['AI_LAST_ERROR'] = $httpCode === null ? null : ['http' => $httpCode, 'err' => $err];
}

/** 最近一次失败是否属于可重试类（429限流 / 网络错误 / 超时）；true=应标回 pending 自动重试 */
function ai_last_error_retryable(): bool
{
    $e = $GLOBALS['AI_LAST_ERROR'] ?? null;
    return $e !== null && ($e['http'] === 429 || $e['http'] === 0);
}


/** AI 节流/退避配置（config.php ai 段） */
function ai_cfg(string $key, float $default): float
{
    return (float)(flowerpot_config()['ai'][$key] ?? $default);
}
function ai_throttle_load(): array
{
    $f = ai_throttle_state_file();
    $st = is_file($f) ? json_decode((string)file_get_contents($f), true) : [];
    return [
        'interval' => max(ai_cfg('interval_min', 8.0), (float)($st['interval'] ?? ai_cfg('interval_min', 10.0))),
        'last' => (float)($st['last'] ?? 0),
    ];
}

function ai_throttle_save(array $st): void
{
    file_put_contents(ai_throttle_state_file(), json_encode($st), LOCK_EX);
}

/** 等待到可以发请求；$maxSleep 限制服务端睡眠秒数（Web 端传 0，改为返回剩余秒数）；记录本次请求时间 */
function ai_throttle_acquire(int $maxSleep = PHP_INT_MAX): int
{
    $st = ai_throttle_load();
    $remain = (int)ceil($st['last'] + $st['interval'] - microtime(true));
    if ($remain > 0) {
        $sleep = min($remain, $maxSleep);
        if ($sleep > 0) sleep($sleep);
        $remain -= $sleep;
    }
    $st['last'] = microtime(true);
    ai_throttle_save($st);
    return max(0, $remain);
}

/** 请求结果反馈：撞限流 → 指数加倍；成功 → 衰减回落 */
function ai_throttle_feedback(bool $rateLimited): void
{
    $st = ai_throttle_load();
    $st['interval'] = $rateLimited
        ? min(ai_cfg('interval_max', 300.0), $st['interval'] * 2)
        : max(ai_cfg('interval_min', 8.0), $st['interval'] * 0.7);
    ai_throttle_save($st);
}

/**
 * 调用智谱 chat/completions，带重试。
 * 成功返回 assistant 消息文本；失败返回 null。
 */
function zhipu_chat(array $messages, ?string $model = null, int $maxRetries = 6): ?string
{
    $config = flowerpot_config();
    $apiKey = $config['zhipu']['api_key'];
    if ($apiKey === '') {
        ai_log("错误: ZHIPU_API_KEY 未配置（检查 .env）\n");
        return null;
    }

    // 模型 fallback 链：显式指定用单模型；否则按 retry 轮换（首选 → 备选）
    $isVision = is_array($messages[0]['content'] ?? null)
        && in_array('image_url', array_column($messages[0]['content'], 'type'), true);
    if ($model !== null) {
        $chain = [$model];
    } else {
        $key = $isVision ? 'vision_models' : 'text_models';
        $chain = $config['zhipu'][$key] ?? [$isVision ? $config['zhipu']['vision_model'] : $config['zhipu']['text_model']];
    }

    $ch = curl_init($config['zhipu']['api_url']);
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,   // 3张图base64请求体较大，连接给足
        CURLOPT_TIMEOUT => 120,         // 视觉模型高峰期可能超过60秒
    ];

    // Laragon 的 php.ini 可能指向失效的 cacert 路径（如换过盘符），自动纠正
    $iniCa = ini_get('openssl.cafile') ?: ini_get('curl.cainfo');
    if ($iniCa && !is_file($iniCa)) {
        $candidates = flowerpot_config()['ai']['ca_bundle_candidates'] ?? [
            __DIR__ . '/../data/cacert.pem',
            'M:/laragon/etc/ssl/cacert.pem',
        ];
        foreach ($candidates as $ca) {
            if (is_file($ca)) { $opts[CURLOPT_CAINFO] = $ca; break; }
        }
    }
    curl_setopt_array($ch, $opts);

    // 跨进程共享节流：CLI 可久睡；Web 请求服务端最长等 web_wait_max 秒（config.php ai 段）
    ai_throttle_acquire(PHP_SAPI === 'cli' ? PHP_INT_MAX : (int)ai_cfg('web_wait_max', 45));

    $response = false;
    $usedModel = null;
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        // 每次尝试轮换模型：首选 429 时自动切到备选
        $curModel = $chain[$retry % count($chain)];
        if ($usedModel !== $curModel) {
            ai_log("    [模型切换] -> {$curModel}\n");
            $usedModel = $curModel;
        }
        $payload = json_encode([
            'model' => $curModel,
            'messages' => $messages,
            'temperature' => 0.3,
        ], JSON_UNESCAPED_UNICODE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        // 超时/网络错误（false 或 httpCode=0）和 429 都要重试
        if ($response === false || $httpCode === 0 || $httpCode === 429) {
            ai_note_error($response === false ? 0 : ($httpCode === 0 ? 0 : 429), $curlErr);
            ai_throttle_feedback(true);   // 撞限流：全局间隔加倍
            // 指数退避（backoff_base 起步，backoff_max 封顶，config.php ai 段）+ 抖动
            $delay = min(ai_cfg('backoff_max', 90), ai_cfg('backoff_base', 5) * (2 ** $retry)) + rand(0, 5);
            ai_log("    [重试 " . ($retry + 1) . "/{$maxRetries}] http={$httpCode} 等待{$delay}s {$curlErr}\n");
            sleep($delay);
            continue;
        }
        ai_throttle_feedback(false);  // 成功：全局间隔逐渐回落
        ai_note_error(null);
        break;
    }
    curl_close($ch);

    if ($response === false || $response === null) return null;

    $result = json_decode($response, true);
    if (!isset($result['choices'][0]['message']['content'])) {
        return null;
    }
    return $result['choices'][0]['message']['content'];
}

/**
 * 分析视频的3帧图片。
 * $frameAbsPaths 为绝对路径数组（可含 null，null 的帧跳过）。
 * 成功返回解析后的 JSON 数组；失败返回 null。
 */
function zhipu_analyze_frames(array $frameAbsPaths, ?string $model = null): ?array
{
    $content = [
        ['type' => 'text', 'text' => get_analysis_prompt(count(array_filter($frameAbsPaths)))],
    ];

    foreach (array_values(array_filter($frameAbsPaths)) as $i => $imgPath) {
        $data = base64_encode(file_get_contents($imgPath));
        $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,' . $data]];
    }

    $text = zhipu_chat([['role' => 'user', 'content' => $content]], $model);
    if ($text === null) return null;

    return parse_ai_json($text);
}

/** 从 AI 输出中提取 JSON（容忍 markdown 代码块包裹） */
function parse_ai_json(string $text): ?array
{
    // 去掉可能的 ```json ... ``` 包裹
    if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) {
        $text = $m[1];
    }
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    // 兜底：找第一个 { 到最后一个 }
    $start = strpos($text, '{');
    $end = strrpos($text, '}');
    if ($start !== false && $end !== false && $end > $start) {
        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);
        if (is_array($decoded)) return $decoded;
    }
    return null;
}

function get_analysis_prompt(int $frameCount): string
{
    return <<<PROMPT
你是一个专业的视频素材分析助手。你将收到一个视频的{$frameCount}张截图。
图片顺序：图1=开头(10%位置)，图2=中间(50%位置)，图3=结尾(90%位置)。
请综合分析这些截图，输出JSON格式的结果。

评判标准（严格拉计分，禁止默认给中间偏高分数）：

quality 评分参照：
- 9-10：电影级，稳定、构图讲究、光线完美
- 7-8：能直接用的好素材
- 5-6：勉强能用，有轻微瑕疵
- 3-4：抖动、模糊、曝光问题明显
- 0-2：废片，完全不能用

appeal 评分参照：
- 9-10：一眼抓人，有情绪、有动作、有冲突
- 7-8：内容有意思，值得看
- 5-6：常规内容，可用但不突出
- 3-4：平淡，没有看点
- 0-2：空镜、发呆、无意义画面

扣分触发规则：
- 画面模糊、抖动、过暗、过曝、构图失衡、主体不明确 → 相应扣分
- 画面只是空镜、风景、无人物、无动作 → appeal 不超过 5 分
- 大多数日常素材：quality 在 4-7，appeal 在 3-6，不要轻易给 8 以上

输出格式（严格JSON，quality和appeal为0-10整数，根据实际画面拉计分，不要参照示例数值）：
{"quality": <0-10>, "appeal": <0-10>, "tags": ["..."], "description": "...", "scene_type": "...", "mood": "..."}

tags: 提取3-5个关键标签，如"人像""户外""运动""微笑""建筑"。
description: 用一句话描述画面内容，50字以内。
scene_type: 必须从以下选项中选择：人物活动/风景/产品特写/会议/室内/抽象/其他。
mood: 必须从以下选项中选择：欢快/严肃/紧张/温馨/宏大/悲伤/中性。
PROMPT;
}
