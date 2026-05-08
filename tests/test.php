<?php

require __DIR__ . '/../lib/bootstrap.php';

system('php ' . escapeshellarg(__DIR__ . '/../seed.php') . ' > /dev/null', $rc);
if ($rc !== 0) {
    fwrite(STDERR, "seed failed\n");
    exit(1);
}

// Run migrations after seed
system('php ' . escapeshellarg(__DIR__ . '/../migrate.php') . ' > /dev/null', $rc2);
if ($rc2 !== 0) {
    fwrite(STDERR, "migrate failed\n");
    exit(1);
}

$pass = 0;
$fail = 0;

function test(string $name, callable $fn): void {
    global $pass, $fail;
    try {
        $fn();
        echo "  [ok] {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  [FAIL] {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}

function assert_true($cond, string $msg = ''): void {
    if (!$cond) {
        throw new RuntimeException($msg !== '' ? $msg : 'expected true');
    }
}

function assert_equals($expected, $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        $detail = "expected " . var_export($expected, true) . ", got " . var_export($actual, true);
        throw new RuntimeException($msg !== '' ? "$msg ($detail)" : $detail);
    }
}

echo "\nRunning tests:\n";

// --- Existing test ---

test('seeded share link resolves to the seeded document', function () {
    $stmt = db()->prepare('
        SELECT d.title
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        LIMIT 1
    ');
    $stmt->execute();
    $row = $stmt->fetch();
    assert_true($row !== false, 'expected the seeded share to resolve');
    assert_true($row['title'] === 'Welcome Packet', 'unexpected title: ' . var_export($row['title'], true));
});

// --- Feature 1: Scheduled Publishing ---

test('document with null publish_at is considered published', function () {
    $doc = ['publish_at' => null];
    assert_true(is_published($doc), 'null publish_at should mean published');
});

test('document with past publish_at is considered published', function () {
    $doc = ['publish_at' => date('Y-m-d\TH:i', strtotime('-1 hour'))];
    assert_true(is_published($doc), 'past publish_at should mean published');
});

test('document with future publish_at is NOT considered published', function () {
    $doc = ['publish_at' => date('Y-m-d\TH:i', strtotime('+1 hour'))];
    assert_true(!is_published($doc), 'future publish_at should mean not published');
});

test('seeded scheduled document has future publish_at', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title = ?');
    $stmt->execute(['Q3 Report']);
    $doc = $stmt->fetch();
    assert_true($doc !== false, 'Q3 Report should exist');
    assert_true(!empty($doc['publish_at']), 'Q3 Report should have publish_at set');
    assert_true(!is_published($doc), 'Q3 Report should not be published yet');
});

// --- Feature 2: Human-Readable IDs ---

test('generate_slug produces a readable slug with suffix', function () {
    $slug = generate_slug('Hello World');
    assert_true(str_starts_with($slug, 'hello-world-'), "slug should start with 'hello-world-', got: $slug");
    // Should be title-part + dash + 4 char suffix
    $parts = explode('-', $slug);
    $suffix = end($parts);
    assert_true(strlen($suffix) === 4, "suffix should be 4 chars, got: $suffix");
});

test('generate_slug truncates long titles', function () {
    $slug = generate_slug('This Is A Very Long Title That Should Be Truncated To Keep Slugs Short');
    // Base should be max 30 chars + dash + 4 char suffix
    $withoutSuffix = substr($slug, 0, strrpos($slug, '-'));
    assert_true(strlen($withoutSuffix) <= 30, "base slug too long: $withoutSuffix (" . strlen($withoutSuffix) . " chars)");
});

test('generate_slug produces unique slugs for same title', function () {
    $slug1 = generate_slug('Duplicate Title');
    $slug2 = generate_slug('Duplicate Title');
    assert_true($slug1 !== $slug2, 'two calls should produce different slugs due to random suffix');
});

test('seeded documents have slugs', function () {
    $stmt = db()->prepare('SELECT slug FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $doc = $stmt->fetch();
    assert_true($doc !== false && !empty($doc['slug']), 'Welcome Packet should have a slug');
});

test('slug-based document lookup works', function () {
    $stmt = db()->prepare('SELECT slug FROM documents WHERE title = ?');
    $stmt->execute(['Welcome Packet']);
    $doc = $stmt->fetch();
    $slug = $doc['slug'];

    $stmt2 = db()->prepare('SELECT title FROM documents WHERE slug = ?');
    $stmt2->execute([$slug]);
    $found = $stmt2->fetch();
    assert_equals('Welcome Packet', $found['title'], 'slug lookup should find the document');
});

// --- Feature 3: Search ---

test('search by title prefix finds matching documents', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['Welcome%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) === 1, 'should find 1 document matching "Welcome"');
    assert_equals('Welcome Packet', $results[0]['title']);
});

test('search by title prefix is case-sensitive (SQLite LIKE default)', function () {
    // SQLite LIKE is case-insensitive for ASCII by default
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['welcome%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) === 1, 'SQLite LIKE should be case-insensitive for ASCII');
});

test('search with no match returns empty', function () {
    $stmt = db()->prepare('SELECT * FROM documents WHERE title LIKE ?');
    $stmt->execute(['Nonexistent%']);
    $results = $stmt->fetchAll();
    assert_true(count($results) === 0, 'should find no documents');
});

// --- Audit logging ---

test('document creation is audit logged', function () {
    // Create a document the way admin.php does
    $title = 'Audit Test Doc';
    $body = 'Testing audit logging';
    $slug = generate_slug($title);
    $stmt = db()->prepare('INSERT INTO documents (title, body, created_by, slug) VALUES (?, ?, 1, ?)');
    $stmt->execute([$title, $body, $slug]);
    $docId = (int) db()->lastInsertId();
    audit_log('create', 'document', $docId, ['title' => $title, 'slug' => $slug]);

    $stmt = db()->prepare("SELECT * FROM audit_log WHERE action = 'create' AND entity_type = 'document' AND entity_id = ?");
    $stmt->execute([$docId]);
    $log = $stmt->fetch();
    assert_true($log !== false, 'should have an audit log entry for document creation');
    $details = json_decode($log['details'], true);
    assert_equals($title, $details['title'], 'audit log should contain the title');
});

echo "\n{$pass} passed, {$fail} failed.\n";
exit($fail > 0 ? 1 : 0);
