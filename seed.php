<?php

require __DIR__ . '/lib/bootstrap.php';

$dbPath = __DIR__ . '/db.sqlite';
if (file_exists($dbPath)) {
    unlink($dbPath);
}

$pdo = db();
$pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));

// Mark migrations as already applied (schema.sql includes these changes)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        filename TEXT NOT NULL UNIQUE,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )
");
$migrationDir = __DIR__ . '/migrations';
$migrationFiles = glob($migrationDir . '/*.sql');
sort($migrationFiles);
foreach ($migrationFiles as $file) {
    $filename = basename($file);
    $pdo->prepare('INSERT OR IGNORE INTO migrations (filename) VALUES (?)')->execute([$filename]);
}

$pdo->exec("
    INSERT INTO staff (email, name) VALUES
        ('freddy@folio.example', 'Freddy Folio')
");

// Document 1: immediately published, with slug
$slug1 = generate_slug('Welcome Packet');
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug)
    VALUES (?, ?, 1, ?)
');
$stmt->execute([
    'Welcome Packet',
    "Welcome to Folio!\n\nThis is the body of your welcome packet.",
    $slug1,
]);
$docId = (int) $pdo->lastInsertId();

$token = random_token();
$stmt = $pdo->prepare('
    INSERT INTO shares (document_id, token, recipient_email)
    VALUES (?, ?, ?)
');
$stmt->execute([$docId, $token, 'recipient@example.com']);

// Document 2: scheduled for the future
$slug2 = generate_slug('Q3 Report');
$futureDate = date('Y-m-d\TH:i', strtotime('+7 days'));
$stmt = $pdo->prepare('
    INSERT INTO documents (title, body, created_by, slug, publish_at)
    VALUES (?, ?, 1, ?, ?)
');
$stmt->execute([
    'Q3 Report',
    "This is the Q3 financial report.\n\nIt contains sensitive data.",
    $slug2,
    $futureDate,
]);

echo "Seeded db.sqlite.\n";
echo "Admin:          http://localhost:8000/admin.php\n";
echo "Sample share:   http://localhost:8000/view.php?token={$token}\n";
echo "Slug link:      http://localhost:8000/view.php?slug={$slug1}\n";
echo "Scheduled doc:  http://localhost:8000/view.php?slug={$slug2} (not yet available)\n";
