<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;

Config::load();

echo "=========================================================\n";
echo "Block-BOT: Ma'lumotlar bazasi migratsiyasini ishga tushirish\n";
echo "=========================================================\n";

$env = Config::get('APP_ENV', 'production');
$driver = Config::get('DB_DRIVER', 'mysql');

try {
    if ($driver === 'mysql' && $env !== 'test') {
        $host = Config::get('DB_HOST', '127.0.0.1');
        $port = Config::getInt('DB_PORT', 3306);
        $dbname = Config::get('DB_NAME', 'block_bot');
        $user = Config::get('DB_USER', 'root');
        $pass = Config::get('DB_PASS', '');
        if (!preg_match('/^[A-Za-z0-9_]+$/', (string)$dbname)) {
            throw new RuntimeException("DB_NAME faqat harf, raqam va pastki chiziqdan iborat bo'lishi kerak.");
        }

        // Avval bazani mavjud bo'lmasa yaratamiz
        $rootPdo = new PDO("mysql:host={$host};port={$port}", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        echo "[OK] Ma'lumotlar bazasi tekshirildi/yaratildi: {$dbname}\n";
    }

    $pdo = Database::getConnection();

    $migrationFiles = glob(dirname(__DIR__) . '/database/migrations/[0-9][0-9][0-9]_*.sql') ?: [];
    sort($migrationFiles, SORT_NATURAL);
    if ($migrationFiles === []) {
        throw new RuntimeException("Migratsiya fayllari topilmadi");
    }

    $pdo->exec($driver === 'sqlite' || $env === 'test'
        ? "CREATE TABLE IF NOT EXISTS schema_migrations (version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)"
        : "CREATE TABLE IF NOT EXISTS schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($driver === 'sqlite' || $env === 'test') {
        $version = 'sqlite_schema_v3';
        $check = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = :version");
        $check->execute(['version' => $version]);
        if ((int)$check->fetchColumn() === 0) {
            $sqliteSchema = dirname(__DIR__) . '/database/migrations/sqlite_schema.sql';
            if (!is_file($sqliteSchema)) {
                throw new RuntimeException("SQLite sxema fayli topilmadi: {$sqliteSchema}");
            }
            $pdo->exec((string)file_get_contents($sqliteSchema));
            $record = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :now)");
            $record->execute(['version' => $version, 'now' => gmdate('Y-m-d H:i:s')]);
            echo "[OK] {$version}\n";
        } else {
            echo "[SKIP] {$version}\n";
        }
    } else foreach ($migrationFiles as $migrationFile) {
        $version = basename($migrationFile);
        $check = $pdo->prepare("SELECT COUNT(*) FROM schema_migrations WHERE version = :version");
        $check->execute(['version' => $version]);
        if ((int)$check->fetchColumn() > 0) {
            echo "[SKIP] {$version}\n";
            continue;
        }

        $sql = (string)file_get_contents($migrationFile);
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $record = $pdo->prepare("INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :now)");
        $record->execute(['version' => $version, 'now' => gmdate('Y-m-d H:i:s')]);
        echo "[OK] {$version}\n";
    }

    echo "[OK] Barcha jadvallar va indekslar muvaffaqiyatli yaratildi!\n";
    Logger::info("Baza migratsiyasi muvaffaqiyatli yakunlandi.");
} catch (Throwable $e) {
    echo "[XATO] Migratsiya jarayonida xatolik yuz berdi:\n";
    echo $e->getMessage() . "\n";
    Logger::error("Migratsiya xatosi: " . $e->getMessage());
    exit(1);
}
