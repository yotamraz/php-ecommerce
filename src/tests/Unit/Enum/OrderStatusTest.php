<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function testAllCasesExist(): void
    {
        $cases = OrderStatus::cases();
        $this->assertCount(5, $cases);
    }

    public function testBackedValues(): void
    {
        $this->assertSame('pending', OrderStatus::Pending->value);
        $this->assertSame('processing', OrderStatus::Processing->value);
        $this->assertSame('shipped', OrderStatus::Shipped->value);
        $this->assertSame('delivered', OrderStatus::Delivered->value);
        $this->assertSame('cancelled', OrderStatus::Cancelled->value);
    }

    public function testFromValidString(): void
    {
        $status = OrderStatus::from('pending');
        $this->assertSame(OrderStatus::Pending, $status);
    }

    public function testTryFromInvalidStringReturnsNull(): void
    {
        $status = OrderStatus::tryFrom('invalid');
        $this->assertNull($status);
    }
}
