# 服务契约：AsyncTaskService

**目的**: 提交异步任务并返回 Future 对象
**优先级**: P1（核心服务）

## 接口定义

### 方法：submit()

**签名**:
```php
public function submit(object $message): FutureInterface
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 | 约束 |
|--------|------|------|------|------|
| $message | object | ✅ | 业务消息对象 | 必须可序列化 |

**输出**:
| 类型 | 说明 |
|------|------|
| FutureInterface | Future 对象，持有任务 ID 和查询方法 |

**行为约束**:
1. 生成唯一任务 ID（UUID v4）
2. 将任务状态初始化为 PENDING
3. 序列化业务消息并保存
4. 分发 `AsyncTaskMessage` 到消息队列
5. 创建并返回 `TaskFuture` 对象
6. **非阻塞**：方法必须在 50ms 内返回

**错误场景**:
| 错误 | 异常类型 | 说明 |
|------|---------|------|
| 消息不可序列化 | `SerializationException` | 业务消息对象包含不可序列化的属性（如闭包） |
| 存储不可用 | `StorageException` | 数据库或 Redis 连接失败 |
| 配置错误 | `ConfigurationException` | `MESSENGER_TRANSPORT_DSN` 未配置或格式错误 |

**测试用例**:

**TC-001**: 提交简单任务
```php
// Given: 简单可序列化消息
$message = new SendEmailMessage('test@example.com', 'Hello');

// When: 提交任务
$future = $service->submit($message);

// Then: 返回 Future 对象，包含有效任务 ID
assertInstanceOf(FutureInterface::class, $future);
assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $future->getTaskId());
```

**TC-002**: 提交后任务已保存
```php
// Given: 任务已提交
$future = $service->submit($message);

// When: 查询存储
$taskData = $storage->load($future->getTaskId());

// Then: 任务状态为 PENDING
assertEquals(TaskStatus::PENDING, $taskData->getStatus());
assertNotNull($taskData->getSubmittedAt());
```

**TC-003**: 消息已分发到队列
```php
// Given: 使用测试消息总线
$messageBus = $this->createMock(MessageBusInterface::class);
$messageBus->expects($this->once())
    ->method('dispatch')
    ->with($this->isInstanceOf(AsyncTaskMessage::class));

// When: 提交任务
$service = new AsyncTaskService($messageBus, $storage);
$service->submit($message);

// Then: dispatch 被调用一次
```

**TC-004**: 处理序列化失败
```php
// Given: 包含闭包的消息（不可序列化）
$message = new class {
    public \Closure $callback;
};

// When/Then: 抛出序列化异常
$this->expectException(SerializationException::class);
$service->submit($message);
```

**TC-005**: 处理存储故障
```php
// Given: 存储不可用
$storage->method('save')->willThrowException(new \RuntimeException('DB down'));

// When/Then: 抛出存储异常
$this->expectException(StorageException::class);
$service->submit($message);
```

---

## 性能契约

- **延迟**: `submit()` 调用耗时 P50 < 20ms，P99 < 50ms
- **吞吐**: 数据库模式支持 1000 TPS，Redis 模式支持 5000 TPS
- **并发**: 支持 100 并发调用无性能下降

**验证方法**:
```php
// 性能测试
$start = hrtime(true);
for ($i = 0; $i < 1000; $i++) {
    $service->submit(new TestMessage($i));
}
$duration = (hrtime(true) - $start) / 1e9;

// 断言：1000 次调用在 1 秒内完成（1000 TPS）
assertLessThan(1.0, $duration);
```

---

**版本**: 1.0
**状态**: Draft
**更新日期**: 2025-12-01
