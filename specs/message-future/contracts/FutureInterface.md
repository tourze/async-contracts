# 服务契约：FutureInterface

**目的**: 提供查询任务状态和获取结果的统一接口
**优先级**: P1（核心接口）

## 接口定义

### 方法：getTaskId()

**签名**:
```php
public function getTaskId(): string
```

**输出**: 任务唯一标识符（UUID v4 字符串）

**行为约束**: 返回创建 Future 时分配的任务 ID，不可变

**测试用例**:
```php
$future = new TaskFuture('550e8400-e29b-41d4-a716-446655440000', $storage);
assertEquals('550e8400-e29b-41d4-a716-446655440000', $future->getTaskId());
```

---

### 方法：isDone()

**签名**:
```php
public function isDone(): bool
```

**输出**: 任务是否已完成（COMPLETED/FAILED/CANCELLED）

**行为约束**:
- 查询存储获取当前状态
- PENDING/RUNNING 返回 `false`
- COMPLETED/FAILED/CANCELLED 返回 `true`

**测试用例**:
```php
// TC-001: PENDING 任务
$storage->method('load')->willReturn(TaskData::pending(...));
assertFalse($future->isDone());

// TC-002: COMPLETED 任务
$storage->method('load')->willReturn(TaskData::completed(...));
assertTrue($future->isDone());
```

---

### 方法：isSuccess()

**签名**:
```php
public function isSuccess(): bool
```

**输出**: 任务是否成功完成（COMPLETED）

**行为约束**:
- 仅当状态为 COMPLETED 时返回 `true`
- 其他状态返回 `false`

**测试用例**:
```php
$storage->method('load')->willReturn(TaskData::completed(...));
assertTrue($future->isSuccess());

$storage->method('load')->willReturn(TaskData::failed(...));
assertFalse($future->isSuccess());
```

---

### 方法：isFailed()

**签名**:
```php
public function isFailed(): bool
```

**输出**: 任务是否执行失败（FAILED）

---

### 方法：isCancelled()

**签名**:
```php
public function isCancelled(): bool
```

**输出**: 任务是否已取消（CANCELLED）

---

### 方法：getStatus()

**签名**:
```php
public function getStatus(): TaskStatus
```

**输出**: 当前任务状态枚举值

**行为约束**: 查询存储并返回最新状态

**测试用例**:
```php
$storage->method('load')->willReturn(TaskData::running(...));
assertEquals(TaskStatus::RUNNING, $future->getStatus());
```

---

### 方法：get()

**签名**:
```php
public function get(?int $timeoutSeconds = null): mixed
```

**输入参数**:
| 参数名 | 类型 | 必填 | 默认值 | 说明 |
|--------|------|------|--------|------|
| $timeoutSeconds | ?int | ❌ | null | 超时时间（秒），null 表示无限等待 |

**输出**: 任务执行结果（类型由业务消息决定）

**行为约束**:
1. 轮询查询任务状态（默认 100ms 间隔）
2. 状态为 COMPLETED → 返回反序列化的结果
3. 状态为 FAILED → 抛出原始异常
4. 状态为 CANCELLED → 抛出 `TaskCancelledException`
5. 超过 `timeoutSeconds` 仍未完成 → 抛出 `TimeoutException`

**错误场景**:
| 错误 | 异常类型 | 说明 |
|------|---------|------|
| 任务失败 | 业务异常（重新抛出） | 任务执行时抛出的原始异常 |
| 任务取消 | `TaskCancelledException` | 任务在执行前被取消 |
| 等待超时 | `TimeoutException` | 超过指定时间仍未完成 |
| 任务不存在 | `TaskNotFoundException` | 任务 ID 无效或已过期 |

**测试用例**:

**TC-001**: 获取已完成任务结果
```php
// Given: 任务已完成，结果为字符串 "success"
$taskData = TaskData::completed('success');
$storage->method('load')->willReturn($taskData);

// When/Then: 立即返回结果
assertEquals('success', $future->get());
```

**TC-002**: 阻塞等待直到完成
```php
// Given: 任务先 PENDING，1 秒后变为 COMPLETED
$storage->method('load')
    ->willReturnOnConsecutiveCalls(
        TaskData::pending(...),
        TaskData::pending(...),
        TaskData::completed('done')
    );

// When: 阻塞等待（超时 5 秒）
$result = $future->get(5);

// Then: 返回结果 "done"
assertEquals('done', $result);
```

**TC-003**: 抛出任务异常
```php
// Given: 任务失败，异常信息已保存
$exception = new \RuntimeException('Task failed');
$taskData = TaskData::failed($exception);
$storage->method('load')->willReturn($taskData);

// When/Then: 重新抛出异常
$this->expectException(\RuntimeException::class);
$this->expectExceptionMessage('Task failed');
$future->get();
```

**TC-004**: 超时异常
```php
// Given: 任务一直处于 PENDING
$storage->method('load')->willReturn(TaskData::pending(...));

// When/Then: 1 秒后抛出超时异常
$this->expectException(TimeoutException::class);
$future->get(1);
```

**TC-005**: 任务已取消
```php
$storage->method('load')->willReturn(TaskData::cancelled(...));

$this->expectException(TaskCancelledException::class);
$future->get();
```

---

### 方法：getNonBlocking()

**签名**:
```php
public function getNonBlocking(): mixed
```

**输出**: 任务结果（如果已完成）

**行为约束**:
- **非阻塞**：立即返回，不轮询
- 状态为 COMPLETED → 返回结果
- 状态为 FAILED → 抛出异常
- 状态为 PENDING/RUNNING → 抛出 `TaskNotCompletedException`

**错误场景**:
| 错误 | 异常类型 | 说明 |
|------|---------|------|
| 任务未完成 | `TaskNotCompletedException` | 状态为 PENDING 或 RUNNING |

**测试用例**:
```php
// TC-001: 任务已完成
$storage->method('load')->willReturn(TaskData::completed('result'));
assertEquals('result', $future->getNonBlocking());

// TC-002: 任务未完成
$storage->method('load')->willReturn(TaskData::running(...));
$this->expectException(TaskNotCompletedException::class);
$future->getNonBlocking();
```

---

### 方法：cancel()

**签名**:
```php
public function cancel(): bool
```

**输出**: 是否成功取消（true = 成功，false = 失败）

**行为约束**:
1. 仅 PENDING 状态可取消
2. 更新状态为 CANCELLED，设置 completedAt
3. 返回 `true`
4. 若状态为 RUNNING/COMPLETED/FAILED，返回 `false`（已开始执行或已完成）

**测试用例**:
```php
// TC-001: 成功取消 PENDING 任务
$storage->method('load')->willReturn(TaskData::pending(...));
$storage->expects($this->once())
    ->method('updateStatus')
    ->with($taskId, TaskStatus::CANCELLED);

assertTrue($future->cancel());

// TC-002: 无法取消 RUNNING 任务
$storage->method('load')->willReturn(TaskData::running(...));
assertFalse($future->cancel());
```

---

### 方法：getException()

**签名**:
```php
public function getException(): ?\Throwable
```

**输出**: 任务异常（仅 FAILED 状态有值）

**行为约束**:
- 状态为 FAILED → 返回反序列化的异常对象
- 其他状态 → 返回 `null`

**测试用例**:
```php
$exception = new \RuntimeException('Error');
$storage->method('load')->willReturn(TaskData::failed($exception));

$result = $future->getException();
assertInstanceOf(\RuntimeException::class, $result);
assertEquals('Error', $result->getMessage());
```

---

## 性能契约

- **isDone/getStatus**: 查询延迟 P50 < 5ms，P99 < 10ms
- **get()**: 轮询开销 ≤ 10 次/秒（100ms 间隔）
- **cancel()**: 更新延迟 < 20ms

---

**版本**: 1.0
**状态**: Draft
**更新日期**: 2025-12-01
