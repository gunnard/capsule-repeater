<?php

declare(strict_types=1);

namespace Demo;

/**
 * Order service that depends on ShippingCalculator.
 */
final class OrderService
{
    public function __construct(private readonly ShippingCalculator $calculator) {}

    public function totalWithShipping(array $lines): float
    {
        $subtotal = array_sum(array_map(
            static fn (array $l): float => $l['price'] * $l['qty'],
            $lines,
        ));

        return $subtotal + $this->calculator->shippingFor($lines);
    }
}
