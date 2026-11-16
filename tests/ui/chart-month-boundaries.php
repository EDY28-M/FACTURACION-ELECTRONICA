<?php

require __DIR__.'/../../vendor/autoload.php';

use Carbon\Carbon;

$controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/System/ClientController.php');
if (strpos($controller, '$date_initial->copy()->endOfMonth()') === false || strpos($controller, 'cal_days_in_month') !== false) {
    throw new RuntimeException('Dashboard charts must use extension-independent month boundaries.');
}
foreach (['2024-02-01' => '2024-02-29', '2025-02-01' => '2025-02-28', '2028-02-01' => '2028-02-29', '2026-01-01' => '2026-01-31', '2026-12-01' => '2026-12-31'] as $input => $expected) {
    $start = Carbon::parse($input);
    if ($start->copy()->endOfMonth()->toDateString() !== $expected || $start->toDateString() !== $input) {
        throw new RuntimeException('Invalid or mutated month boundary: '.$input);
    }
}
echo "Dashboard chart month boundaries: five cases passed.\n";
