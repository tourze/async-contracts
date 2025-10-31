# async-contracts

[English](README.md) | [中文](README.zh-CN.md)

A simple contract package that provides interfaces for asynchronous messaging in PHP applications. This package defines the basic contract for async message handling without any implementation details.

## Installation

```bash
composer require tourze/async-contracts
```

## Quick Start

This package provides a base interface for async message handling:

```php
<?php

use Tourze\AsyncContracts\AsyncMessageInterface;

// Implement the interface in your async message classes
class MyAsyncMessage implements AsyncMessageInterface
{
    // Your implementation here
}
```

## Usage

The `AsyncMessageInterface` serves as a marker interface for async message objects. It currently contains no methods, allowing maximum flexibility for implementers to define their own message structure.

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

## Requirements

- PHP 8.1 or higher

## Testing

```bash
./vendor/bin/phpunit packages/async-contracts/tests
```

## License

This package is open-source software licensed under the [MIT license](LICENSE).