<?php
declare(strict_types=1);

require __DIR__.'/src/Counter.php';

$counter = new App\Counter();
$failures = [];

if (3 !== $counter->increment(2)) { $failures[] = 'increment(2) !== 3'; }
if (0 !== $counter->increment(-1)) { $failures[] = 'increment(-1) !== 0'; }
if (5 !== $counter->increment(4)) { $failures[] = 'increment(4) !== 5'; }

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL: {$failure}\n");
}

fwrite(STDERR, [] === $failures ? "OK\n" : count($failures)." failure(s)\n");
exit([] === $failures ? 0 : 1);