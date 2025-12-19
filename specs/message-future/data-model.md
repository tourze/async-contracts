# 数据模型：消息异步任务 Future 模式

**Feature**: `message-future`
**日期**: 2025-12-01
**关联**: spec.md, research.md

## 核心实体

### 1. TaskData（任务数据传输对象）

**用途**: 在存储层和业务层之间传递任务完整信息

**属性**:
| 字段名 | 类型 | 必填 | 说明 | 约束 |
|--------|------|------|------|------|
| taskId | string | ✅ | 任务唯一标识 | UUID v4 格式（36 字符） |
| status | TaskStatus | ✅ | 任务当前状态 | 枚举值（PENDING/RUNNING/COMPLETED/FAILED/CANCELLED） |
| payload | ?string | ❌ | 序列化的业务消息 | JSON 格式，可为 null |
| result | ?string | ❌ | 序列化的执行结果 | JSON 格式，仅 COMPLETED 状态有值 |
| error | ?string | ❌ | 序列化的异常信息 | JSON 格式，仅 FAILED 状态有值 |
| submittedAt | \DateTimeImmutable | ✅ | 任务提交时间 | ISO 8601 格式 |
| startedAt | ?\DateTimeImmutable | ❌ | 任务开始执行时间 | ISO 8601 格式，RUNNING/COMPLETED/FAILED 状态有值 |
| completedAt | ?\DateTimeImmutable | ❌ | 任务完成时间 | ISO 8601 格式，COMPLETED/FAILED/CANCELLED 状态有值 |

**状态转换规则**:
```
PENDING → RUNNING → COMPLETED
              ↓
            FAILED

PENDING → CANCELLED
```

**验证规则**:
- `taskId` 必须符合 UUID v4 格式
- `status` 必须是有效枚举值
- `COMPLETED` 状态时 `result` 不能为 null
- `FAILED` 状态时 `error` 不能为 null
- `CANCELLED` 状态时 `result` 和 `error` 必须为 null
- `completedAt` ≥ `startedAt` ≥ `submittedAt`

---

### 2. TaskStatus（任务状态枚举）

**用途**: 标识任务当前所处的生命周期阶段

**枚举值**:
| 值 | 说明 | 可转换至 |
|----|------|---------|
| PENDING | 已提交，等待 Worker 处理 | RUNNING, CANCELLED |
| RUNNING | Worker 正在执行 | COMPLETED, FAILED |
| COMPLETED | 执行成功，结果已保存 | *(终态)* |
| FAILED | 执行失败，异常已保存 | *(终态)* |
| CANCELLED | 任务已取消，未执行 | *(终态)* |

**实现建议**:
- PHP: `enum TaskStatus: string { case PENDING = 'pending'; ... }`
- 数据库: VARCHAR(20) 或 ENUM 类型
- Redis: 字符串值（如 `task:123:status` → `"running"`）

---

### 3. ExceptionData（异常信息结构）

**用途**: 序列化异常对象为结构化数据

**属性**:
| 字段名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| class | string | ✅ | 异常类全限定名（如 `RuntimeException`） |
| message | string | ✅ | 异常消息 |
| code | int | ✅ | 异常代码 |
| file | string | ✅ | 异常发生文件路径 |
| line | int | ✅ | 异常发生行号 |
| trace | string | ✅ | 堆栈跟踪（字符串格式） |
| previous | ?ExceptionData | ❌ | 前置异常（递归结构） |

**序列化示例**:
```json
{
  "class": "RuntimeException",
  "message": "Task execution failed",
  "code": 500,
  "file": "/path/to/Handler.php",
  "line": 42,
  "trace": "#0 /path/to/file.php(10): ...",
  "previous": null
}
```

---

## 数据库模式（Doctrine 实现）

### 表结构：async_tasks

| 列名 | 数据类型 | 约束 | 索引 | 说明 |
|------|---------|------|------|------|
| task_id | VARCHAR(36) | PRIMARY KEY | ✅ 主键 | UUID v4 |
| status | VARCHAR(20) | NOT NULL | ✅ 普通索引 | 任务状态 |
| payload | TEXT | NULL | - | 业务消息 JSON |
| result | TEXT | NULL | - | 执行结果 JSON |
| error | TEXT | NULL | - | 异常信息 JSON |
| submitted_at | DATETIME | NOT NULL | - | 提交时间（UTC） |
| started_at | DATETIME | NULL | - | 开始时间（UTC） |
| completed_at | DATETIME | NULL | ✅ 普通索引 | 完成时间（UTC），用于过期清理 |

**索引策略**:
- `PRIMARY KEY (task_id)`: 主键索引，支持快速查询和更新
- `INDEX idx_status (status)`: 支持按状态过滤（如查询所有 PENDING 任务）
- `INDEX idx_completed_at (completed_at)`: 支持过期任务清理查询

**Doctrine 实体映射示例**:
```php
#[ORM\Entity]
#[ORM\Table(name: 'async_tasks')]
#[ORM\Index(columns: ['status'], name: 'idx_status')]
#[ORM\Index(columns: ['completed_at'], name: 'idx_completed_at')]
class AsyncTask {
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $taskId;

    #[ORM\Column(type: 'string', length: 20)]
    private string $status;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $payload = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $result = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $error = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $submittedAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;
}
```

---

## Redis 模式键结构

### 键命名规范

**前缀**: `task:{taskId}:`

**键列表**:
| 键名 | 类型 | TTL | 说明 | 示例值 |
|------|------|-----|------|--------|
| `task:{taskId}:status` | STRING | 24h | 任务状态 | `"running"` |
| `task:{taskId}:payload` | STRING | 24h | 业务消息 JSON | `"{\"type\":\"email\", ...}"` |
| `task:{taskId}:result` | STRING | 24h | 执行结果 JSON | `"{\"sent\":true}"` |
| `task:{taskId}:error` | STRING | 24h | 异常信息 JSON | `"{\"class\":\"RuntimeException\", ...}"` |
| `task:{taskId}:submitted_at` | STRING | 24h | 提交时间戳（秒） | `"1701388800"` |
| `task:{taskId}:started_at` | STRING | 24h | 开始时间戳（秒） | `"1701388805"` |
| `task:{taskId}:completed_at` | STRING | 24h | 完成时间戳（秒） | `"1701388810"` |

**TTL 策略**:
- 所有键设置 24 小时过期（`EXPIRE key 86400`）
- 任务完成后重新设置 TTL 确保一致过期
- 使用 Redis 原生过期机制，无需额外清理命令

**原子性保证**:
- 使用 `MULTI`/`EXEC` 事务批量设置键
- 状态更新使用 `SET key value NX` 防止并发覆盖

**示例 Redis 命令**:
```redis
# 创建任务
MULTI
SET task:abc-123:status "pending"
SET task:abc-123:payload "{...}"
SET task:abc-123:submitted_at "1701388800"
EXPIRE task:abc-123:status 86400
EXPIRE task:abc-123:payload 86400
EXPIRE task:abc-123:submitted_at 86400
EXEC

# 查询任务状态
GET task:abc-123:status

# 更新为 RUNNING
SET task:abc-123:status "running"
SET task:abc-123:started_at "1701388805"

# 删除任务（手动清理）
DEL task:abc-123:status task:abc-123:payload task:abc-123:result ...
```

---

## 数据一致性保证

### 数据库模式

**问题**: 并发更新任务状态可能导致覆盖

**解决方案**: 乐观锁
```php
#[ORM\Column(type: 'integer')]
#[ORM\Version]
private int $version = 0;
```

**更新逻辑**:
```php
try {
    $task->setStatus(TaskStatus::RUNNING);
    $entityManager->flush(); // 自动检查 version 字段
} catch (OptimisticLockException $e) {
    // 版本冲突，任务已被其他进程更新
    throw new ConcurrentModificationException();
}
```

### Redis 模式

**问题**: 多个进程同时修改状态

**解决方案**: SET NX（仅在键不存在时设置）
```php
$success = $redis->set("task:{$taskId}:lock", "1", ['NX', 'EX' => 60]);
if (!$success) {
    throw new ConcurrentModificationException();
}

// 执行状态更新
$redis->set("task:{$taskId}:status", "running");

// 释放锁
$redis->del("task:{$taskId}:lock");
```

---

## 数据迁移与兼容性

### 数据库 Schema 创建

**开发环境**:
```bash
bin/console doctrine:schema:update --force
```

**生产环境**:
运维团队执行 DDL SQL（从实体类导出）：
```bash
bin/console doctrine:schema:update --dump-sql > schema.sql
```

### Redis 键无迁移

Redis 键基于约定命名，无需迁移脚本。旧版本任务过期后自动清理。

---

## 性能考量

### 数据库

**查询优化**:
- 按 `task_id` 查询使用主键索引（O(log n)）
- 按 `status` 过滤使用普通索引
- 过期清理批量删除（每次 1000 条）避免锁表

**容量规划**:
- 假设每天 100 万任务，每条 1KB，24 小时保留 = ~1GB/天
- 建议定期归档历史任务到冷存储

### Redis

**内存优化**:
- 每个任务约 7 个键，每键约 100-500 字节
- 100 万任务 × 7 键 × 300 字节 = ~2.1GB
- 使用 TTL 自动清理，内存占用稳定

**性能**:
- GET/SET 延迟 < 1ms
- 支持百万级并发查询
- 使用 Redis Cluster 水平扩展

---

## 测试数据

### Fixture 示例

**PENDING 任务**:
```json
{
  "taskId": "550e8400-e29b-41d4-a716-446655440000",
  "status": "pending",
  "payload": "{\"type\":\"email\",\"to\":\"test@example.com\"}",
  "submittedAt": "2025-12-01T10:00:00Z"
}
```

**COMPLETED 任务**:
```json
{
  "taskId": "550e8400-e29b-41d4-a716-446655440001",
  "status": "completed",
  "payload": "{\"type\":\"report\"}",
  "result": "{\"fileUrl\":\"/reports/2025-12-01.pdf\"}",
  "submittedAt": "2025-12-01T10:00:00Z",
  "startedAt": "2025-12-01T10:00:05Z",
  "completedAt": "2025-12-01T10:00:30Z"
}
```

**FAILED 任务**:
```json
{
  "taskId": "550e8400-e29b-41d4-a716-446655440002",
  "status": "failed",
  "payload": "{\"type\":\"api_call\"}",
  "error": "{\"class\":\"RuntimeException\",\"message\":\"API timeout\",\"code\":500,...}",
  "submittedAt": "2025-12-01T10:00:00Z",
  "startedAt": "2025-12-01T10:00:05Z",
  "completedAt": "2025-12-01T10:00:35Z"
}
```

---

**更新日期**: 2025-12-01
**状态**: 已完成
