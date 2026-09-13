# 考前身份核验（证件 + 人脸）

## 功能概述

学生进入考试前必须完成身份核验：上传证件照片并通过摄像头采集人脸照片，
系统自动比对后将结果分为三态：

| 状态 | 说明 | 后续流程 |
|---|---|---|
| `passed` 通过 | 相似度 ≥ 通过阈值（默认 85） | 可直接进入考试 |
| `suspected` 疑似 | 相似度介于两个阈值之间（默认 45–85） | 等待监考老师人工确认 |
| `failed` 失败 | 相似度低于疑似阈值（默认 45） | 可重新核验（每场最多 3 次） |

疑似记录由监考老师（教师/管理员）在「监考核验」页面人工确认：
确认通过 → `passed`；驳回 → `failed`（需填写原因）。

## 隐私与数据保留

- 核验材料（证件照、人脸照）**仅用于当次考试核验**。
- 照片存储于私有目录（`storage/app/private/identity/...`），**不作为静态文件公开**，
  只能经由授权接口 `/api/identity/verifications/{id}/photo/{type}` 访问。
- 每次查看照片都会写入审计日志（`identity_verification_audits`）。
- 证件号**脱敏存储**（仅保留前 3 位 + 后 2 位），完整号码只保留 HMAC 哈希用于一致性校验。
- 材料自提交起保留 `IDENTITY_RETENTION_DAYS` 天（默认 7 天），到期后自动清理：
  - 定时任务：`php artisan identity:purge`（已在 `routes/console.php` 注册每日调度）；
  - 兜底：访问详情/照片接口时发现已过期会即时清理。
- 清理后照片文件被删除、数据库路径置空，任何人（含管理员）都无法再查看，
  仅保留脱敏元数据与审计日志用于追溯。

## 权限模型

| 操作 | 学生 | 教师 | 管理员 |
|---|---|---|---|
| 提交/查询自己的核验 | ✔ | ✔ | ✔ |
| 查看核验列表 | — | 仅自己创建的试卷 | 全部 |
| 查看核验照片（审计） | — | 仅自己创建的试卷 | 全部 |
| 人工审核疑似记录 | — | 仅自己创建的试卷 | 全部 |
| 查看审计日志 | — | 仅自己创建的试卷 | 全部 |

## API 一览

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | `/api/identity/exams/{paper}/verification` | 考生查询当前核验状态 |
| POST | `/api/identity/exams/{paper}/verification` | 考生提交核验（multipart：id_name、id_number、id_photo、face_photo） |
| GET | `/api/identity/verifications` | 监考端列表（status / exam_paper_id 过滤） |
| GET | `/api/identity/verifications/{id}` | 监考端详情（脱敏） |
| GET | `/api/identity/verifications/{id}/photo/{id\|face}` | 授权查看照片（写审计） |
| POST | `/api/identity/verifications/{id}/review` | 人工审核（action: approve/reject, note） |
| GET | `/api/identity/verifications/{id}/audits` | 审计日志 |

`POST /api/exams/{paper}/start` 会强制校验：未通过核验时返回 403 +
`code: VERIFICATION_REQUIRED / VERIFICATION_SUSPECTED / VERIFICATION_FAILED`，
前端据此跳转到核验页。

## 配置项（docker-compose backend environment）

| 变量 | 默认 | 说明 |
|---|---|---|
| `IDENTITY_RETENTION_DAYS` | 7 | 材料保留天数 |
| `IDENTITY_MAX_ATTEMPTS` | 3 | 每场考试最大核验次数 |
| `FACE_COMPARE_PASS_THRESHOLD` | 85 | 通过阈值（百分制） |
| `FACE_COMPARE_SUSPECT_THRESHOLD` | 45 | 疑似阈值（百分制） |

## 人脸比对实现说明

`app/Services/FaceCompareService` 当前使用内置的轻量级图像相似度算法
（16×16 灰度均值哈希 + 汉明距离），便于本地与演示环境确定性运行。
**生产环境应替换为合规的人脸核身服务**（如云厂商人脸比对 API），
仅需修改该类的 `compare()` 方法，阈值判定与状态流转逻辑保持不变。

## 数据库表

- `identity_verifications`：核验记录（脱敏证件号、相似度、状态、审核信息、保留期/清理时间）。
- `identity_verification_audits`：审计日志（提交、查看照片、人工审核、自动清理）。

建表 SQL 位于 `docker-compose.yml` 的 `db-init` 段（幂等，老库启动时自动补表）。

## 手动清理与验证

```bash
# 手动触发一次到期清理
docker compose exec backend php artisan identity:purge

# 验证未授权访问被拒绝
curl -i http://localhost:9000/api/identity/verifications
```
