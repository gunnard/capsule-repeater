<?php

declare(strict_types=1);

/**
 * The reproduction for the bundled bug.
 *
 * Deliberately dependency-free: the demo has to work from a fresh clone without
 * a second `composer install`, and a check that needs a framework installed is a
 * check a stranger will not run. The PHPUnit suite in tests/ covers the same
 * behaviour for anyone who installs the fixture's own dependencies.
 *
 * Exits 0 only when shipping is charged correctly, so it can be used directly as
 * the gate's --test-command.
 */

require __DIR__.'/src/ShippingCalculator.php';

use Demo\ShippingCalculator;

$calculator = new ShippingCalculator();
$failures = [];

/**
 * @param list<array{price: float, qty: int}> $lines
 */
function checkShipping(ShippingCalculator $calculator, array $lines, float $expected, string $description): ?string
{
    $actual = $calculator->shippingFor($lines);

    // Float comparison: both values are exact multiples of 0.05 here, but an
    // epsilon keeps the assertion honest if the pricing model changes.
    if (abs($actual - $expected) > 0.0001) {
        return \sprintf('%s: expected %.2f, got %.2f', $description, $expected, $actual);
    }

    return null;
}

$cases = [
    [[['price' => 10.0, 'qty' => 1]], ShippingCalculator::FLAT_FEE, 'subtotal $10.00 pays the flat fee'],
    [[['price' => 25.0, 'qty' => 2]], 0.0, 'subtotal $50.00 exactly ships free'],
    [[['price' => 60.0, 'qty' => 3]], 0.0, 'subtotal $180.00 ships free'],
    [[['price' => 49.99, 'qty' => 1]], ShippingCalculator::FLAT_FEE, 'subtotal $49.99 pays the flat fee'],
    [
        [['price' => 20.0, 'qty' => 1], ['price' => 15.0, 'qty' => 2]],
        0.0,
        'multiple lines summing to $50.00 ship free',
    ],
];

foreach ($cases as [$lines, $expected, $description]) {
    $failure = checkShipping($calculator, $lines, $expected, $description);
    if (null !== $failure) {
        $failures[] = $failure;
    }
}

foreach ($failures as $failure) {
    fwrite(STDERR, "FAIL  {$failure}\n");
}

if ([] === $failures) {
    fwrite(STDERR, \sprintf("OK  %d shipping cases\n", \count($cases)));

    exit(0);
}

fwrite(STDERR, \sprintf("\n%d of %d shipping cases failed\n", \count($failures), \count($cases)));

exit(1);
