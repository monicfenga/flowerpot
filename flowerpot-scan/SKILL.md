---
name: flowerpot-scan
description: 用 AI 视觉能力批量分析 Flowerpot（花盆）本地视频素材并入库。当用户要求扫描花盆素材、分析待处理视频、查看花盆工程进度，或提到 flowerpot / 花盆 / scan_easyclaw / 素材识别 / 视频抽帧分析时使用。流程为 HTTP 接口领任务（自动抽帧）→ 读取本地帧文件做视觉分析 → 回传结果入库。
---

# Flowerpot 素材扫描（AI 视觉版）

用 Agent 自身的图片识别能力，替代智谱 API，批量消化 Flowerpot 里待分析的视频素材。适合智谱高峰期限流时使用。

## 前置条件

- Flowerpot 部署在 Laragon：`M:\laragon\www\flowerpot\`，入口 `http://localhost/flowerpot/`（Apache，勿另起 `php -S`）
- 接口地址：`http://localhost/flowerpot/cli/scan_easyclaw.php`
- 认证 token：见 `M:\laragon\www\flowerpot\config.php` 的 `easyclaw.token`（默认 `fp-easyclaw-2026`）。所有请求都要带 `&token=xxx`
- 帧文件落在 `M:\laragon\www\flowerpot\data\tmp\frames\`，**Agent 直接按本地路径读取，不要走 HTTP**（image 工具读本地路径更快，且 localhost 会被 SSRF 拦截）

## 核心循环

对每个工程反复执行「领任务 → 分析 → 入库」，直到 `pick` 返回空：

### 1. 查看工程与进度

```powershell
curl.exe -s "http://localhost/flowerpot/cli/scan_easyclaw.php?action=projects&token=TOKEN"
```

返回每个工程的 `total / done / pending / failed`。选 `pending` 多的工程开始。

### 2. 领任务（自动抽帧）

```powershell
curl.exe -s --max-time 900 "http://localhost/flowerpot/cli/scan_easyclaw.php?action=pick&id=工程ID&limit=5&token=TOKEN" -o "$env:TEMP\fp_pick.json"; [System.IO.File]::ReadAllText("$env:TEMP\fp_pick.json")
```

- `limit`：本轮领几条（建议 5，上限 20）
- `force=1`：强制重分析（默认增量，跳过已 done 的）
- 返回 `items[]`，每条含 `video_id`、`filename`、`duration`、`frame_files`（**本地绝对路径数组**）
- 单次调用约 10–30 秒（要扫 F 盘目录），务必加 `--max-time 900`
- **注意**：`pick` 会把这些视频标记为 `analyzing`，必须在本轮结束前用 `analyze` 或 `fail` 收尾，否则会卡在 analyzing

### 3. 看图分析

对每个 item，拿 `frame_files` 里的本地路径，用 image 工具一次性看这条视频的 3 张帧：

```
image(images=[frame_files 里的路径...], prompt="这是同一条视频的关键帧，综合描述画面内容并归类")
```

判断以下字段（对齐 Flowerpot 的分析 schema）：

| 字段 | 说明 |
|---|---|
| `quality` | 素材画质/技术质量，1–10 整数 |
| `appeal` | 吸引力/可用性，1–10 整数 |
| `scene_type` | 场景类型，如「稻田人工查看」「无人机航拍」「人物特写」 |
| `mood` | 氛围，如「专注」「开阔」「生机」 |
| `description` | 一句话画面描述（中文） |
| `tags` | 标签数组，如 `["稻田","人物","农事"]` |

### 4. 回传入库

把结果写成 JSON 文件（**必须用文件 + `--data-binary @文件`**，PowerShell 内联 `--data` 会被转义搞坏）：

```powershell
$json = '{"video_id":123,"analysis":{"quality":8,"appeal":7,"scene_type":"稻田人工查看","mood":"专注","description":"三位男子在稻田边俯身查看稻穗交流种植情况","tags":["稻田","人物","农事"]}}'
[System.IO.File]::WriteAllText("$env:TEMP\fp_body.json", $json, (New-Object System.Text.UTF8Encoding $false))
curl.exe -s -X POST "http://localhost/flowerpot/cli/scan_easyclaw.php?action=analyze&token=TOKEN" -H "Content-Type: application/json" --data-binary "@$env:TEMP\fp_body.json"
```

成功返回 `{"ok":true,...}`。frame_2 会自动升级为该视频缩略图。

### 5. 失败处理

看不了帧 / 判断不了时：

```powershell
# retryable=true → 标回 pending，下轮还能领到；false → 永久 failed
curl.exe -s -X POST "http://localhost/flowerpot/cli/scan_easyclaw.php?action=fail&token=TOKEN" -H "Content-Type: application/json" --data-binary "@$env:TEMP\fp_fail.json"
# 文件内容: {"video_id":123,"error":"帧无法读取","retryable":true}
```

## 其他接口

| 请求 | 作用 |
|---|---|
| GET `?action=stats&id=2` | 统计（含 highlights 高光数） |
| POST `action=clean-tmp` | 清理 done 视频的临时帧文件 |

## 常见问题

- **`database is locked`**：有另一个扫描进程（Web GUI 或 CLI）正在写库。等它结束再重试，或先停掉那个进程。
- **中文乱码**：PowerShell 控制台显示问题，实际 JSON 是 UTF-8。写文件再 `[System.IO.File]::ReadAllText` 读取即可正常。
- **`pick` 很慢**：正常，要递归扫 F 盘大目录。别用默认 curl 超时。
- **帧文件被清理**：`data/tmp/frames/` 是临时的，用户可能手动清空。重新 `pick` 即可重抽。
- **batch 建议**：每轮 5 条，处理完再领下一轮；单轮结束时务必让所有 `analyzing` 收尾。
