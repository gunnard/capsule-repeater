<?php
declare(strict_types=1);

namespace App;

final class Counter
{
    public function increment(int $value): int
    {
        return $value + 1;
    }
}