<?php

require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/layout.php';

$staff = current_staff();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $body = trim($_POST['body'] ?? '');
    $publish_at = trim($_POST['publish_at'] ?? '');

    if ($title === '' || $body === '') {
        $error = 'Title and body are required.';
    } else {
        $slug = generate_slug($title);

        // Ensure slug uniqueness (retry with new suffix on collision)
        $attempts = 0;
        while ($attempts < 5) {
            $check = db()->prepare('SELECT id FROM documents WHERE slug = ?');
            $check->execute([$slug]);
            if (!$check->fetch()) {
                break;
            }
            $slug = generate_slug($title);
            $attempts++;
        }

        $stmt = db()->prepare('
            INSERT INTO documents (title, body, created_by, slug, publish_at)
            VALUES (?, ?, ?, ?, ?)
        ');
        $publishValue = $publish_at !== '' ? $publish_at : null;
        $stmt->execute([$title, $body, $staff['id'], $slug, $publishValue]);
        $docId = (int) db()->lastInsertId();

        $auditDetails = ['title' => $title, 'slug' => $slug];
        if ($publishValue) {
            $auditDetails['publish_at'] = $publishValue;
        }
        audit_log('create', 'document', $docId, $auditDetails);

        if ($publishValue) {
            audit_log('schedule', 'document', $docId, ['publish_at' => $publishValue]);
        }

        header('Location: /admin.php?created=' . $docId);
        exit;
    }
}

// Search functionality
$search = trim($_GET['q'] ?? '');

if ($search !== '') {
    // Prefix search with LIKE — simple, fast, and intuitive for staff
    // Users expect "typing the start of a title" to work, and prefix search
    // handles that without the complexity/surprise of fuzzy matching.
    $stmt = db()->prepare('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        WHERE d.title LIKE ?
        ORDER BY d.created_at DESC
    ');
    $stmt->execute([$search . '%']);
    $docs = $stmt->fetchAll();
} else {
    $docs = db()->query('
        SELECT d.*, s.name AS creator_name
        FROM documents d
        JOIN staff s ON s.id = d.created_by
        ORDER BY d.created_at DESC
    ')->fetchAll();
}

render_header('Admin', $staff);
?>

<h1 class="page-title">Admin</h1>
<p class="page-subtitle">Create documents and generate share links for recipients.</p>

<?php if (!empty($_GET['created'])): ?>
    <div class="banner banner-success">Document #<?= (int) $_GET['created'] ?> created.</div>
<?php endif ?>

<?php if ($error): ?>
    <div class="banner banner-error"><?= h($error) ?></div>
<?php endif ?>

<section class="card">
    <h2 class="card-title">New document</h2>
    <form method="post">
        <div class="form-field">
            <label for="title">Title</label>
            <input type="text" id="title" name="title" required>
        </div>
        <div class="form-field">
            <label for="body">Body</label>
            <textarea id="body" name="body" required></textarea>
        </div>
        <div class="form-field">
            <label for="publish_at">Publish at (optional — leave blank to publish immediately)</label>
            <input type="datetime-local" id="publish_at" name="publish_at">
        </div>
        <button type="submit" class="btn">Create document</button>
    </form>
</section>

<section class="card">
    <h2 class="card-title">Documents</h2>
    <form method="get" class="search-form">
        <div class="form-field form-field-inline">
            <input type="text" name="q" placeholder="Search by title…" value="<?= h($search) ?>">
            <button type="submit" class="btn btn-small">Search</button>
            <?php if ($search !== ''): ?>
                <a href="/admin.php" class="btn-link">Clear</a>
            <?php endif ?>
        </div>
    </form>
    <?php if (empty($docs)): ?>
        <p class="empty"><?= $search !== '' ? 'No documents match your search.' : 'No documents yet.' ?></p>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Title</th>
                    <th>Creator</th>
                    <th>Created</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($docs as $d): ?>
                    <tr>
                        <td class="id"><?= h($d['slug'] ?? '#' . $d['id']) ?></td>
                        <td><?= h($d['title']) ?></td>
                        <td><?= h($d['creator_name']) ?></td>
                        <td><?= h($d['created_at']) ?></td>
                        <td>
                            <?php if (is_published($d)): ?>
                                <span class="status status-published">Published</span>
                            <?php else: ?>
                                <span class="status status-scheduled">Scheduled: <?= h($d['publish_at']) ?></span>
                            <?php endif ?>
                        </td>
                        <td><a href="/share.php?doc=<?= (int) $d['id'] ?>" class="btn-link">Create share →</a></td>
                    </tr>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</section>

<?php render_footer(); ?>
