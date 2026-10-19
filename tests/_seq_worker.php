<?php
declare(strict_types=1);
// عامل مساعد لاختبار التزامن: يولّد N أرقاماً متسلسلة ويطبعها (يُستدعى من run_tests.php فقط).
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
$_SERVER['REQUEST_METHOD'] = 'CLI';
require_once __DIR__ . '/../includes/bootstrap.php';
$n = max(1, (int)($argv[1] ?? 20));
for ($i = 0; $i < $n; $i++) {
    echo next_seq('receipt', 'R-'), "\n";
}
