<?php

declare(strict_types=1);

namespace Tourze\AsyncContracts\Tests\Implementation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\AsyncContracts\AsyncMessageInterface;

/**
 * @internal
 */
#[CoversClass(ConcreteAsyncMessage::class)]
final class ConcreteAsyncMessageTest extends TestCase
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
        $this->assertNotEquals($message1, $message2);
    }
}
