<?php
return [
    // 应用配置
    'app' => [
        'name' => 'Flowerpot',
        'version' => '0.1.0',
        'debug' => true,
        'max_execution_time' => 300,  // Web 长任务 set_time_limit（s）
    ],

    // 前端 CDN 源
    'cdn_base' => 'https://cdn.jsdelivr.net/npm',

    // 智谱API配置
    'zhipu' => [
        'api_key' => 'your_api_key_here',
        'api_url' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
        // 视觉模型 fallback 链：429 重试时依次轮换（首选 → 备选）
        'vision_model' => 'glm-4.6v-flash',
        'vision_models' => ['glm-4.6v-flash', 'glm-4v-flash', 'glm-4.1v-thinking-flash'],
        // 文本模型 fallback 链（文稿分类等）
        'text_model' => 'glm-4.7-flash',
        'text_models' => ['glm-4.7-flash', 'glm-4-flash-250414', 'glm-z1-flash'],
    ],

    // FFmpeg路径（支持 __DIR__ 相对项目根；留空/写 'ffmpeg' 则要求在 PATH 中）
    'ffmpeg' => [
        'ffmpeg_path' => __DIR__ . '/inc/ffmpeg.exe',
        'ffprobe_path' => __DIR__ . '/inc/ffprobe.exe',
    ],

    // 高光素材阈值（全站统一：stats 徽标/素材库筛选/CLI 统计都用它）
    'highlight_min' => 8,

    // AI 请求节流与退避（跨进程共享，全站统一）
    'ai' => [
        'interval_min' => 8,      // 基础最小间隔（s），连续成功衰减下限
        'interval_max' => 300,    // 撞429退避上限（s）
        'web_wait_max' => 45,     // Web 请求服务端最长等待（s），超出返回剩余秒数
        'backoff_base' => 5,      // 429 重试基础延迟（s），指数递增
        'backoff_max' => 90,      // 429 重试延迟封顶（s）
        // CA 证书候选路径（php.ini cacert 失效时依次尝试；程序内优先，随项目迁移）
        'ca_bundle_candidates' => [
            __DIR__ . '/data/cacert.pem',
        ],
    ],

    // 路径配置
    'paths' => [
        'default_scan_folder' => 'D:/Videos',
        'thumbnails_folder' => __DIR__ . '/public/thumbnails',
        'frames_tmp_folder' => __DIR__ . '/data/tmp/frames',
        'exports_folder' => __DIR__ . '/data/exports',
    ],

    // 数据库
    'database' => [
        'path' => __DIR__ . '/data/flowerpot.db',
    ],

    // 分析配置
    'analysis' => [
        'thumbnail_quality' => 2,              // JPEG质量 (2-31, 越小越好)
        'frame_positions' => [0.1, 0.5, 0.9],  // 截帧位置（比例，避开首尾黑帧）
    ],

    // EasyClaw HTTP 接口认证（留空则不校验）
    'easyclaw' => [
        'token' => 'change-me',
    ],

    // 来源识别关键词（扫描时匹配路径，自动打 source 标签）。键=路径关键词，值=来源标签
    'source_keywords' => [
        '航拍' => '航拍', '无人机' => '航拍', 'drone' => '航拍', 'aerial' => '航拍', 'dji' => '航拍', 'fpv' => '航拍',
        '手机' => '手机', 'phone' => '手机',
        '稳定器' => '稳定器', 'gimbal' => '稳定器',
        '运动相机' => '运动相机', 'gopro' => '运动相机', 'insta360' => '运动相机', 'actioncam' => '运动相机',
        'ae输出' => 'AE输出', 'after effects' => 'AE输出', '渲染' => 'AE输出', '输出' => 'AE输出',
    ],
];
