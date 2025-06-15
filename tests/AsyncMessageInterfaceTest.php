<?php

namespace Tourze\AsyncContracts\Tests;

use PHPUnit\Framework\TestCase;
use Tourze\AsyncContracts\AsyncMessageInterface;

class AsyncMessageInterfaceTest extends TestCase
{
    public function testInterfaceExists(): void
    {
        $this->assertTrue(interface_exists(AsyncMessageInterface::class));
    }

    public function testInterfaceImplementation(): void
    {
        $implementation = new class implements AsyncMessageInterface {
        };

        $this->assertInstanceOf(AsyncMessageInterface::class, $implementation);
    }

    public function testInterfaceHasNoMethods(): void
    {
        $reflection = new \ReflectionClass(AsyncMessageInterface::class);
        $methods = $reflection->getMethods();

        $this->assertEmpty($methods, 'AsyncMessageInterface should not have any methods');
    }

    public function testInterfaceIsInterface(): void
    {
        $reflection = new \ReflectionClass(AsyncMessageInterface::class);

        $this->assertTrue($reflection->isInterface());
    }

    public function testInterfaceNamespace(): void
    {
        $reflection = new \ReflectionClass(AsyncMessageInterface::class);

        $this->assertSame('Tourze\\AsyncContracts', $reflection->getNamespaceName());
    }
}