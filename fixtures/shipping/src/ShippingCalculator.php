<?php

declare(strict_types=1);

namespace Demo;

final class ShippingCalculator
{
    /**
     * Shipping is free once the cart reaches the threshold, otherwise a flat fee.
     *
     * @param list<array{price: float, qty: int}> $lines
     */
    public function shippingFor(array $lines): float
    {
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $subtotal += $line['price'] * $line['qty'];
        }

        // BUG: the threshold comparison is inverted, so free shipping is never
        // granted and small carts are wrongly shipped for free.
        if ($subtotal < self::FREE_SHIPPING_THRESHOLD) {
            return 0.0;
        }

        return self::FLAT_FEE;
    }

    public const FREE_SHIPPING_THRESHOLD = 50.0;
    public const FLAT_FEE = 6.95;
}
