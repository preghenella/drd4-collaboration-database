<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
$id = validId($_GET['id'] ?? null); $document = null; $agencyName = null;
if ($id === null) { http_response_code(400); $error = 'Invalid document ID.'; }
else {
    try {
        $document = findAccessibleDocument(documentsDatabase(), $id);
        if ($document === null) { http_response_code(404); $error = 'The document was not found or you do not have access to it.'; }
        else {
            $error = null;
            if ($document['access_level'] === 'funding_agency' && $document['funding_agency_id'] !== null) {
                $collaboration = collaborationDatabase();
                if ($collaboration !== null) { $s = $collaboration->prepare('SELECT name FROM agencies WHERE id = ?'); $s->execute([$document['funding_agency_id']]); $agencyName = $s->fetchColumn() ?: null; }
            }
        }
    } catch (Throwable $exception) { error_log('Unable to load document: ' . $exception->getMessage()); http_response_code(500); $error = 'The document is temporarily unavailable.'; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Document details</title><link rel="stylesheet" href="assets/style.css"></head><body><main><h1>Document details</h1>
<?php if ($error !== null): ?><p class="errors"><?= h($error) ?></p><a class="button secondary" href="./">Back to documents</a><?php else: ?>
<section class="panel"><dl class="details"><dt>ID</dt><dd><?= (int) $document['id'] ?></dd><dt>Reference</dt><dd><?= h($document['reference']) ?></dd><dt>Version</dt><dd><?= h($document['version']) ?></dd><dt>Title</dt><dd><?= h($document['title']) ?></dd><dt>Document date</dt><dd><?= h(formatDate($document['document_date'])) ?></dd><dt>Uploaded at</dt><dd><?= h(formatDate($document['uploaded_at'], true)) ?></dd><dt>Approved</dt><dd><?php if ((bool) $document['approved']): ?><span class="status-check" aria-label="Approved">&#10003;</span><?php endif; ?></dd><dt>Approval date</dt><dd><?= h(formatDate($document['approval_date'])) ?></dd><dt>Access level</dt><dd><?= h(DOCUMENT_ACCESS_LEVELS[$document['access_level']] ?? $document['access_level']) ?></dd><?php if ($document['access_level'] === 'funding_agency'): ?><dt>Funding Agency</dt><dd><?= h($agencyName ?? $document['funding_agency_id']) ?></dd><?php endif; ?></dl></section>
<div class="actions" style="margin-top:1rem"><a class="button" target="_blank" rel="noopener" href="pdf.php?id=<?= (int) $document['id'] ?>">Open PDF</a><a class="button" href="pdf.php?id=<?= (int) $document['id'] ?>&amp;download=1">Download PDF</a><a class="button secondary" href="./">Back to documents</a></div>
<?php endif; ?></main></body></html>
