<?php
/**
 * Simple sequential migration runner.
 * Tracks applied migrations in a `migrations` table.
 * Run: php migrate.php
 */

require __DIR__ . '/lib/bootstrap.php';

$pdo = db();

// Create migrations tracking table if it doesn't exist
$pdo->exec('
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
    )
');

// Get already-applied migrations
$applied = $pdo->query('SELECT filename FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

// Find migration files
$dir = __DIR__ . '/migrations';
$files = glob($dir . '/*.sql');
sort($files); // ensures numeric order

$count = 0;
foreach ($files as $file) {
    $filename = basename($file);
    if (in_array($filename, $applied, true)) {
        continue;
    }

    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO migrations (filename) VALUES (?)');
        $stmt->execute([$filename]);
        echo "  Applied: {$filename}\n";
        $count++;
    } catch (PDOException $e) {
        fwrite(STDERR, "  FAILED: {$filename}: " . $e->getMessage() . "\n");
        exit(1);
    }
}

if ($count === 0) {
    echo "  No new migrations.\n";
} else {
    echo "  {$count} migration(s) applied.\n";
}
