# Flowerpot 🌱 花盆

> **花生在土里，你的素材种在花盆里。**
> 你的素材，长在你自己的电脑上——不是花生AI的素材库。

本地运行的视频素材智能管理工具：扫描你的素材文件夹，用 AI 自动打标签、评质量、筛高光；粘贴配音文稿，AI 拆句分类，直接告诉你每句该配什么画面。所有功能按「工程」隔离，GPL v3 开源。

## 功能

- **工程隔离** — 每个专题片一个工程，A机/B机/航拍/AE输出各配各的文件夹，互不污染
- **AI 素材分析** — 抽3帧送 GLM-4.6V-Flash（免费），输出质量分、吸引力分、标签、一句话描述、场景类型、情绪
- **素材库检索** — 关键词搜索（LIKE）、场景/情绪筛选、质量/吸引力阈值、多维度排序
- **文稿分类** — 粘贴整篇配音文稿，GLM-4.7-Flash 一次性拆句分类（素材型/数据型/抽象型），抽象大词给画面建议
- **素材匹配** — 每句自动在当前工程素材库中匹配素材（缩略图预览，可手动调整）
- **CSV 导出** — 真下载（Content-Disposition: attachment），Excel 直接打开不乱码
- **来源自动识别** — 路径含「航拍/无人机/drone」自动打「航拍」标签

## 安装

### 环境要求

- PHP 8.3+（需 pdo_sqlite 扩展）
- **FFmpeg + FFprobe**（必须；本工具用 `shell_exec` 调用，路径可在 config.php 指定）
- Apache（.htaccess）或 PHP 内置服务器
- 智谱 API Key（免费注册：https://open.bigmodel.cn ）

### 步骤

```bash
# 1. 克隆到 web 目录（Laragon 示例）
cd M:\laragon\www
git clone <repo-url> flowerpot

# 2. 复制配置模板
copy config.example.php config.php
copy .env.example .env

# 3. 编辑 .env，填入你的智谱 API Key
notepad .env

# 4. 按需编辑 config.php（FFmpeg 路径等）
notepad config.php

# 5. 初始化（检查依赖 + 建表）
php init.php

# 6. 浏览器访问
http://localhost/flowerpot/
```

### 使用流程

```
1. 「工程」页新建工程 → 填名称 → 粘贴扫描文件夹（一行一个）
2. 「扫描」页点「开始扫描」（或命令行 php cli/scan.php --project=1）
   # 免费模型限流自适应节流：基础间隔8s，撞429指数加倍（上限300s）
   # 实测参考：某专题片 1820 段，约 4-6 分钟/10 段，全量约 15-20 小时（挂机可断点续扫）
3. 「素材库」搜索筛选高光素材
4. 「文稿分类」粘贴配音文稿 → AI 拆句分类 → 导出 CSV
```

### CLI 命令

```bash
php cli/scan.php --projects        # 列出所有工程
php cli/scan.php --project=1       # 增量扫描工程1（跳过已分析）
php cli/scan.php --project=1 --force   # 强制重新分析
php cli/scan.php --stats [id]      # 统计
php cli/scan.php --clean-tmp       # 清理临时帧（只删已完成视频的）
```

## 我们的承诺

**删工程不删素材。** 工程只是索引和分类——花盆记录的是你素材的元数据和分析结果，删除工程或卸载花盆，你硬盘上的视频文件毫发无损。素材永远在你自己的电脑上，不上传到任何云存储。

## 依赖诚实标注

| 依赖 | 必需性 | 说明 |
|------|--------|------|
| PHP 8.3+ / SQLite | 必需 | 运行环境 |
| FFmpeg + FFprobe | **必需** | 抽帧、元数据提取（不用本地跑模型，只是命令行调用） |
| 智谱 API（免费模型） | 必需 | 视觉分析 + 文稿分类，Key 存本地 .env |
| faster-whisper | 第二阶段 | 音频转字幕时会引入额外依赖（Python 或预编译 exe），到时在文档中注明 |

## 技术栈

PHP 8.3+（无框架） · SQLite 3 · Bootstrap 5.3（暗色） · Bootstrap Icons · HTMX 2 · Alpine.js 3 · 智谱免费模型

## 许可证

GPL v3。详见 [LICENSE](LICENSE)。

---

*Flowerpot v0.1.0 · 花生在土里，你的素材种在花盆里*
