<?php

declare(strict_types=1);

namespace Demo\Tests;

use Demo\ShippingCalculator;
use PHPUnit\Framework\TestCase;

final class ShippingCalculatorTest extends TestCase
{
    public function testSmallCartPaysTheFlatFee(): void
    {
        $calculator = new ShippingCalculator();

        self::assertSame(6.95, $calculator->shippingFor([
            ['price' => 10.0, 'qty' => 1],
        ]));
    }

    public function testCartAtThresholdShipsFree(): void
    {
        $calculator = new ShippingCalculator();

        self::assertSame(0.0, $calculator->shippingFor([
            ['price' => 25.0, 'qty' => 2],
        ]));
    }

    public function testLargeCartShipsFree(): void
    {
        $calculator = new ShippingCalculator();

        self::assertSame(0.0, $calculator->shippingFor([
            ['price' => 60.0, 'qty' => 3],
        ]));
    }
}
