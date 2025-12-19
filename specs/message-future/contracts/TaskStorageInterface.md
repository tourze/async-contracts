# 服务契约：TaskStorageInterface

**目的**: 抽象任务数据的存储和检索，支持数据库和 Redis 两种实现
**优先级**: P1（核心接口）

## 接口定义

### 方法：save()

**签名**:
```php
public function save(string $taskId, TaskData $data): void
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| $taskId | string | ✅ | 任务 ID（UUID） |
| $data | TaskData | ✅ | 任务完整数据 |

**行为约束**:
1. 保存或更新任务记录（UPSERT 语义）
2. 数据库模式：INSERT ... ON DUPLICATE KEY UPDATE
3. Redis 模式：SET 多个键（status、payload、result、error、时间戳）
4. 所有时间字段存储为 UTC

**错误场景**:
| 错误 | 异常类型 | 说明 |
|------|---------|------|
| 存储不可用 | `StorageException` | 数据库连接失败或 Redis 不可达 |
| 数据验证失败 | `ValidationException` | TaskData 不符合约束（如 COMPLETED 状态但 result 为 null） |

**测试用例**:
```php
// TC-001: 保存新任务
$data = TaskData::pending($taskId, $payload, new \DateTimeImmutable());
$storage->save($taskId, $data);

// 验证：可查询到记录
$loaded = $storage->load($taskId);
assertEquals(TaskStatus::PENDING, $loaded->getStatus());

// TC-002: 更新已有任务
$data->setStatus(TaskStatus::RUNNING);
$storage->save($taskId, $data);

// 验证：状态已更新
assertEquals(TaskStatus::RUNNING, $storage->load($taskId)->getStatus());
```

---

### 方法：load()

**签名**:
```php
public function load(string $taskId): ?TaskData
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| $taskId | string | ✅ | 任务 ID |

**输出**: TaskData 对象（任务存在）或 null（任务不存在/已过期）

**行为约束**:
1. 按 taskId 查询存储
2. 反序列化 payload、result、error 字段
3. 任务不存在或已过期 → 返回 `null`

**测试用例**:
```php
// TC-001: 查询存在的任务
$data = $storage->load($existingTaskId);
assertNotNull($data);
assertEquals($existingTaskId, $data->getTaskId());

// TC-002: 查询不存在的任务
$data = $storage->load('non-existent-uuid');
assertNull($data);
```

---

### 方法：updateStatus()

**签名**:
```php
public function updateStatus(string $taskId, TaskStatus $status): void
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| $taskId | string | ✅ | 任务 ID |
| $status | TaskStatus | ✅ | 新状态 |

**行为约束**:
1. 仅更新 status 字段
2. 若状态转为 RUNNING → 同时设置 startedAt
3. 若状态转为 COMPLETED/FAILED/CANCELLED → 同时设置 completedAt
4. 数据库模式：使用乐观锁防止并发覆盖
5. Redis 模式：使用分布式锁（可选）

**错误场景**:
| 错误 | 异常类型 | 说明 |
|------|---------|------|
| 并发修改 | `ConcurrentModificationException` | 任务状态已被其他进程修改 |
| 任务不存在 | `TaskNotFoundException` | taskId 无效 |

**测试用例**:
```php
// TC-001: 更新状态
$storage->updateStatus($taskId, TaskStatus::RUNNING);
assertEquals(TaskStatus::RUNNING, $storage->load($taskId)->getStatus());
assertNotNull($storage->load($taskId)->getStartedAt());

// TC-002: 并发修改检测（数据库模式）
$storage->expects($this->once())
    ->method('updateStatus')
    ->willThrowException(new OptimisticLockException());
```

---

### 方法：delete()

**签名**:
```php
public function delete(string $taskId): void
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| $taskId | string | ✅ | 任务 ID |

**行为约束**:
1. 删除任务记录
2. 数据库模式：DELETE FROM async_tasks WHERE task_id = ?
3. Redis 模式：DEL task:{taskId}:*（删除所有相关键）
4. 任务不存在时不抛出异常（幂等）

**测试用例**:
```php
// TC-001: 删除存在的任务
$storage->delete($taskId);
assertNull($storage->load($taskId));

// TC-002: 删除不存在的任务（幂等）
$storage->delete('non-existent-uuid');
// 不抛出异常
```

---

### 方法：deleteExpiredTasks()

**签名**:
```php
public function deleteExpiredTasks(\DateTimeImmutable $before): int
```

**输入参数**:
| 参数名 | 类型 | 必填 | 说明 |
|--------|------|------|------|
| $before | \DateTimeImmutable | ✅ | 删除此时间之前完成的任务 |

**输出**: 删除的任务数量

**行为约束**:
1. 删除 completedAt < $before 的所有任务
2. 仅删除终态任务（COMPLETED/FAILED/CANCELLED）
3. 数据库模式：批量删除（每次最多 1000 条）
4. Redis 模式：使用 SCAN 遍历键并批量 DEL

**测试用例**:
```php
// TC-001: 删除过期任务
$before = new \DateTimeImmutable('-25 hours');
$count = $storage->deleteExpiredTasks($before);

// 验证：24 小时前的任务已删除
assertGreaterThan(0, $count);
```

---

## 实现类契约

### DoctrineTaskStorage

**依赖**: Doctrine ORM EntityManager
**表**: `async_tasks`
**特性**:
- 使用 Doctrine Repository 模式
- 乐观锁（version 字段）
- 批量删除分页（防止锁表）

### RedisTaskStorage

**依赖**: predis/predis 或 phpredis
**键前缀**: `task:{taskId}:`
**特性**:
- 所有键设置 24 小时 TTL
- 使用 MULTI/EXEC 事务保证原子性
- 可选：使用分布式锁（`task:{taskId}:lock`）

---

## 性能契约

| 操作 | 数据库模式 | Redis 模式 |
|------|-----------|-----------|
| save() | < 20ms | < 5ms |
| load() | < 10ms | < 2ms |
| updateStatus() | < 15ms | < 3ms |
| delete() | < 10ms | < 2ms |
| deleteExpiredTasks() | < 5s（1000 条/批次） | < 10s（10 万键） |

---

**版本**: 1.0
**状态**: Draft
**更新日期**: 2025-12-01
