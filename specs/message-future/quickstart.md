# 快速开始：消息异步任务 Future 模式

**Feature**: `message-future`
**目标读者**: 开发者
**预计阅读时间**: 10 分钟

## 概述

本功能提供类似 Java Future 的异步任务管理能力，基于 Symfony Messenger，支持数据库和 Redis 两种存储后端自动适配。

**核心能力**:
- ✅ 提交异步任务，立即返回 Future 对象
- ✅ 查询任务状态（PENDING/RUNNING/COMPLETED/FAILED/CANCELLED）
- ✅ 阻塞或非阻塞获取执行结果
- ✅ 取消未执行的任务
- ✅ 根据配置自动选择 Doctrine 或 Redis 存储

---

## 安装

### 依赖要求

- PHP 8.1+
- Symfony 6.x 或 7.x
- Doctrine ORM（数据库模式）或 Redis 客户端（Redis 模式）

### 配置环境变量

在 `.env` 文件中配置消息队列传输方式：

**数据库模式**:
```env
MESSENGER_TRANSPORT_DSN=doctrine://default
```

**Redis 模式**:
```env
MESSENGER_TRANSPORT_DSN=redis://localhost:6379/messages
```

---

## 基础用法

### 1. 提交异步任务

```php
use App\Service\AsyncTaskService;
use App\Message\SendEmailMessage;

class UserController
{
    public function __construct(
        private AsyncTaskService $asyncTaskService
    ) {}

    public function sendWelcomeEmail(string $email): JsonResponse
    {
        // 创建业务消息
        $message = new SendEmailMessage($email, 'Welcome!');

        // 提交异步任务，立即返回 Future
        $future = $this->asyncTaskService->submit($message);

        // 返回任务 ID 给前端
        return $this->json([
            'taskId' => $future->getTaskId(),
            'message' => 'Email task submitted'
        ]);
    }
}
```

### 2. 查询任务状态

```php
public function checkTaskStatus(string $taskId): JsonResponse
{
    // 根据 taskId 重建 Future 对象
    $future = $this->asyncTaskService->getFuture($taskId);

    return $this->json([
        'taskId' => $taskId,
        'status' => $future->getStatus()->value,
        'done' => $future->isDone(),
        'success' => $future->isSuccess(),
    ]);
}
```

### 3. 阻塞等待结果

```php
public function waitForResult(string $taskId): JsonResponse
{
    $future = $this->asyncTaskService->getFuture($taskId);

    try {
        // 阻塞等待，最多 30 秒
        $result = $future->get(timeoutSeconds: 30);

        return $this->json([
            'status' => 'completed',
            'result' => $result,
        ]);
    } catch (TimeoutException $e) {
        return $this->json([
            'status' => 'timeout',
            'message' => 'Task did not complete within 30 seconds'
        ], 408);
    } catch (\Throwable $e) {
        return $this->json([
            'status' => 'failed',
            'error' => $e->getMessage()
        ], 500);
    }
}
```

### 4. 非阻塞获取结果

```php
public function getResultNonBlocking(string $taskId): JsonResponse
{
    $future = $this->asyncTaskService->getFuture($taskId);

    try {
        $result = $future->getNonBlocking();

        return $this->json([
            'status' => 'completed',
            'result' => $result
        ]);
    } catch (TaskNotCompletedException $e) {
        return $this->json([
            'status' => 'pending',
            'message' => 'Task is still running'
        ], 202);
    }
}
```

### 5. 取消任务

```php
public function cancelTask(string $taskId): JsonResponse
{
    $future = $this->asyncTaskService->getFuture($taskId);

    $cancelled = $future->cancel();

    return $this->json([
        'taskId' => $taskId,
        'cancelled' => $cancelled,
        'message' => $cancelled ? 'Task cancelled' : 'Task already running or completed'
    ]);
}
```

---

## 高级用法

### 处理任务异常

```php
$future = $this->asyncTaskService->submit($message);

try {
    $result = $future->get(10);
} catch (RuntimeException $e) {
    // 业务异常（任务执行失败）
    logger->error('Task failed', ['exception' => $e]);
} catch (TaskCancelledException $e) {
    // 任务被取消
    logger->info('Task was cancelled');
} catch (TimeoutException $e) {
    // 等待超时
    logger->warning('Task timeout');
}
```

### 轮询间隔配置

默认轮询间隔为 100ms，可通过构造函数配置：

```yaml
# config/services.yaml
services:
    App\Service\TaskFuture:
        arguments:
            $pollingIntervalMs: 200  # 200ms 轮询间隔
```

### 任务过期清理

使用 Symfony Console 命令清理过期任务（建议通过 Cron 定时执行）：

```bash
# 清理 24 小时前完成的任务
php bin/console app:clean-expired-tasks

# Cron 配置示例（每小时执行）
0 * * * * /path/to/bin/console app:clean-expired-tasks >> /var/log/task-cleanup.log 2>&1
```

---

## 完整示例：生成报表

```php
// 1. 定义业务消息
class GenerateReportMessage
{
    public function __construct(
        public readonly string $reportType,
        public readonly \DateTimeImmutable $startDate,
        public readonly \DateTimeImmutable $endDate
    ) {}
}

// 2. 创建消息处理器
#[AsMessageHandler]
class GenerateReportHandler
{
    public function __invoke(GenerateReportMessage $message): string
    {
        // 生成报表逻辑
        $filePath = $this->reportGenerator->generate(
            $message->reportType,
            $message->startDate,
            $message->endDate
        );

        return $filePath;  // 返回值会被保存到任务结果
    }
}

// 3. 控制器：提交报表生成任务
#[Route('/reports/generate', methods: ['POST'])]
public function generateReport(Request $request): JsonResponse
{
    $data = json_decode($request->getContent(), true);

    $message = new GenerateReportMessage(
        $data['type'],
        new \DateTimeImmutable($data['startDate']),
        new \DateTimeImmutable($data['endDate'])
    );

    $future = $this->asyncTaskService->submit($message);

    return $this->json([
        'taskId' => $future->getTaskId(),
        'statusUrl' => $this->generateUrl('report_status', ['taskId' => $future->getTaskId()])
    ], 202);
}

// 4. 控制器：查询报表生成状态
#[Route('/reports/{taskId}/status', methods: ['GET'])]
public function reportStatus(string $taskId): JsonResponse
{
    $future = $this->asyncTaskService->getFuture($taskId);

    if (!$future->isDone()) {
        return $this->json([
            'status' => $future->getStatus()->value,
            'progress' => 'generating'
        ], 200);
    }

    if ($future->isSuccess()) {
        $filePath = $future->getNonBlocking();

        return $this->json([
            'status' => 'completed',
            'downloadUrl' => $this->generateUrl('report_download', ['file' => basename($filePath)])
        ], 200);
    }

    // 任务失败
    $exception = $future->getException();
    return $this->json([
        'status' => 'failed',
        'error' => $exception?->getMessage()
    ], 500);
}
```

---

## 前端集成示例

### JavaScript（轮询模式）

```javascript
// 提交任务
async function submitReport(reportData) {
  const response = await fetch('/reports/generate', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(reportData)
  });

  const { taskId, statusUrl } = await response.json();

  // 轮询检查状态
  return pollTaskStatus(statusUrl);
}

// 轮询任务状态
async function pollTaskStatus(statusUrl, maxAttempts = 60) {
  for (let i = 0; i < maxAttempts; i++) {
    const response = await fetch(statusUrl);
    const data = await response.json();

    if (data.status === 'completed') {
      return data.downloadUrl;
    }

    if (data.status === 'failed') {
      throw new Error(data.error);
    }

    // 等待 1 秒后重试
    await new Promise(resolve => setTimeout(resolve, 1000));
  }

  throw new Error('Task timeout');
}

// 使用示例
submitReport({ type: 'sales', startDate: '2025-11-01', endDate: '2025-11-30' })
  .then(downloadUrl => {
    console.log('Report ready:', downloadUrl);
    window.location.href = downloadUrl;
  })
  .catch(error => console.error('Report generation failed:', error));
```

---

## 故障排查

### 任务一直处于 PENDING 状态

**原因**: Worker 进程未运行或队列堵塞

**解决**:
```bash
# 启动 Messenger Worker
php bin/console messenger:consume async -vv

# 检查队列积压
php bin/console messenger:stats
```

### 任务状态查询返回 null

**原因**: 任务已过期（默认 24 小时后清理）

**解决**: 减少任务保留时间或增加清理频率

### 存储连接失败

**数据库模式**:
```bash
# 检查数据库连接
php bin/console doctrine:query:sql "SELECT 1"
```

**Redis 模式**:
```bash
# 检查 Redis 连接
redis-cli -h localhost -p 6379 ping
```

---

## 性能优化建议

1. **使用 Redis 模式**: 查询性能比数据库高 5-10 倍
2. **调整轮询间隔**: 低优先级任务可设置为 500ms 减少负载
3. **Worker 并发**: 启动多个 Worker 进程提高吞吐
   ```bash
   # 启动 4 个 Worker
   for i in {1..4}; do
     php bin/console messenger:consume async &
   done
   ```
4. **数据库索引**: 确保 `task_id`、`status`、`completed_at` 有索引
5. **Redis 内存**: 监控 Redis 内存使用，设置 `maxmemory-policy volatile-ttl`

---

## 下一步

- 📖 阅读 [数据模型文档](./data-model.md) 了解存储结构
- 📖 阅读 [服务契约文档](./contracts/) 了解接口详情
- 🛠️ 查看 [tasks.md](./tasks.md) 了解实施计划

---

**版本**: 1.0
**更新日期**: 2025-12-01
