# Flowerpot 项目规格说明书

> **花生在土里，你的素材种在花盆里**
> 你的素材，长在你自己的电脑上，不是花生AI的素材库。

---

## 一、项目概述

### 1.1 项目定位

**Flowerpot（花盆）** 是一个本地运行的视频素材智能管理工具，旨在解决视频剪辑师面对大量素材时的"启动困难"和"筛选疲劳"问题。

**一句话描述：**
> 在你的电脑上扫描视频素材，自动打标签、评质量、支持关键词搜索，导出高光片段列表供剪辑软件使用。

**对标产品：**
- Adobe Bridge（文件管理）
- 花生AI（智能匹配逻辑）
- VideoSeek（语义搜索）

**差异化优势：**
- 全部本地运行，素材不出门
- 基于用户熟悉的技术栈（PHP + WAMP）
- GPL v3 开源，技术民主化
- 轻量级，零额外环境依赖（仅需 FFmpeg）

### 1.2 核心用户场景

| 场景 | 描述 |
|------|------|
| **扫描素材** | 命令行运行 `php cli/scan.php D:/Videos`，程序遍历指定文件夹里的所有视频，自动提取元数据 + AI分析内容（MVP阶段通过CLI扫描，避免PHP超时） |
| **浏览结果** | 打开浏览器，看到所有视频的缩略图、时长、质量分、吸引力分、自动生成的标签 |
| **搜索素材** | 输入关键词（如「户外」「人像」「微笑」），显示匹配的素材列表 |
| **筛选高光** | 按 quality + appeal 排序，一键过滤出「值得用」的素材 |
| **脚本分类** | 粘贴配音文稿 → AI自动按句拆解 + 分类为「素材型/数据型/抽象型」 |
| **导出清单** | 把选中的素材导出为 CSV 或剪映/PR可导入的格式 |

### 1.3 解决的核心痛点

1. **启动困难**：面对20分钟配音音频和空荡荡的时间线，不知道第一步做什么
2. **筛选疲劳**：300段素材肉眼过一遍需要2小时，越看越麻木，好素材全漏了
3. **抽象大词无画面**：「船大能远航」「共创辉煌」这类抽象概念不知道配什么画面
4. **思维外包需求**：想把"要不要"的决策外包，只保留最终创意决策权

---

## 二、功能模块

### 2.1 模块总览

```
┌─────────────────────────────────────────────────────────────┐
│                    Flowerpot 功能架构                         │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │  A. 扫描引擎  │  │ B. 检索筛选  │  │ C. 展示界面  │       │
│  │              │  │              │  │              │       │
│  │ • 目录遍历   │  │ • 标签筛选   │  │ • 文件列表   │       │
│  │ • 元数据提取 │  │ • 评分筛选   │  │ • 缩略图展示 │       │
│  │ • 抽帧分析   │  │ • 关键词搜索 │  │ • 筛选控件   │       │
│  │ • AI内容分析 │  │ • 排序       │  │ • 批量选择   │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐       │
│  │ D. 文稿分类  │  │ E. 素材匹配  │  │ F. 导出功能  │       │
│  │  (MVP新增)   │  │              │  │              │       │
│  │ • 粘贴文稿   │  │ • 关键词匹配 │  │ • CSV导出    │       │
│  │ • AI拆句     │  │ • 备选推荐   │  │ • EDL导出    │       │
│  │ • 类型分类   │  │              │  │ • FCPXML导出 │       │
│  └──────────────┘  └──────────────┘  └──────────────┘       │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 模块 A：扫描引擎（核心）

| 功能 | 实现方式 | 输出 |
|------|----------|------|
| 遍历目录 | PHP `RecursiveDirectoryIterator` | 文件列表 |
| 提取视频元数据 | `shell_exec('ffprobe ...')` | 时长、分辨率、码率、编码格式 |
| 抽帧 | `shell_exec('ffmpeg -ss {time} -i ... -vframes 1 ...')` | 抽取3帧送AI分析 |
| 生成缩略图 | 复用抽帧结果（第2帧作为缩略图） | 用于Web界面展示 |
| AI内容分析 | cURL 调智谱 GLM-4.6V-Flash（传图片base64） | JSON: tags, quality, appeal, description |
| 写入索引 | SQLite 数据库 | 所有分析结果持久化 |

#### 抽帧策略

**采用抽帧传图片方案**，不传整个视频base64（原因：大视频base64会爆PHP `memory_limit`）。

| 参数 | 值 | 说明 |
|------|-----|------|
| 抽帧数量 | 3帧 | 够覆盖开头/中间/结尾 |
| 抽帧位置 | `[0.1, 0.5, 0.9]` × duration | **不用 `[0, 0.5, 1]`**，因为首尾帧容易黑屏/空帧 |
| FFmpeg 模式 | input seeking（`-ss` 在 `-i` 前面） | 速度快几个数量级 |
| 图片质量 | `-q:v 2` | 高质量JPEG |
| 临时文件路径 | `data/tmp/frames/{video_id}_{1\|2\|3}.jpg` | 分析完移入 thumbnails 或删除 |

**FFmpeg 命令示例：**
```bash
ffmpeg -ss 5.0 -i "D:\Videos\demo.mp4" -vframes 1 -q:v 2 "data/tmp/frames/42_1.jpg" -y
```

**⚠️ Windows 注意事项：**

**不要使用 `escapeshellarg()`！** Windows 下该函数有以下问题：
- 遇到中文/非ASCII字符（素材文件名常见）在某些 PHP 版本下返回空字符串
- 嵌套双引号场景行为异常

**正确做法：使用自定义封装函数：**
```php
function win_path(string $path): string {
    // 统一转为反斜杠，去除已有双引号，包裹双引号
    return '"' . str_replace('"', '', str_replace('/', '\\', $path)) . '"';
}
$cmd = 'ffmpeg -ss 5.0 -i ' . win_path($videoPath) . ' -vframes 1 ...';
```

FFmpeg 在 Windows 下能正确识别正斜杠和反斜杠路径。

#### AI 分析容错

- 若 AI 返回非 JSON 格式或解析失败 → `status` 标记为 `failed`，`error_message` 记录原始响应
- **不中断整体扫描流程**，跳过当前文件继续处理下一个
- 若 tags 为空数组 → 保留其他字段，tags 记为 `[]`

**AI分析输出结构：**
```json
{
  "tags": ["人像", "户外", "运动", "微笑"],
  "quality": 8,
  "appeal": 9,
  "description": "阳光下年轻女性在草地上奔跑，面带微笑，背景是蓝天白云",
  "scene_type": "人物活动",
  "mood": "欢快"
}
```

### 2.3 模块 B：检索与筛选

| 功能 | 实现方式 |
|------|----------|
| 标签筛选 | SQL `WHERE tags LIKE '%关键词%'` |
| 评分筛选 | `WHERE quality > 6 AND appeal > 6` |
| 关键词搜索 | `WHERE filename LIKE '%关键词%' OR description LIKE '%关键词%' OR tags LIKE '%关键词%'` |
| 排序 | 按 appeal DESC、quality DESC、时长、文件名 |

> ⚠️ **关于搜索的说明：** SQLite FTS5 默认的 `unicode61` 分词器**不支持中文分词**（按空格切词，中文无空格），会导致搜索失效。MVP 阶段直接使用 `LIKE '%关键词%'`，对 300 条记录完全够快。FTS5（配合 trigram tokenizer 或自定义分词器）或向量语义搜索留到第三阶段。**当前搜索为关键词匹配，非向量语义搜索。**

### 2.4 模块 C：展示界面

| 功能 | 实现方式 |
|------|----------|
| 文件列表 | Bootstrap 5 卡片网格 |
| 缩略图 | 指向生成好的截图文件（复用抽帧的第2帧） |
| 筛选控件 | 下拉框 + 滑块 + 搜索框（HTMX + Alpine.js） |
| 批量选择 | 复选框，支持全选 |
| 导出按钮 | 导出选中素材的CSV/XML |

### 2.5 模块 D：文稿分类（MVP 新增）

**这是花盆区别于 Edit Mind 的核心功能。** 针对专题片/企业宣传片的配音驱动剪辑工作流。

MVP 阶段实现「粘贴文稿 → AI分类」，**不需要 Whisper**（音频转字幕留到第二阶段）。

| 功能 | 实现方式 | 阶段 |
|------|----------|------|
| 粘贴/输入文稿 | Web 文本框，用户粘贴配音文稿 | **MVP** |
| AI 自动拆句 | 智谱文本模型（GLM-4.7-Flash）按句拆解 | **MVP** |
| 句子分类 | 智谱文本模型判断每句类型（material/data/abstract） | **MVP** |
| 音频转字幕 | 本地 faster-whisper 或剪映识别字幕 | 第二阶段 |

**句子分类规则：**

| 类型 | 判断标准 | 示例 |
|------|----------|------|
| material（有素材型） | 描述具体的、可拍摄的实体或场景 | "工人在生产线上操作" |
| data（数据型） | 包含具体数字、增长数据、统计信息 | "2023年销售额增长了150%" |
| abstract（抽象型） | 宏大的、抽象的、无法用具体画面表现的词 | "船大能远航""共创辉煌" |

**抽象大词处理建议：**

| 抽象词 | AI建议的画面 | 情绪分类 |
|--------|--------------|----------|
| 船大能远航 | 航拍船只破浪前进 / 宏大的海洋画面 | 宏大/希望 |
| 逐鹿中原 | 地图动画，光点在地图上移动 / 城市航拍快剪 | 速度/谋划 |
| 共创辉煌 | 握手的特写 / 多人击掌 / 光芒汇聚的特效 | 希望/团结 |
| 新格局 | 几何图形重组 / 建筑延时摄影 / 城市天际线变迁 | 科技/宏大 |
| 运筹帷幄 | 棋盘特写 / 会议室全景 / 手指点地图 | 谋划/严肃 |

### 2.6 模块 E：素材匹配（第二阶段）

| 功能 | 实现方式 |
|------|----------|
| 关键词匹配 | 从脚本句子提取关键词，在素材索引中搜索 |
| 关键词搜索 | SQL `LIKE '%关键词%'`（MVP 搜索方案） |
| 备选推荐 | 返回 Top 3 匹配素材供用户选择 |

### 2.7 模块 F：导出功能

| 格式 | 用途 | 阶段 |
|------|------|------|
| CSV | 在Excel里查看/排序，手动导入剪辑软件 | MVP |
| EDL | Edit Decision List，导入 Premiere Pro | 第二阶段 |
| FCPXML | 导入 Final Cut Pro | 第二阶段 |
| 剪映草稿 | 导入剪映（格式较复杂） | 第三阶段 |

> 📝 **EDL 导出预留说明：** EDL 需要源素材的入出点（in/out），`script_lines` 表在第二阶段需增加 `source_in` / `source_out` 字段，默认取素材中间 N 秒。

---

## 三、技术栈

### 3.1 技术选型

| 组件 | 选择 | 理由 |
|------|------|------|
| 后端语言 | PHP 8.3+（实测 8.4.24） | 用户主力语言。⚠️ 8.4 的坑：`string $x = null` 会报 nullable 弃用警告，必须写 `?string $x = null` |
| Web服务器 | Laragon/WAMP | 本地已有环境 |
| 数据库 | SQLite 3 | 零配置，单文件，WAMP自带 |
| 前端框架 | Bootstrap 5.3 | 成熟稳定，可用Bootswatch主题 |
| 图标 | Bootstrap Icons | 统一风格，无需手写SVG |
| 交互框架 | Alpine.js | 轻量级，适合局部交互 |
| 服务端交互 | HTMX | 减少JS编写，服务端渲染 |
| PHP框架 | 不使用框架（MVP） | MVP 阶段所有逻辑写在 index.php + 少量 include 文件中，降低复杂度 |
| 外部依赖 | FFmpeg（ffprobe + ffmpeg） | 系统已安装 |
| AI接口 | 智谱 GLM-4.6V-Flash（免费） | 已有API Key |
| 许可证 | GPL v3 | 技术民主化 |

> 📝 **关于框架：** TooBasic Framework 可作为后续迭代选项（当文件数量增多、需要更清晰的路由时引入）。MVP 阶段不使用任何框架，保持轻量。

### 3.2 智谱免费模型

| 模型名称 | 上下文 | 输入价格 | 输出价格 | 适用场景 |
|----------|--------|----------|----------|----------|
| GLM-4.7-Flash | 200K | 免费 | 免费 | 文本分析、脚本拆解、句子分类 |
| GLM-4-Flash-250414 | 128K | 免费 | 免费 | 轻量文本任务 |
| GLM-Z1-Flash | 128K | 免费 | 免费 | 推理任务 |
| GLM-4.6V-Flash | — | 免费 | 免费 | **视频/图片理解（主力视觉模型）** |
| GLM-4V-Flash | 4K | 免费 | 免费 | 轻量单图描述 |
| GLM-4.1V-Thinking-Flash | 64K | 免费 | 免费 | 复杂视觉推理 |

**API Key 管理：**
- ⚠️ **绝不要在代码或文档中明文写入 API Key**
- 使用 `.env` 文件存储，通过 `getenv('ZHIPU_API_KEY')` 读取
- `.env` 文件必须加入 `.gitignore`
- 提供 `config.example.php` 作为模板，Key 位置写占位符
- 首次使用前需去智谱控制台重置 Key（旧 Key 已在文档中暴露过）

### 3.3 前端技术说明

#### Bootstrap 5.3
- 文档：https://getbootstrap.com/docs/5.3/getting-started/introduction/
- 可使用 Bootswatch 主题快速换肤
- 使用 Bootstrap Icons 作为图标库

#### HTMX
- 用于服务端交互，减少JavaScript编写
- 核心属性：`hx-get`, `hx-post`, `hx-target`, `hx-swap`, `hx-trigger`
- 适合场景：筛选、搜索、分页、批量操作

#### Alpine.js
- 轻量级前端交互，适合局部状态管理
- 核心指令：`x-data`, `x-text`, `x-show`, `x-for`, `x-on`, `x-model`
- 适合场景：模态框、下拉菜单、表单验证、局部刷新

### 3.4 外部依赖说明

| 依赖 | 必需性 | 说明 |
|------|--------|------|
| FFmpeg + FFprobe | **必需** | 抽帧、提取元数据 |
| PHP 8.3 + SQLite | **必需** | 运行环境 |
| faster-whisper | 第二阶段 | 音频转字幕（本地 exe 或 Python 脚本，PHP 通过 `shell_exec` 调用） |

> ⚠️ **诚实标注：** 第二阶段的 Whisper 依赖会引入额外环境要求（Python 或预编译 exe），这将使"零额外环境依赖"的宣传语不再完全成立。README 中需诚实标注。

---

## 四、项目结构

### 4.1 MVP 阶段（轻量，无框架）

```
M:\laragon\www\flowerpot\
├── index.php                    # 入口路由 + 页面渲染
├── config.php                   # 配置文件（从 .env 读取敏感信息）← 加入 .gitignore
├── config.example.php           # 配置模板（提交到 git）
├── .env                         # 环境变量（API Key 等）← 加入 .gitignore
├── .env.example                 # 环境变量模板
├── .gitignore                   # 忽略 config.php, .env, data/, vendor/
├── bootstrap.php                 # 初始化：定义常量 + flowerpot_config + load_env + base_url
│
├── includes/                    # 核心函数文件
│   ├── scanner.php              # 遍历目录 + ffprobe/ffmpeg 调用
│   ├── ai_client.php            # cURL 调智谱 API + 指数退避 + 共享节流（ai_throttle.json）
│   ├── scan_process.php         # 单视频处理流水线（CLI/Web 共用）：scan_process_one_video() / scan_pick_next()
│   ├── database.php             # SQLite 读写函数（含 infer_source 来源打标 / video_delete / project_delete）
│   ├── search.php               # 搜索/筛选函数
│   ├── classifier.php           # 文稿分类函数
│   └── export.php               # CSV/EDL 导出函数
│
├── tpl/                         # 模板文件
│   ├── _wrapper.php             # 布局包装器（header + footer）
│   ├── home.php                 # 首页/仪表盘
│   ├── library.php              # 素材库页面
│   ├── classify.php             # 文稿分类页面
│   └── partials/                # 局部模板（HTMX 返回用）
│       ├── video-card.php
│       ├── video-list.php
│       └── filter-bar.php
│
├── public/                      # 静态资源
│   ├── css/
│   │   └── app.css              # 自定义样式
│   ├── js/
│   │   └── app.js               # 自定义脚本
│   └── thumbnails/              # 生成的缩略图
│
├── data/                        # 数据目录（.gitignore）
│   ├── flowerpot.db             # SQLite数据库文件
│   ├── tmp/
│   │   └── frames/              # 抽帧临时文件（分析完可清理）
│   └── exports/                 # 导出的文件
│
├── cli/                         # 命令行工具
│   └── scan.php                 # 命令行批量扫描
│
├── README.md
├── LICENSE                      # GPL v3
└── SPEC.md                      # 本文档
```

### 4.2 后续迭代（可选 TooBasic）

当功能增多后，可将 `includes/` 中的函数重构为类，引入 TooBasic Framework 的 Controller/Template 模式。

---

## 五、数据库设计

### 5.0 projects 表（工程，v0.1.0 新增）

```sql
CREATE TABLE projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,                    -- 工程名称，如 "2026专题片"
    folders TEXT,                          -- 扫描文件夹列表，JSON数组 ["D:/A机","E:/航拍"]
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

所有功能按工程隔离：videos/scripts/scan_jobs 均有 `project_id` 外键（ON DELETE CASCADE）。
同一路径允许存在于不同工程（upsert 按 path + project_id 查重）。

### 5.1 videos 表

```sql
CREATE TABLE videos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,  -- 工程外键
    
    -- 文件信息
    path TEXT NOT NULL,                  -- 文件绝对路径（唯一性为 path+project_id）
    filename TEXT,                        -- 文件名
    extension TEXT,                       -- 扩展名
    filesize INTEGER,                     -- 字节
    file_mtime INTEGER,                   -- 文件修改时间（Unix时间戳），用于增量扫描
    source TEXT,                          -- 来源（扫描时按 config 的 source_keywords 匹配路径自动打标：
                                          --   航拍/无人机/drone→航拍，手机/phone→手机，稳定器/gimbal→稳定器，
                                          --   运动相机/gopro/insta360→运动相机，输出/渲染→AE输出；
                                          --   关键词表可在 config.php 自定义；扫描后也可在详情弹窗手动改）
                                          --   实现：includes/database.php infer_source() / video_set_source()
    
    -- 视频元数据
    duration REAL,                        -- 秒
    width INTEGER,
    height INTEGER,
    codec TEXT,
    bitrate INTEGER,
    fps REAL,
    
    -- 缩略图 & 抽帧
    thumbnail_path TEXT,                  -- 缩略图相对路径（复用 frame_2）
    frame_1_path TEXT,                    -- 第1帧路径（开头 10%）
    frame_2_path TEXT,                    -- 第2帧路径（中间 50%）
    frame_3_path TEXT,                    -- 第3帧路径（结尾 90%）
    
    -- AI分析结果
    tags TEXT,                            -- JSON数组 ["人像","户外","运动"]
    quality INTEGER,                      -- 0-10 画质分
    appeal INTEGER,                       -- 0-10 吸引力分
    description TEXT,                     -- 一句话描述
    scene_type TEXT,                      -- 场景类型
    mood TEXT,                            -- 情绪/氛围
    
    -- 状态
    status TEXT DEFAULT 'pending',        -- pending | analyzing | done | failed
    error_message TEXT,                   -- 失败原因（含原始AI响应）
    analyzed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- 索引
CREATE INDEX idx_videos_status ON videos(status);
CREATE INDEX idx_videos_quality ON videos(quality);
CREATE INDEX idx_videos_appeal ON videos(appeal);
CREATE INDEX idx_videos_scene_type ON videos(scene_type);
CREATE INDEX idx_videos_mtime ON videos(file_mtime);
```

### 5.2 scripts 表（文稿分类）

```sql
CREATE TABLE scripts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER REFERENCES projects(id) ON DELETE CASCADE,  -- 工程外键
    name TEXT NOT NULL,                    -- 脚本名称
    source_text TEXT,                      -- 用户粘贴的原始文稿
    audio_path TEXT,                       -- 配音音频路径（第二阶段）
    duration REAL,                         -- 总时长
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE script_lines (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    script_id INTEGER NOT NULL,
    line_number INTEGER NOT NULL,
    
    -- 时间信息（第二阶段由 Whisper 填充）
    start_time REAL,                       -- 开始时间（秒），MVP阶段可为NULL
    end_time REAL,                         -- 结束时间（秒），MVP阶段可为NULL
    
    -- 内容
    text TEXT NOT NULL,                    -- 原文
    
    -- 分类
    line_type TEXT DEFAULT 'unknown',      -- material | data | abstract | unknown
    keywords TEXT,                         -- JSON数组
    
    -- 匹配结果（第二阶段）
    matched_video_id INTEGER,              -- 匹配的素材ID
    match_score REAL,                      -- 匹配分数
    ai_suggestion TEXT,                    -- AI建议
    source_in REAL,                        -- 源素材入点（秒，第二阶段）
    source_out REAL,                       -- 源素材出点（秒，第二阶段）
    
    -- 用户操作
    user_action TEXT,                      -- accepted | rejected | modified
    notes TEXT,                            -- 用户备注
    
    FOREIGN KEY (script_id) REFERENCES scripts(id) ON DELETE CASCADE,
    FOREIGN KEY (matched_video_id) REFERENCES videos(id) ON DELETE SET NULL
);

CREATE INDEX idx_script_lines_script ON script_lines(script_id);
CREATE INDEX idx_script_lines_type ON script_lines(line_type);
```

### 5.3 scan_jobs 表（扫描任务）

```sql
CREATE TABLE scan_jobs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    folder_path TEXT NOT NULL,
    status TEXT DEFAULT 'pending',         -- pending | running | completed | failed
    total_files INTEGER DEFAULT 0,
    processed_files INTEGER DEFAULT 0,
    failed_files INTEGER DEFAULT 0,
    skipped_files INTEGER DEFAULT 0,       -- 跳过的文件（mtime未变）
    
    -- 并发控制字段（第二阶段使用，MVP阶段可留空）
    worker_id TEXT,                        -- 工作进程ID
    retry_count INTEGER DEFAULT 0,         -- 重试次数
    max_retries INTEGER DEFAULT 3,         -- 最大重试次数
    
    started_at DATETIME,
    completed_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

---

## 六、API 设计

### 6.1 页面路由

| URL | 方法 | 功能 |
|-----|------|------|
| `/` | GET | 首页/仪表盘（统计卡片 + 工程概览） |
| `/projects` | GET/POST | 工程管理页（新建/编辑：名称 + 多行文件夹池） |
| `/library` | GET | 素材库列表（`?project=N` 工程隔离） |
| `/classify` | GET/POST | 文稿分类页面（MVP 核心，绑定工程） |
| `/scan` | GET | 扫描 GUI（每工程「开始扫描/强制重新分析」按钮 + 进度条 + 实时日志 + 停止；JS 循环调 /api/scan/one 每次处理一段） |
| `/export` | POST | 导出功能 |

#### 路由实现方式（无框架）

由于 MVP 不使用框架，需要自己实现微型路由器。在 `index.php` 中：

```php
// 解析请求路径
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$path = rtrim($path, '/') ?: '/';

// 简单路由表
$routes = [
    '/' => 'home',
    '/projects' => 'projects',
    '/library' => 'library',
    '/classify' => 'classify',
    '/scan' => 'scan',
];

// API 路由前缀
if (str_starts_with($path, '/api/')) {
    $apiAction = substr($path, 5); // 去掉 /api/
    // 分发到对应的 API 处理函数
    handle_api($apiAction);
    exit;
}

// 页面路由
$page = $routes[$path] ?? '404';
render_page($page);
```

配合 `.htaccess` 实现伪静态：
```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]
```

### 6.2 HTMX 端点

| 端点 | 方法 | 功能 | 返回 |
|------|------|------|------|
| `/api/videos` | GET | 获取视频列表（筛选+分页，参数透传 search_videos()） | HTML片段 |
| `/api/video` | GET | 单个视频详情 modal（`?id=N`） | HTML片段 |
| `/api/video/source` | POST | 手动设置素材来源（id + source），同步增删 tags | HTML徽章 |
| `/api/video/delete` | POST | 删除素材（记录+缩略图+帧；视频源文件不动） | JSON |
| `/api/project/delete` | POST | 删除工程及全部素材/文稿 | JSON |
| `/api/scan/one` | POST | 处理工程下一个待分析视频（project_id + force），浏览器 JS 循环驱动 | JSON |
| `/api/line` | POST | 修改文稿行类型（HTMX，表单含 id + type） | HTML徽章 |
| `/api/export/csv` | POST | 导出CSV | 文件下载（**传统表单提交，非HTMX**） |

> ⚠️ **关于文件导出的说明：** HTMX 的 `hx-post` 本质是 AJAX 请求，浏览器不会触发文件下载对话框。CSV/EDL 导出必须使用传统 `<form method="POST" action="/api/export/csv">` 提交，或用 `window.location` 跳转。其余交互（列表、搜索、筛选）使用 HTMX。

### 6.3 CLI 命令

```bash
# 扫描指定文件夹
php cli/scan.php "D:/Videos"

# 强制重新扫描（忽略 file_mtime）
php cli/scan.php "D:/Videos" --force

# 增量扫描（默认，跳过 file_mtime 未变的文件）
php cli/scan.php "D:/Videos"

# 查看统计
php cli/scan.php --stats

# 清理临时帧文件
php cli/scan.php --clean-tmp

# 环境自检（config / API Key 配置与格式 / AI 连通 / 数据库与目录可写）
php cli/doctor.php
php cli/doctor.php --no-ping   # 只做本地检查，不调 API
```

---

## 七、AI Prompt 设计

### 7.1 视频内容分析 Prompt（配合抽帧图片使用）

> 与 `includes/ai_client.php` 的 `get_analysis_prompt()` 完全一致（2026-09-11 同步）。修改 Prompt 必须两边同时改。

```
你是一个专业的视频素材分析助手。你将收到一个视频的{N}张截图。
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

tags: 提取3-5个关键标签，如"人像""户外""运动""微笑""建筑"。
description: 用一句话描述画面内容，50字以内。
scene_type: 必须从以下选项中选择：人物活动/风景/产品特写/会议/室内/抽象/其他。
mood: 必须从以下选项中选择：欢快/严肃/紧张/温馨/宏大/悲伤/中性。

输出格式（严格JSON，quality和appeal为0-10整数，根据实际画面拉计分，不要参照示例数值）：
{"quality": <0-10>, "appeal": <0-10>, "tags": ["..."], "description": "...", "scene_type": "...", "mood": "..."}
```

### 7.2 文稿拆句 + 分类 Prompt

```
你是一个专题片剪辑助手。用户将提供一段配音文稿，你需要：
1. 按「适合一句配一个画面」的原则拆分成句子列表
2. 对每句话判断类型

类型定义：
1. material（有素材型）：描述具体的、可拍摄的实体或场景
   例如："工人在生产线上操作"、"办公室里员工正在开会"
   
2. data（数据型）：包含具体数字、增长数据、统计信息
   例如："2023年销售额增长了150%"、"用户突破1000万"
   
3. abstract（抽象型）：宏大的、抽象的、无法用具体画面表现的词
   例如："船大能远航"、"共创辉煌"、"新格局"

输出格式（严格JSON数组，不要包含其他文字）：
[
  {
    "text": "回顾过去十年的发展历程",
    "type": "material",
    "keywords": ["回顾", "十年", "发展历程"],
    "visual_suggestion": "公司旧貌照片蒙太奇 / 历史影像资料"
  },
  {
    "text": "销售额增长了200%",
    "type": "data",
    "keywords": ["销售额", "增长", "200%"],
    "visual_suggestion": "柱状图/折线图动态展示"
  },
  {
    "text": "船大能远航",
    "type": "abstract",
    "keywords": ["船", "远航"],
    "visual_suggestion": "航拍船只破浪前进 / 宏大海洋画面",
    "abstract_category": "宏大/希望"
  }
]

注意：abstract_category 字段仅当 type 为 abstract 时出现，其他类型不需要此字段。

输入文稿：
{text}
```

---

## 八、用户界面设计

### 8.1 页面结构

```
┌─────────────────────────────────────────────────────────────┐
│  🌱 Flowerpot                                 [设置] [帮助] │
├─────────────────────────────────────────────────────────────┤
│  [首页]  [工程]  [素材库]  [文稿分类]  [扫描]                     │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│                    主内容区域                                 │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### 8.2 首页/仪表盘

```
┌─────────────────────────────────────────────────────────────┐
│ 仪表盘                                                       │
├─────────────────────────────────────────────────────────────┤
│ ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐        │
│ │ 📁 300   │ │ ✅ 280   │ │ ⏳ 15    │ │ ❌ 5     │        │
│ │ 总素材    │ │ 已分析   │ │ 待分析   │ │ 失败     │        │
│ └──────────┘ └──────────┘ └──────────┘ └──────────┘        │
│                                                              │
│ 最近扫描: 2026-09-10 11:30  耗时: 45分钟                    │
│ 高光素材 (quality≥8 且 appeal≥8): 42 个                     │
│                                                              │
│ [进入素材库] [新建文稿分类]                                   │
└─────────────────────────────────────────────────────────────┘
```

### 8.3 素材库页面

```
┌─────────────────────────────────────────────────────────────┐
│ 素材库                                          [批量操作▼] │
├─────────────────────────────────────────────────────────────┤
│ 搜索: [________________]  类型: [全部▼]  质量: [----●----]  │
│ 排序: [吸引力▼]         情绪: [全部▼]  吸引力: [----●----]  │
├─────────────────────────────────────────────────────────────┤
│ ┌─────────┐ ┌─────────┐ ┌─────────┐ ┌─────────┐           │
│ │ [缩略图] │ │ [缩略图] │ │ [缩略图] │ │ [缩略图] │           │
│ │ 质量: 8  │ │ 质量: 9  │ │ 质量: 6  │ │ 质量: 7  │           │
│ │ 吸引: 9  │ │ 吸引: 8  │ │ 吸引: 3  │ │ 吸引: 7  │           │
│ │ 人像,户外│ │ 产品特写 │ │ 模糊,抖动│ │ 会议,室内│           │
│ │ [详情]   │ │ [详情]   │ │ [详情]   │ │ [详情]   │           │
│ │ [ ] 选择 │ │ [ ] 选择 │ │ [ ] 选择 │ │ [ ] 选择 │           │
│ └─────────┘ └─────────┘ └─────────┘ └─────────┘           │
│                                                              │
│ [1] [2] [3] ... [10]  共 300 个素材                         │
└─────────────────────────────────────────────────────────────┘
```

### 8.4 文稿分类页面（MVP 核心页面）

```
┌─────────────────────────────────────────────────────────────┐
│ 文稿分类                                        [新建脚本]  │
├─────────────────────────────────────────────────────────────┤
│ 脚本名称: [________________]                                │
│                                                              │
│ 粘贴配音文稿：                                               │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ 回顾过去十年的发展历程，我们的销售额增长了200%。         │ │
│ │ 船大能远航，逐鹿中原，共创辉煌。新格局、新突破...        │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                              │
│ [AI 分析并分类]                                              │
├─────────────────────────────────────────────────────────────┤
│ 分析结果（类型下拉可编辑，HTMX保存；匹配素材为实时计算的top3缩略图） │
│ ┌────────────────────────────────────────────────────────────┐ │
│ │ # │ 原文            │ 类型▼   │ 视觉建议     │ 匹配素材    │ │
│ ├───┼─────────────────┼─────────┼──────────────┼─────────────┤ │
│ │ 1 │ 回顾过去十年... │ 素材型▼ │ 公司旧貌蒙太奇 │ [图][图][图] │ │
│ │ 2 │ 销售额增长200%  │ 数据型▼ │ 柱状图动态展示 │ [图][图][图] │ │
│ │ 3 │ 船大能远航      │ 抽象型▼ │ 航拍船只/海洋 │ [图][图][图] │ │
│ │ 4 │ 逐鹿中原        │ 抽象型▼ │ 地图动画/航拍 │ [图][图][图] │ │
│ └───┴─────────────────┴─────────┴──────────────┴─────────────┘ │
│   ▼ = 下拉可改（素材型/数据型/抽象型/未分类）                    │
│   匹配素材按标签/描述/场景/情绪LIKE打分(2/2/1/1)取top3，         │
│   悬停显示文件名与分数；匹配仅限当前工程素材库                  │
│                                                              │
│ [导出CSV] [重新分析]                                         │
└─────────────────────────────────────────────────────────────┘
```

### 8.5 图标使用（Bootstrap Icons）

| 功能 | 图标 |
|------|------|
| 首页 | `bi-house` |
| 素材库 | `bi-collection` |
| 文稿分类 | `bi-file-text` |
| 扫描 | `bi-search` |
| 导出 | `bi-download` |
| 设置 | `bi-gear` |
| 播放 | `bi-play` |
| 质量分 | `bi-star` |
| 吸引力 | `bi-heart` |
| 标签 | `bi-tags` |
| 删除 | `bi-trash` |
| 编辑 | `bi-pencil` |
| 素材型 | `bi-camera-reels` |
| 数据型 | `bi-bar-chart` |
| 抽象型 | `bi-lightbulb` |

---

## 九、开发计划

### 9.1 MVP 范围（第一阶段）

| 模块 | 是否包含 | 备注 |
|------|----------|------|
| CLI扫描 + ffprobe元数据 | ✅ | 核心 |
| 抽帧 + 智谱API图片分析 | ✅ | 核心，抽3帧传图片 |
| SQLite存储 | ✅ | 核心 |
| Web界面 - 列表展示 + 筛选 | ✅ | 核心 |
| Web界面 - 关键词搜索（LIKE） | ✅ | 核心 |
| Web界面 - 批量选择 + CSV导出 | ✅ | 核心 |
| **文稿分类（粘贴文稿→AI拆句+分类）** | ✅ | **核心，花盆的差异化功能** |
| 缩略图生成 | ✅ | 复用抽帧第2帧，几乎零成本 |
| file_mtime 增量扫描 | ✅ | 避免重复烧API |
| 素材匹配功能 | ❌ | 第二阶段 |
| EDL/FCPXML导出 | ❌ | 第二阶段 |
| 音频转字幕（Whisper） | ❌ | 第二阶段 |
| 批量并发扫描加速 | ❌ | 第二阶段 |

### 9.2 第二阶段

- 素材匹配功能（脚本句子 → 搜索素材库 → 推荐 Top 3）
- 音频转字幕（本地 faster-whisper，PHP `shell_exec` 调用）
- EDL/FCPXML导出
- 扫描进度实时显示（Web 轮询 scan_jobs 表）
- 批量并发扫描
- 视频详情弹窗（播放预览 + 3帧对比）

### 9.3 第三阶段

- FTS5 中文搜索优化（trigram tokenizer 或自定义分词器）
- 向量语义搜索（可选，需额外依赖）
- 人脸/物体识别（可选）
- 剪映草稿导出
- 插件系统
- TooBasic Framework 重构（如需要）

---

## 十、配置说明

### 10.1 .env（敏感信息，不提交到 git）

```env
ZHIPU_API_KEY=你的智谱API_KEY
```

### 10.2 .env.example（提交到 git）

```env
ZHIPU_API_KEY=your_api_key_here
```

### 10.3 config.php（从 .env 读取，不提交到 git）

```php
<?php
// 加载 .env 文件
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

return [
    // 应用配置
    'app' => [
        'name' => 'Flowerpot',
        'version' => '0.1.0',
        'debug' => true,
    ],
    
    // 智谱API配置
    'zhipu' => [
        'api_key' => getenv('ZHIPU_API_KEY') ?: '',
        'api_url' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
        'vision_model' => 'glm-4.6v-flash',      // 视觉模型（图片分析）
        'text_model' => 'glm-4.7-flash',         // 文本模型（文稿分类）
    ],
    
    // FFmpeg路径
    'ffmpeg' => [
        'ffmpeg_path' => 'ffmpeg',
        'ffprobe_path' => 'ffprobe',
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
];
```

### 10.4 config.example.php（提交到 git）

与 config.php 结构相同，但 `api_key` 值为 `'your_api_key_here'`。

### 10.5 .gitignore

```gitignore
# 敏感配置
config.php
.env

# 数据文件
data/flowerpot.db
data/tmp/
data/exports/

# 生成的缩略图
public/thumbnails/*
!public/thumbnails/.gitkeep

# 系统文件
Thumbs.db
.DS_Store

# IDE
.vscode/
.idea/
```

---

## 十一、扫描执行策略

### 11.1 为什么 MVP 用 CLI 扫描

| 问题 | 说明 |
|------|------|
| PHP 超时 | 默认 `max_execution_time = 30` 秒，300个视频 × 3帧 = 900次API调用，预计 40-90 分钟 |
| 内存限制 | `memory_limit` 默认 128M，虽然抽帧传图片比传视频好，但长时间运行仍有风险 |
| 用户体验 | 浏览器等待90分钟不现实 |

### 11.2 CLI 扫描流程

```
1. 用户运行: php cli/scan.php "D:/Videos"
2. 程序遍历文件夹，收集所有视频文件
3. 对比 file_mtime，跳过未变更的文件（增量扫描）
4. 对每个新文件：
   a. ffprobe 提取元数据
   b. ffmpeg 抽取3帧 → data/tmp/frames/
   c. cURL 将3帧图片发送给智谱 GLM-4.6V-Flash
      - 连接超时: 10秒；传输超时: 120秒
      - 发请求前先过共享节流器（ai_throttle.json，CLI/Web 共用）
      - 收到 429 (限流) → 全局间隔翻倍 + 指数退避重试（最多6次）
      - 仍失败 → 标记 failed，继续下一个
   d. 解析 JSON 响应（失败则记录 error_message，继续下一个）
   e. 第2帧移入 public/thumbnails/ 作为缩略图
   f. 写入 SQLite 数据库
   g. 终端输出进度: [42/300] DJI_0042.mp4 ✅ quality=8 appeal=9
5. 扫描完成，输出统计
```

#### API 限流重试策略

免费模型有并发限制，批量扫描时必然遇到 429 错误。实际实现（ai_client.php）两层机制：

**1）共享节流器（CLI/Web 共用状态文件 data/ai_throttle.json）：**

```php
// 发请求前调用：距上次请求不足 interval 秒则等待；Web 端 maxSleep 限2秒
$remain = ai_throttle_acquire(PHP_SAPI === 'cli' ? PHP_INT_MAX : 2);

// 结果反馈：撞 429 → interval 翻倍（上限300s）；成功 → ×0.7 衰减（下限8s）
ai_throttle_feedback($rateLimited);
```

**2）单请求内指数退避重试（最多6次）：**

```php
for ($retry = 0; $retry < $maxRetries; $retry++) {
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // 超时/网络错误时 curl_exec 返回 false、httpCode 为 0，也要重试
    if ($response === false || $httpCode === 0 || $httpCode === 429) {
        ai_throttle_feedback(true);                    // 全局间隔加倍
        $delay = 5 * (2 ** $retry) + rand(0, 5);       // 5,10,20,40,80,160s + 抖动
        ai_log("    [重试] http={$httpCode} 等待{$delay}s");
        sleep($delay);
        continue;
    }
    ai_throttle_feedback(false);                       // 成功：全局间隔回落
    break;
}
```

> ⚠️ 日志必须用 `ai_log()`（CLI 走 stderr，Web 走 error_log），直接 `fwrite(STDERR,...)` 在 Apache 下是致命错误，会污染 JSON 响应。

#### 路径存储约定

| 字段 | 存储格式 | 示例 |
|------|----------|------|
| `videos.path` | 绝对路径（唯一允许绝对路径的字段） | `D:/Videos/demo.mp4` |
| `thumbnail_path` | 相对 `public/` 的路径 | `thumbnails/42_2.jpg` |
| `frame_1/2/3_path` | 相对项目根的路径 | `data/tmp/frames/42_1.jpg` |

换电脑/换盘符时只需更新 `videos.path`。

#### 临时帧清理逻辑（--clean-tmp）

**按 status 清，不按目录扫：**
```sql
-- 只删已分析完成的视频的帧文件，不碰 pending/analyzing 状态
SELECT frame_1_path, frame_2_path, frame_3_path
FROM videos
WHERE status = 'done' AND frame_1_path IS NOT NULL;
```

#### shell_exec 捕获 stderr

ffprobe/ffmpeg 调用必须捕获 stderr，否则解析失败时错误信息被丢弃、无法调试：
```php
// ffprobe 加 2>&1，出错时把错误信息写入 error_message 字段
$output = shell_exec('ffprobe ... 2>&1');
```

### 11.3 扫描时间预估

| 素材数量 | 预计时间 | API调用次数 |
|----------|----------|-------------|
| 50 | 7-15 分钟 | 150 |
| 100 | 15-30 分钟 | 300 |
| 300 | 40-90 分钟 | 900 |

---

## 十二、参考项目

| 项目 | 特点 | 可借鉴之处 |
|------|------|------------|
| Edit Mind | 本地视频知识库，多模态打标签 | 索引结构、标签体系 |
| PromptClip-Skill | Prompt驱动的废片过滤器 | 筛选逻辑、时间线导出 |
| KB Cut | 本地口播剪辑Skill | 转写流程、剪辑逻辑 |
| VideoSeek | 本地语义视频搜索 | 抽帧分析、搜索架构 |
| 花生AI | B站AI视频生成工具 | 句级编辑、素材匹配逻辑 |

---

## 十三、许可证

本项目采用 **GPL v3** 许可证。

```
Flowerpot - 本地视频素材智能管理工具
Copyright (C) 2026

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

---

## 附录 A：快速开始

### A.1 环境要求

- PHP 8.3+
- SQLite 3
- FFmpeg（包含 ffmpeg 和 ffprobe）
- 现代浏览器
- 智谱 API Key（免费注册）

### A.2 安装步骤

```bash
# 1. 克隆项目到 Laragon www 目录
cd M:\laragon\www
git clone <repo-url> flowerpot

# 2. 复制配置模板
copy config.example.php config.php
copy .env.example .env

# 3. 编辑 .env，填入你的智谱 API Key
notepad .env

# 4. 初始化数据库
# 无需手动初始化 —— 首次访问时 bootstrap.php 自动建表

# 5. 访问 Web 界面
# http://localhost/flowerpot/
```

### A.3 命令行扫描

```bash
# 列出所有工程
php cli/scan.php --projects

# 增量扫描工程（跳过已分析/mtime未变的文件，failed自动重试）
php cli/scan.php --project=1

# 强制重新分析（1818个素材会全部重来，慎用）
php cli/scan.php --project=1 --force

# 查看统计（可选限工程ID）
php cli/scan.php --stats
php cli/scan.php --stats 2

# 清理临时帧文件（只删 status='done' 视频的帧）
php cli/scan.php --clean-tmp
```

---

## 附录 B：Review 修订记录

### v2.1.0 (2026-09-10) — 第二轮 Review 修订

**来源：** DeepSeek / 通义千问 / 智谱清言

| 修改项 | 修改内容 | 来源 |
|--------|----------|------|
| 🟡 Windows 路径处理 | 明确禁止使用 `escapeshellarg()`，改用自定义 `win_path()` 函数 | 智谱 + DeepSeek |
| 🟡 URL 路由机制 | 补充微型路由器实现说明 + `.htaccess` 配置 | 通义千问 |
| 🟡 CSV 导出交互 | 明确标注 CSV 导出用传统表单提交，非 HTMX | 通义千问 |
| 🟡 API 限流重试 | 补充 429 重试策略 + cURL 超时设置 | 智谱 |
| 🟡 Prompt 受控词表 | scene_type/mood 限定为固定选项，避免 AI 自由发挥 | 通义千问 |
| 🟡 Prompt 图片顺序 | 明确标注“图1=开头，图2=中间，图3=结尾” | 智谱 |
| 🟡 abstract_category | 明确该字段仅当 type=abstract 时出现 | DeepSeek |
| 🟡 scan_jobs 字段 | 并发控制字段直接加列（不再注释），MVP 可留空 | 通义千问 |
| 🟢 .env 解析器 | 增强健壮性：去引号、trim、跳过注释 | 通义千问 |
| 🟢 init.php 检查 | 补充说明：启动时检查 config.php 是否存在 | DeepSeek |

### v2.0.0 (2026-09-10) — 根据三方 Review 修订

**来源：** DeepSeek / 通义千问 / 智谱清言

| 修改项 | 修改内容 | 来源 |
|--------|----------|------|
| 🔴 API Key 泄露 | 删除明文 Key，改用 .env + getenv() | 三方共同指出 |
| 🔴 FTS5 中文失效 | MVP 改用 LIKE，FTS5 推到第三阶段 | 三方共同指出 |
| 🟡 抽帧位置 | `[0, 0.5, 1]` → `[0.1, 0.5, 0.9]`，避免首尾黑帧 | DeepSeek + 智谱 |
| 🟡 扫描执行方式 | 明确 MVP 只做 CLI 扫描，Web 只展示 | DeepSeek + 智谱 |
| 🟡 增量扫描 | 新增 file_mtime 字段，跳过未变更文件 | 智谱 |
| 🟡 AI 容错 | 解析失败不中断，记录 error_message | 通义千问 |
| 🟡 文稿分类进 MVP | 粘贴文稿→AI分类，不需要 Whisper | DeepSeek |
| 🟡 项目结构简化 | MVP 不用框架，includes/ 函数文件 | 通义千问 |
| 🟢 帧路径字段 | 数据库新增 frame_1/2/3_path | DeepSeek |
| 🟢 临时文件管理 | 明确 data/tmp/frames/ 路径和清理策略 | DeepSeek |
| 🟢 Windows 注意 | 注明 escapeshellarg() 和路径引号问题 | 智谱 |
| 🟢 EDL 预留 | script_lines 预留 source_in/source_out | 智谱 |
| 🟢 Whisper 标注 | 诚实标注第二阶段的环境依赖 | 智谱 |
| 🟢 scan_jobs 预留 | 注释中标注并发控制扩展字段 | 通义千问 |

## 附录 C：实现偏差记录（v0.1.0）

> SPEC 是设计，代码是现实。以下是 v0.1.0 实际实现与 SPEC 的差异，不记三个月后全忘。

| # | SPEC 原设计 | 实际实现 | 原因 |
|---|------------|----------|------|
| 1 | folders 为 JSON 数组 | **已按 SPEC 实现**：`projects.folders` 存 JSON 数组，UI 多行文本框一行一个，解析后 json_encode | — |
| 2 | 素材匹配结果存 `matched_video_id` | **匹配实时计算**：渲染结果表时按关键词 LIKE 现算 top3（不预存），仅 `matched_video_id` 存用户确认的最优绑定。理由：素材库随时在变（重扫/新素材），预存会过期 | 实时算保证匹配永远基于最新素材库 |
| 3 | 匹配分数未定义 | tags 命中2分 + description 命中2分 + scene_type 命中1分 + mood 命中1分，取 top3 | 简单可调 |
| 4 | `line_type` 字段名 | **采用 `line_type`（非千问建议的 category）**，类型下拉可编辑，HTMX POST /api/line 保存 | 与 SPEC 5.2 建表一致 |
| 5 | CSV 导出格式 | **追加 UTF-8 BOM（\xEF\xBB\xBF）**，否则 Excel 打开中文乱码；列固定：行号/原文/类型/关键词/AI建议画面/匹配素材文件名/匹配素材路径 | Excel 兼容 |
| 6 | 视频分析传“视频文件” | **抽3帧传 base64 图片**（首/中/尾 10%/50%/90%），frame_2 复用为缩略图 | 传整视频 base64 会爆 memory_limit，且图片分析更稳 |
| 7 | 抽帧位置 `[0.1, 0.5, 0.9]` 比例 | 额外保护：`min(ratio*duration, duration-0.2)`，避免短视频尾帧越界 | ffmpeg 对超尾 -ss 返回空 |
| 8 | escapeshellarg 禁用 | 已实现 `win_path()`：去引号+统一反斜杠+双引号包裹 | PHP 8.4 + 中文路径实测 |
| 9 | cURL 超时 5s/60s | **10s/120s**，重试 6 次，**指数退避**：`5×2^n + rand(0,5)` → 5/10/20/40/80/160s；`$response === false \|\| $httpCode === 0` 也重试；**视频间强制 sleep 8-12s**（配额型限流，连续请求=加速撞墙） | 3图 base64 请求体大 + 免费模型 429 频繁（实测高峰期连续429，固定短间隔负效率） |
| 10 | PHP CLI 扫描 | **保留直接传文件夹参数的兼容模式**（不推荐），主用 `--project=N` | 调试方便 |
| 11 | 文稿分类 Prompt 逐句 `abstract_category` | abstract_category 仅 abstract 时填值，其他类型空字符串（模型明确告知，避免困惑） | review 建议 |
| 12 | Laragon 内置服务器 | 缩略图友好 URL `/thumbnails/x.jpg` 由 index.php 手动吐文件（内置服务器），Apache 下 rewrite | 静态资源必须文件访问 |
| 13 | scene_type/mood 受控词表 | 实现于 Prompt，但 AI 偶尔仍溢出（如返回“其他”以外的值），**筛选下拉未含的值会被自然过滤**，不报错 | 容错优先 |
| 14 | scripts 表 `source_text` | 实现；`name` 为空时自动命名“未命名文稿 m-d H:i” | UX |
| 15 | 同一路径跨工程 | **允许**：upsert 按 (path, project_id) 查重，同一视频可在多个工程各有分析记录 | 工程隔离的推论 |

### v0.1.0 未实现（SPEC 内、留待后续）

- 匹配结果的交互式改绑 UI（当前仅展示 top3 缩略图，改绑需直接改数据库或后续迭代）
- EDL/FCPXML 导出（第二阶段）
- 音频转字幕 Whisper（第二阶段，会引入额外依赖）
- scan_jobs 表写入逻辑（表已建，CLI 扫描暂不记录任务状态）
- FTS5/向量搜索（第三阶段）

---

### v2.4.0 (2026-09-12) — 环境自检（Doctor）

- 新增 `includes/health_check.php`：检查 config.php / ZHIPU_API_KEY 是否配置与格式 / 智谱 API 实际连通（轻量 ping，直连不走共享节流）/ 429 视为 Key 有效
- 结果缓存 `data/health.json`（TTL 10分钟），Web 与 CLI 共用
- Web 端：所有页面顶部异常时显示黄色警示横幅（健康时无痕）
- CLI 端：`php cli/doctor.php [--no-ping]`，退出码 0/1 可用于脚本
- UI 主题同步：荧光绿主色 #C1FF72（tiffinbox 同款）、胶囊按钮（btn-primary 变量集覆盖）、大圆角卡片

---

*文档版本: 2.4.0*  
*最后更新: 2026-09-12*  
*变更说明: 新增环境自检（横幅+cli/doctor.php，缓存10min）；UI主题对齐tiffinbox参考（#C1FF72主色、按钮统一走btn-primary变量集）*
