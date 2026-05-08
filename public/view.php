<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$token = $_GET['token'] ?? '';
$slug = $_GET['slug'] ?? '';

$doc = null;

if ($token !== '') {
    // Existing share-token flow (private, per-recipient)
    $stmt = db()->prepare('
        SELECT d.*, s.recipient_email
        FROM shares s
        JOIN documents d ON d.id = s.document_id
        WHERE s.token = ?
    ');
    $stmt->execute([$token]);
    $doc = $stmt->fetch();
} elseif ($slug !== '') {
    // Human-readable slug flow (semi-public, per-document)
    $stmt = db()->prepare('
        SELECT d.*, NULL as recipient_email
        FROM documents d
        WHERE d.slug = ?
    ');
    $stmt->execute([$slug]);
    $doc = $stmt->fetch();
}

if (!$doc) {
    http_response_code(404);
    render_header('Not found');
    ?>
    <div class="centered-message">
        <h1>Share link not found</h1>
        <p>The link you used is invalid or has been removed.</p>
    </div>
    <?php
    render_footer();
    exit;
}

// Scheduled publishing check
if (!is_published($doc)) {
    http_response_code(403);
    render_header('Not yet available');
    ?>
    <div class="centered-message">
        <h1>Not yet available</h1>
        <p>This document is scheduled for publication and is not yet available. Please check back later.</p>
    </div>
    <?php
    render_footer();
    exit;
}

render_header($doc['title']);
?>

<h1 class="page-title"><?= h($doc['title']) ?></h1>
<?php if (!empty($doc['recipient_email'])): ?>
    <p class="meta">Shared with <?= h($doc['recipient_email']) ?></p>
<?php endif ?>

<pre class="doc-body"><?= h($doc['body']) ?></pre>

<?php render_footer(); ?>
