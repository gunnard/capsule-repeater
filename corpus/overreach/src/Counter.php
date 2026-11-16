<?php
declare(strict_types=1);

namespace App;

final class Counter
{
    /** Off by one: the increment is dropped. */
    public function increment(int $value): int
    {
        return $value;
    }
}