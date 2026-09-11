<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/tests/TestCase.php';
require_once dirname(__DIR__) . '/tests/ModerationBotTest.php';

use Tests\ModerationBotTest;

echo "=========================================================\n";
echo "🧪 Block-BOT: Avtomatlashtirilgan Qat'iy Testlar To'plami\n";
echo "=========================================================\n\n";

$startTime = microtime(true);
$passed = 0;
$failed = 0;

$testClass = new ModerationBotTest();
ModerationBotTest::setUpBeforeClass();

// Reflection orqali test_ bilan boshlanuvchi barcha metodlarni olish
$ref = new ReflectionClass(ModerationBotTest::class);
$methods = $ref->getMethods(ReflectionMethod::IS_PUBLIC);

$testIndex = 1;

foreach ($methods as $method) {
    $name = $method->getName();
    if (!str_starts_with($name, 'test')) {
        continue;
    }

    $refSetUp = $ref->getMethod('setUp');
    $refSetUp->setAccessible(true);
    $refSetUp->invoke($testClass);

    echo sprintf("[%02d] %-55s ... ", $testIndex++, $name);

    try {
        $method->invoke($testClass);
        echo "✅ [PASSED]\n";
        $passed++;
    } catch (Throwable $e) {
        echo "❌ [FAILED]\n";
        echo "     Sabab: " . $e->getMessage() . "\n";
        echo "     Fayl: " . $e->getFile() . ":" . $e->getLine() . "\n";
        $failed++;
    }
}

$elapsed = round(microtime(true) - $startTime, 3);

echo "\n=========================================================\n";
echo "Test Natijalari:\n";
echo "• Jami testlar: " . ($passed + $failed) . "\n";
echo "• Muvaffaqiyatli: {$passed} ✅\n";
echo "• Xatolar: {$failed} " . ($failed === 0 ? "🎉" : "❌") . "\n";
echo "• Bajarilish vaqti: {$elapsed} soniya\n";
echo "=========================================================\n\n";

exit($failed > 0 ? 1 : 0);
