# 实施方案：消息异步任务 Future 模式

**Feature**: `message-future` | **Scope**: `packages/async-contracts` | **日期**: 2025-12-01 | **Spec**: [spec.md](./spec.md)
**输入**: `packages/async-contracts/specs/message-future/spec.md`

## 概述

实现类似 Java Future 的异步任务管理模式，基于 Symfony Messenger 组件，根据环境配置 `MESSENGER_TRANSPORT_DSN` 自动适配数据库（Doctrine）或 Redis 存储后端。核心功能包括任务提交、状态查询、阻塞/非阻塞结果获取、任务取消和自动过期清理。

**技术选择**（来自 research.md）:
- 存储适配：策略模式 + 工厂模式（TaskStorageInterface）
- 序列化：Symfony Serializer + JSON 格式
- 轮询等待：usleep(100ms) + 状态轮询
- 任务清理：Symfony Console Command + Cron 定时调度

## 技术背景

**语言/版本**：PHP 8.1+
**主要依赖**：
- symfony/framework-bundle ^6.0|^7.0
- symfony/messenger ^6.0|^7.0
- symfony/serializer ^6.0|^7.0
- symfony/uid ^6.0|^7.0
- doctrine/orm ^2.0|^3.0（数据库模式）
- predis/predis ^2.0（Redis 模式，可选 phpredis）

**存储**：
- 数据库模式：MySQL 5.7+/PostgreSQL 12+/SQLite 3.x，通过 Doctrine ORM
- Redis 模式：Redis 5.x+，通过 predis 或 phpredis 客户端

**测试**：PHPUnit 10.x，PHPStan Level 8
**目标平台**：Linux/macOS/Windows 服务器，PHP-FPM 或 Swoole
**项目类型**：Symfony Bundle（可复用库）
**性能目标**：
- 提交任务：1000 TPS（数据库），5000 TPS（Redis）
- 状态查询：< 10ms P50
- 阻塞等待轮询：≤ 10 次/秒

**约束**：
- 提交响应 < 50ms
- 轮询间隔默认 100ms（可配置）
- 任务保留 24 小时（默认）

**规模/场景**：支持 10 万+ 并发任务，适用于邮件发送、报表生成、数据导入等耗时操作

## 宪章检查

> 阶段门：Phase 0 前必过，Phase 1 后复核。依据 `.specify/memory/constitution.md`。

- [x] **Monorepo 分层架构**：功能归属 `packages/async-contracts`，仅依赖 Symfony 组件，无循环依赖
- [x] **Spec 驱动**：具备完整的 spec.md、plan.md、research.md、data-model.md、contracts/*.md
- [x] **测试优先**：TDD 策略明确，契约测试（接口测试）+ 单元测试（存储实现）+ 集成测试（端到端）
- [x] **质量门禁**：PHPStan Level 8、PHP-CS-Fixer、PHPUnit 覆盖率 > 80%
- [x] **可追溯性**：research.md 记录技术决策，contracts/*.md 提供测试用例，符合 Conventional Commits

**Phase 1 后复核**：✅ 所有宪章要求满足，无违例，无需复杂度备案。

## 项目结构

### 文档（本 Feature）

```text
[scope]/specs/[feature]/
├── plan.md              # 本文件（/speckit.plan 输出）
├── research.md          # Phase 0（/speckit.plan 输出）
├── data-model.md        # Phase 1（/speckit.plan 输出）
├── quickstart.md        # Phase 1（/speckit.plan 输出）
├── contracts/           # Phase 1（/speckit.plan 输出）
└── tasks.md             # Phase 2（/speckit.tasks 输出）
```

### 代码结构（Scope 根下）

```text
packages/async-contracts/
├── src/
│   ├── Contract/                         # 接口定义
│   │   ├── FutureInterface.php           # Future 核心接口
│   │   ├── TaskStorageInterface.php      # 存储抽象接口
│   │   └── AsyncTaskServiceInterface.php # 任务提交服务接口
│   │
│   ├── DTO/                              # 数据传输对象
│   │   ├── TaskData.php                  # 任务数据 DTO
│   │   ├── TaskStatus.php                # 任务状态枚举（enum）
│   │   └── ExceptionData.php             # 异常序列化 DTO
│   │
│   ├── Service/                          # 服务实现
│   │   ├── AsyncTaskService.php          # 任务提交服务
│   │   ├── TaskFuture.php                # Future 实现类
│   │   └── TaskStorageFactory.php        # 存储工厂
│   │
│   ├── Storage/                          # 存储实现
│   │   ├── DoctrineTaskStorage.php       # Doctrine 存储实现
│   │   └── RedisTaskStorage.php          # Redis 存储实现
│   │
│   ├── Entity/                           # Doctrine 实体（数据库模式）
│   │   └── AsyncTask.php                 # async_tasks 表映射
│   │
│   ├── Message/                          # Messenger 消息
│   │   └── AsyncTaskMessage.php          # 异步任务包装消息
│   │
│   ├── Handler/                          # 消息处理器
│   │   └── AsyncTaskHandler.php          # 任务执行处理器
│   │
│   ├── Command/                          # Console 命令
│   │   └── CleanExpiredTasksCommand.php  # 过期任务清理
│   │
│   └── Exception/                        # 自定义异常
│       ├── TimeoutException.php
│       ├── TaskNotFoundException.php
│       ├── TaskCancelledException.php
│       ├── TaskNotCompletedException.php
│       ├── ConcurrentModificationException.php
│       └── StorageException.php
│
├── tests/                                # 镜像 src/ 目录结构
│   ├── Contract/                         # 接口契约测试
│   ├── DTO/                              # DTO 单元测试
│   ├── Service/                          # 服务单元测试
│   ├── Storage/                          # 存储实现测试
│   ├── Entity/                           # 实体映射测试
│   ├── Message/                          # 消息测试
│   ├── Handler/                          # 处理器测试
│   ├── Command/                          # 命令测试
│   └── Integration/                      # 端到端集成测试（例外）
│       ├── DoctrineStorageIntegrationTest.php
│       └── RedisStorageIntegrationTest.php
│
└── specs/message-future/                 # 设计文档
    ├── spec.md
    ├── plan.md (本文件)
    ├── research.md
    ├── data-model.md
    ├── quickstart.md
    └── contracts/
        ├── AsyncTaskService.md
        ├── FutureInterface.md
        └── TaskStorageInterface.md
```

**结构决策**：
- 采用 Symfony Bundle 标准结构
- `Contract/` 存放接口定义，便于其他包依赖
- `Storage/` 分离存储实现，符合策略模式
- `tests/Integration/` 例外保留（集成测试需要真实存储环境），但仍镜像主要结构
- 所有 Doctrine 实体集中在 `Entity/`，Repository 自动生成
- 无 Controller（纯库，不提供 HTTP 端点）

## 复杂度备案

**无违例**：所有设计符合宪章要求，无需备案。

---

**版本**: 1.0
**状态**: Phase 1 完成，等待 Phase 2（任务分解）
**更新日期**: 2025-12-01
