# Flowerpot 项目简报（AI 必读）

1. 先读 `SPEC.md`（代码镜像，v2.4.0 起 SPEC=真相），实现偏差看附录 C。
2. 已交付功能以 SPEC 6.1/6.2 为准，**别凭记忆判断"做没做过"，先查代码再说话**。
3. 入口 `http://localhost/flowerpot/`（Laragon Apache）；扫描走 Web GUI（/scan）或 `php cli/scan.php --project=N`，CLI/Web 共用节流（data/ai_throttle.json）与 `scan_process_one_video()`。
