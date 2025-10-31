# async-contracts

[English](README.md) | [中文](README.zh-CN.md)

一个简单的契约包，为 PHP 应用程序中的异步消息处理提供接口。该包定义了异步消息处理的基本契约，不包含任何实现细节。

## 安装

```bash
composer require tourze/async-contracts
```

## 快速开始

该包提供了异步消息处理的基础接口：

```php
<?php

use Tourze\AsyncContracts\AsyncMessageInterface;

// 在你的异步消息类中实现该接口
class MyAsyncMessage implements AsyncMessageInterface
{
    // 你的实现代码
}
```

## 使用方法

`AsyncMessageInterface` 作为异步消息对象的标记接口。它目前不包含任何方法，为实现者定义自己的消息结构提供最大的灵活性。

```php
<?php

namespace App\Messages;

use Tourze\AsyncContracts\AsyncMessageInterface;

class UserRegistrationMessage implements AsyncMessageInterface
{
    public function __construct(
        private string $userId,
        private string $email
    ) {}

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getEmail(): string
    {
        return $this->email;
    }
}
```

## 系统要求

- PHP 8.1 或更高版本

## 测试

```bash
./vendor/bin/phpunit packages/async-contracts/tests
```

## 许可证

该包是根据 [MIT 许可证](LICENSE) 授权的开源软件。
