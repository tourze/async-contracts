# 技术研究：消息异步任务 Future 模式

**Feature**: `message-future`
**日期**: 2025-12-01
**目的**: 解决实施方案中的技术不确定性

## 研究目标

1. 确定存储后端适配层的架构模式
2. 选择任务结果和异常的序列化方案
3. 设计轮询等待的实现策略
4. 规划任务过期清理机制

---

## 决策 1：存储后端适配层架构

### 选定方案：策略模式 + 工厂模式

**实现方式**：
- 定义 `TaskStorageInterface` 接口，包含 `save()`、`load()`、`updateStatus()`、`delete()` 方法
- 提供两个实现类：
  - `DoctrineTaskStorage`：基于 Doctrine ORM 的数据库存储
  - `RedisTaskStorage`：基于 Redis 客户端的键值存储
- 使用 `TaskStorageFactory` 根据 `MESSENGER_TRANSPORT_DSN` 创建对应实现
- 通过 Symfony DI 容器自动注入正确的存储实现

**技术细节**：
```php
// 伪代码示例
interface TaskStorageInterface {
    public function save(string $taskId, TaskData $data): void;
    public function load(string $taskId): ?TaskData;
    public function updateStatus(string $taskId, TaskStatus $status): void;
    public function delete(string $taskId): void;
}

class TaskStorageFactory {
    public function create(string $dsn): TaskStorageInterface {
        if (str_starts_with($dsn, 'doctrine://')) {
            return new DoctrineTaskStorage(...);
        }
        if (str_starts_with($dsn, 'redis://')) {
            return new RedisTaskStorage(...);
        }
        throw new InvalidArgumentException('Unsupported DSN');
    }
}
```

**理由**：
- 策略模式解耦存储实现与业务逻辑
- 工厂模式实现运行时动态选择
- 符合开闭原则，易于扩展（未来可添加 Memcached、文件系统等）
- Symfony DI 容器确保单例和依赖注入

**替代方案**：
- **方案 A**：硬编码 if-else 判断 - 违反单一职责原则，难以测试
- **方案 B**：使用 Symfony Messenger 的 Transport 抽象 - 过度依赖 Messenger 内部实现，耦合度高

---

## 决策 2：序列化方案

### 选定方案：Symfony Serializer + JSON 格式

**实现方式**：
- 使用 `Symfony\Component\Serializer\Serializer` 组件
- 任务结果序列化为 JSON 格式存储
- 异常信息提取为结构化数据（类名、消息、堆栈、code）后序列化
- 反序列化时重建异常对象（保留原始类型信息）

**技术细节**：
```php
// 异常序列化
$exceptionData = [
    'class' => get_class($exception),
    'message' => $exception->getMessage(),
    'code' => $exception->getCode(),
    'file' => $exception->getFile(),
    'line' => $exception->getLine(),
    'trace' => $exception->getTraceAsString(),
];
$serialized = json_encode($exceptionData);

// 异常反序列化
$data = json_decode($serialized, true);
$exceptionClass = $data['class'];
if (class_exists($exceptionClass)) {
    throw new $exceptionClass($data['message'], $data['code']);
} else {
    throw new RuntimeException($data['message']);
}
```

**理由**：
- JSON 格式人类可读，便于调试
- Symfony Serializer 支持类型提示和自定义 Normalizer
- 异常信息保留完整堆栈，便于问题定位
- 兼容 Redis（字符串值）和数据库（TEXT 字段）

**替代方案**：
- **方案 A**：PHP 原生 `serialize()`/`unserialize()` - 存在安全风险（对象注入攻击），不可跨语言
- **方案 B**：MessagePack/Igbinary - 性能更好但不可读，调试困难
- **方案 C**：Protobuf - 需要schema 定义，过于复杂

**限制与风险**：
- 非标准异常类（自定义 Exception）在反序列化时可能丢失部分上下文
- 解决：对关键业务异常提供专门的 Normalizer

---

## 决策 3：轮询等待实现策略

### 选定方案：微秒级 usleep + 状态轮询

**实现方式**：
- 默认轮询间隔 100ms（100,000 微秒）
- 使用 `usleep()` 实现阻塞等待
- 每次轮询查询任务状态，检查是否完成/失败/取消
- 超时时间通过参数传入（秒），内部转换为微秒计数

**技术细节**：
```php
public function get(?int $timeoutSeconds = null): mixed {
    $start = microtime(true);
    $timeoutMicros = $timeoutSeconds ? $timeoutSeconds * 1_000_000 : null;

    while (true) {
        $status = $this->storage->load($this->taskId)?->getStatus();

        if ($status === TaskStatus::COMPLETED) {
            return $this->storage->load($this->taskId)->getResult();
        }

        if ($status === TaskStatus::FAILED) {
            throw $this->storage->load($this->taskId)->getException();
        }

        if ($status === TaskStatus::CANCELLED) {
            throw new TaskCancelledException();
        }

        if ($timeoutMicros && (microtime(true) - $start) * 1_000_000 > $timeoutMicros) {
            throw new TimeoutException();
        }

        usleep(100_000); // 100ms
    }
}
```

**性能优化**：
- 轮询间隔可配置（通过构造函数参数或环境变量）
- 可选：使用指数退避策略（初始 50ms，最大 500ms）减少负载
- 数据库模式：考虑在状态字段添加索引加速查询

**理由**：
- `usleep()` 是 PHP 标准函数，无额外依赖
- 100ms 间隔在大多数场景下性能和响应性平衡良好
- 简单易测试，不依赖特定平台特性

**替代方案**：
- **方案 A**：Redis BLPOP - 需要额外的完成通知队列，架构复杂，且仅适用于 Redis 模式
- **方案 B**：Swoole/ReactPHP 异步 I/O - 引入异步编程复杂性，与传统 Symfony 应用兼容性差
- **方案 C**：WebSocket/Mercure 推送 - 超出轮询范围，属于实时推送（范围外）

**限制与风险**：
- 高并发场景下轮询会增加存储负载
- 缓解：在 Future 对象内部缓存最近一次查询结果（带短期 TTL）
- Redis 模式优于数据库模式（内存查询更快）

---

## 决策 4：任务过期清理机制

### 选定方案：Symfony Messenger + Cron 调度

**实现方式**：
- 创建 `CleanExpiredTasksCommand` Symfony Console 命令
- 命令逻辑：
  1. 查询所有完成时间 + 24 小时 < 当前时间的任务
  2. 批量删除（数据库使用 DELETE ... WHERE，Redis 使用 DEL 批量键）
- 通过系统 Cron 或 Kubernetes CronJob 定期执行（如每小时一次）

**技术细节**：
```php
class CleanExpiredTasksCommand extends Command {
    public function execute(InputInterface $input, OutputInterface $output): int {
        $ttl = 24 * 3600; // 24 小时
        $expiredTime = new \DateTimeImmutable("-{$ttl} seconds");

        // 数据库模式
        $count = $this->taskRepository->deleteExpiredTasks($expiredTime);

        // 或 Redis 模式
        $keys = $this->redis->keys('task:*:status');
        foreach ($keys as $key) {
            $taskId = /* 从 key 提取 */;
            $completedAt = $this->redis->get("task:{$taskId}:completed_at");
            if ($completedAt && $completedAt < $expiredTime->getTimestamp()) {
                $this->redis->del("task:{$taskId}:*");
            }
        }

        $output->writeln("Cleaned {$count} expired tasks");
        return Command::SUCCESS;
    }
}
```

**Cron 配置示例**：
```cron
0 * * * * /path/to/bin/console app:clean-expired-tasks >> /var/log/task-cleanup.log 2>&1
```

**理由**：
- Symfony Console 命令易于测试和手动触发
- Cron 是成熟的调度方案，无需额外依赖
- 批量删除减少数据库/Redis 负载

**替代方案**：
- **方案 A**：Redis TTL 自动过期 - 仅适用于 Redis 模式，数据库无法使用
- **方案 B**：Symfony Messenger Scheduled Message - 需要每个任务创建一个延迟消息，消息队列负担大
- **方案 C**：数据库触发器 - 过于底层，违反"实体是唯一事实来源"原则

**限制与风险**：
- Cron 执行失败可能导致任务堆积
- 缓解：监控命令执行日志，设置告警
- 数据库模式：DELETE 大量记录可能锁表，考虑分批删除（每次 1000 条）

---

## 决策 5：Symfony Messenger 集成方式

### 选定方案：创建自定义 AsyncTaskMessage 消息类

**实现方式**：
- 定义 `AsyncTaskMessage` 类，包含 `taskId` 和原始业务消息（payload）
- `AsyncTaskService::submit()` 方法：
  1. 生成任务 ID（UUID v4）
  2. 创建任务记录（状态 PENDING）
  3. 包装原始消息为 `AsyncTaskMessage`
  4. 通过 `MessageBusInterface` 分发到队列
  5. 返回 `TaskFuture` 对象
- 创建 `AsyncTaskHandler` 处理消息：
  1. 更新状态为 RUNNING
  2. 提取并处理原始业务消息（调用对应 Handler）
  3. 捕获结果或异常，更新任务记录
  4. 更新状态为 COMPLETED/FAILED

**技术细节**：
```php
class AsyncTaskMessage {
    public function __construct(
        public readonly string $taskId,
        public readonly object $payload,
    ) {}
}

#[AsMessageHandler]
class AsyncTaskHandler {
    public function __invoke(AsyncTaskMessage $message): void {
        $this->storage->updateStatus($message->taskId, TaskStatus::RUNNING);

        try {
            // 分发原始消息到对应 Handler
            $result = $this->messageBus->dispatch($message->payload);
            $this->storage->save($message->taskId, TaskData::completed($result));
        } catch (\Throwable $e) {
            $this->storage->save($message->taskId, TaskData::failed($e));
        }
    }
}
```

**理由**：
- 复用 Symfony Messenger 的传输、重试、失败处理机制
- `AsyncTaskMessage` 作为包装层，业务消息保持原样
- 统一的 Handler 逻辑，易于扩展和测试

**限制**：
- 原始业务消息必须可序列化（Messenger 要求）
- 嵌套分发（Handler 内部再次 dispatch）可能增加复杂度
- 解决：优先使用同步处理业务逻辑，仅异步记录状态

---

## 决策 6：任务 ID 生成策略

### 选定方案：Symfony UUID v4

**实现方式**：
```php
use Symfony\Component\Uid\Uuid;

$taskId = Uuid::v4()->toRfc4122();
```

**理由**：
- UUID v4 全局唯一，碰撞概率极低（~0）
- Symfony Uid 组件是标准依赖，无需额外安装
- RFC 4122 格式字符串（36 字符），兼容数据库和 Redis

**替代方案**：
- **方案 A**：`uniqid()` - 非加密安全，多进程可能重复
- **方案 B**：Snowflake ID - 过度设计，需要集中式 ID 生成服务
- **方案 C**：自增 ID - 数据库依赖，Redis 模式无法使用

---

## 技术栈汇总

| 技术选型 | 方案 | 依赖 |
|---------|------|------|
| 语言/版本 | PHP 8.1+ | N/A |
| Web 框架 | Symfony 6.x/7.x | symfony/framework-bundle |
| 消息队列 | Symfony Messenger | symfony/messenger |
| 数据库存储 | Doctrine ORM | doctrine/orm |
| Redis 存储 | predis/predis 或 phpredis | predis/predis |
| 序列化 | Symfony Serializer + JSON | symfony/serializer |
| UUID 生成 | Symfony Uid | symfony/uid |
| 任务清理 | Symfony Console + Cron | symfony/console |
| 测试框架 | PHPUnit | phpunit/phpunit |
| 静态分析 | PHPStan Level 8 | phpstan/phpstan |

---

## 性能估算与验证方法

### 提交性能（SC-001/SC-002/SC-003）
- **目标**：提交耗时 < 50ms，数据库模式 1000 TPS，Redis 模式 5000 TPS
- **验证方法**：
  - 使用 Symfony Profiler 或 Blackfire.io 测量 `AsyncTaskService::submit()` 耗时
  - 压测工具：Apache Bench 或 wrk，并发 100/1000 请求
  - 监控数据库连接池使用率、Redis CPU/内存

### 状态查询性能（SC-004）
- **目标**：查询耗时 < 10ms
- **验证方法**：
  - Profiler 测量 `TaskStorage::load()` 耗时
  - 数据库：EXPLAIN 查询计划，确保使用 taskId 索引
  - Redis：使用 `redis-cli --latency` 测量延迟

### 轮询开销（SC-005）
- **目标**：每秒轮询 ≤ 10 次（100ms 间隔）
- **验证方法**：
  - 单元测试模拟 1 秒等待，统计 `storage->load()` 调用次数
  - 预期：~10 次调用

### 任务执行延迟（SC-006）
- **目标**：90% 任务在 5 秒内开始执行
- **验证方法**：
  - 提交 100 个任务，记录 `submittedAt` 和 `startedAt`
  - 计算 P90 延迟
  - 依赖 Messenger Worker 正常运行

---

## 风险与缓解

| 风险 | 影响 | 缓解措施 |
|------|------|---------|
| 轮询导致存储负载过高 | 性能下降 | Future 内部缓存 + 可配置轮询间隔 |
| 序列化异常丢失上下文 | 调试困难 | 为关键异常提供自定义 Normalizer |
| 任务清理失败导致存储膨胀 | 磁盘/内存占用 | 监控清理命令执行，设置告警 |
| DSN 解析错误导致启动失败 | 服务不可用 | 启动时验证 DSN 格式，提供明确错误消息 |
| 并发修改任务状态导致冲突 | 数据不一致 | 使用数据库乐观锁（version 字段）或 Redis SET NX |

---

## 后续行动

1. ✅ 完成技术决策
2. ➡️ 进入 Phase 1：设计数据模型（data-model.md）和服务契约（contracts/*.md）
3. ➡️ 更新 plan.md 填充技术背景和项目结构
4. ➡️ 生成 tasks.md 进入实施阶段

---

**更新日期**: 2025-12-01
**状态**: 已完成，可进入 Phase 1
