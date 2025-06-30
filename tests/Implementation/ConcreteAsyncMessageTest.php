<?php

namespace Tourze\AsyncContracts\Tests\Implementation;

use PHPUnit\Framework\TestCase;
use Tourze\AsyncContracts\AsyncMessageInterface;

class ConcreteAsyncMessageTest extends TestCase
{
    public function testConcreteImplementation(): void
    {
        $message = new ConcreteAsyncMessage();

        $this->assertInstanceOf(AsyncMessageInterface::class, $message);
    }

    public function testMultipleImplementations(): void
    {
        $message1 = new ConcreteAsyncMessage();
        $message2 = new AnotherAsyncMessage();

        $this->assertInstanceOf(AsyncMessageInterface::class, $message1);
        $this->assertInstanceOf(AsyncMessageInterface::class, $message2);
        $this->assertNotSame($message1, $message2);
    }

}

class ConcreteAsyncMessage implements AsyncMessageInterface
{
}

class AnotherAsyncMessage implements AsyncMessageInterface
{
}