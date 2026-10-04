<?php
/**
 * Flowerpot - 环境自检（Doctor）
 * 检查项：config 存在 / API Key 已填 / Key 格式 / AI 实际连通（轻量 ping）
 * 结果缓存 data/health.json（TTL 10分钟），Web 端横幅与 CLI doctor 共用。
 */

if (!defined('FLOWERPOT')) die('direct access denied');

const FP_HEALTH_TTL = 600;

function fp_health_cache_file(): string
{
    return FLOWERPOT_ROOT . '/data/health.json';
}

/** 跑全套检查（$ping=false 时跳过真实 API 调用，仅本地检查） */
function fp_health_check(bool $ping = true, bool $force = false): array
{
    $cacheFile = fp_health_cache_file();
    if (!$force && is_file($cacheFile)) {
        $c = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($c) && (time() - (int)($c['ts'] ?? 0)) < FP_HEALTH_TTL) {
            return $c;
        }
    }

    $checks = [];

    // 1. config.php 存在
    $checks[] = ['name' => 'config.php', 'ok' => is_file(FLOWERPOT_ROOT . '/config.php'),
                 'msg' => is_file(FLOWERPOT_ROOT . '/config.php') ? '存在' : '缺失，请从 config.example.php 复制'];

    // 2. API Key 已配置
    $key = trim((string)(flowerpot_config()['zhipu']['api_key'] ?? ''));
    $keyOk = $key !== '';
    $checks[] = ['name' => 'ZHIPU_API_KEY', 'ok' => $keyOk,
                 'msg' => $keyOk ? '已配置' : '未配置（.env 中 ZHIPU_API_KEY=）'];

    // 3. Key 格式（智谱格式形如 xxxxxxxx.yyyyyyyy，含一个点）
    $fmtOk = $keyOk && (bool)preg_match('/^\S+\.\S+$/', $key);
    if ($keyOk) {
        $checks[] = ['name' => 'API_KEY 格式', 'ok' => $fmtOk,
                     'msg' => $fmtOk ? '格式正确（id.secret）' : '格式可疑：应形如 "xxxxxxxx.yyyyyyyy"，检查是否复制完整/带空格'];
    }

    // 4. AI 连通（轻量 ping，1次重试都没有，20s 超时）
    if ($ping && $fmtOk) {
        $t0 = microtime(true);
        [$httpCode, $body] = fp_zhipu_ping($key, flowerpot_config()['zhipu']);
        $ms = (int)((microtime(true) - $t0) * 1000);
        $decoded = json_decode($body, true);
        $ok = $httpCode === 200 && isset($decoded['choices'][0]['message']['content']);
        $msg = $ok ? "连通（{$ms}ms）"
             : ($httpCode === 401 ? '401 认证失败：Key 无效或已过期'
             : ($httpCode === 429 ? '429 限流（Key 本身有效，稍后自动重试）'
             : "HTTP {$httpCode}：" . mb_substr($decoded['error']['message'] ?? $body ?: '无响应', 0, 120)));
        $checks[] = ['name' => '智谱 API 连通', 'ok' => $ok || $httpCode === 429, 'msg' => $msg];
    }

    // 5. FFmpeg 路径检测
    $ffConfig = flowerpot_config()['ffmpeg'] ?? [];
    $ffmpegPath = trim($ffConfig['ffmpeg_path'] ?? '');
    $ffprobePath = trim($ffConfig['ffprobe_path'] ?? '');
    $ffOk = true;
    $ffMsg = '';

    if ($ffmpegPath === '' || $ffprobePath === '') {
        $ffOk = false;
        $ffMsg = 'config.php 未配置 ffmpeg_path / ffprobe_path，请在 config.php 的 ffmpeg 数组中填写路径';
    } elseif (!is_file($ffmpegPath) || !is_file($ffprobePath)) {
        $ffOk = false;
        $missing = [];
        if (!is_file($ffmpegPath)) $missing[] = 'ffmpeg → ' . $ffmpegPath;
        if (!is_file($ffprobePath)) $missing[] = 'ffprobe → ' . $ffprobePath;
        $ffMsg = '文件不存在：' . implode('；', $missing) . '（检查路径是否正确，盘符是否变更）';
    } else {
        // 文件存在，尝试验证 ffprobe 能正常运行
        $ver = shell_exec('"' . $ffprobePath . '" -version 2>&1');
        if (strpos($ver ?? '', 'ffprobe version') === false) {
            $ffOk = false;
            $ffMsg = 'ffprobe 无法正常运行（返回异常），请确认路径指向有效的 FFmpeg 构建';
        } else {
            $ffMsg = '正常（' . basename($ffmpegPath) . ' + ' . basename($ffprobePath) . '）';
        }
    }
    $checks[] = ['name' => 'FFmpeg/FFprobe', 'ok' => $ffOk, 'msg' => $ffMsg];

    $result = ['ok' => !in_array(false, array_column($checks, 'ok'), true), 'ts' => time(), 'checks' => $checks];
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $result;
}

/** 直连 ping（不走共享节流/重试，10分钟一次，不影响正常扫描节奏） */
function fp_zhipu_ping(string $apiKey, array $zcfg): array
{
    $ch = curl_init($zcfg['api_url']);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['model' => $zcfg['text_model'],
            'messages' => [['role' => 'user', 'content' => 'ping，只回复 pong']]]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, (string)$body];
}

/** 检查失败时给出人话修复指引 */
function fp_health_hint(array $health): string
{
    foreach ($health['checks'] as $c) {
        if ($c['ok']) continue;
        return htmlspecialchars($c['name']) . '：' . htmlspecialchars($c['msg']);
    }
    return '';
}
