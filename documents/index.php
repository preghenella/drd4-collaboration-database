<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
$title = trim((string) ($_GET['title'] ?? ''));
$reference = trim((string) ($_GET['reference'] ?? ''));
$version = $reference === '' ? '' : trim((string) ($_GET['version'] ?? ''));
$documents = $references = $versions = []; $error = null;
try {
    $database = documentsDatabase(); $context = accessContext(); $parameters = []; $access = accessSql($context, $parameters);
    $statement = $database->prepare("SELECT DISTINCT m.reference FROM document_metadata m WHERE $access ORDER BY m.reference");
    $statement->execute($parameters); $references = $statement->fetchAll(PDO::FETCH_COLUMN);
    if ($reference !== '') {
        $versionParameters = [':selected_reference' => $reference]; $versionAccess = accessSql($context, $versionParameters);
        $statement = $database->prepare("SELECT DISTINCT m.version FROM document_metadata m WHERE $versionAccess AND m.reference = :selected_reference ORDER BY m.version");
        $statement->execute($versionParameters); $versions = $statement->fetchAll(PDO::FETCH_COLUMN);
        if ($version !== '' && !in_array($version, $versions, true)) $version = '';
    }
    $parameters = []; $where = [accessSql($context, $parameters)];
    if ($title !== '') { $where[] = "m.title LIKE :title ESCAPE '\\\\'"; $parameters[':title'] = literalLikePattern($title); }
    if ($reference !== '') { $where[] = 'm.reference = :reference'; $parameters[':reference'] = $reference; }
    if ($version !== '') { $where[] = 'm.version = :version'; $parameters[':version'] = $version; }
    $statement = $database->prepare('SELECT m.id, m.reference, m.version, m.title, m.approved FROM document_metadata m WHERE ' . implode(' AND ', $where) . ' ORDER BY m.document_date DESC, m.reference, m.version');
    $statement->execute($parameters); $documents = $statement->fetchAll();
} catch (Throwable $exception) { error_log('Unable to list documents: ' . $exception->getMessage()); $error = 'The document list is temporarily unavailable.'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>DRD4 Document Database</title><link rel="stylesheet" href="assets/style.css"></head><body><main>
<h1>DRD4 Document Database</h1>
<?php if (identity() !== null): ?><p class="identity">Signed in as <?= h(identity()) ?><?php if (isAdmin()): ?> · <a href="admin/">Admin view</a><?php endif; ?></p><?php endif; ?>
<nav class="tabs" aria-label="Database sections"><a class="tab active" href="./" aria-current="page">Documents</a></nav>
<?php if ($error !== null): ?><p class="errors"><?= h($error) ?></p><?php else: ?>
<form class="toolbar" method="get" id="filters">
<label>Title <input type="search" name="title" value="<?= h($title) ?>" placeholder="Search titles"></label>
<label>Reference <select name="reference" id="reference"><option value="">All</option><?php foreach ($references as $item): ?><option value="<?= h((string) $item) ?>"<?= $reference === $item ? ' selected' : '' ?>><?= h((string) $item) ?></option><?php endforeach; ?></select></label>
<label>Version <select name="version" id="version"<?= $reference === '' ? ' disabled' : '' ?>><option value="">All</option><?php foreach ($versions as $item): ?><option value="<?= h((string) $item) ?>"<?= $version === $item ? ' selected' : '' ?>><?= h((string) $item) ?></option><?php endforeach; ?></select></label>
<button class="button" type="submit">Filter</button><a class="button secondary" href="./">Reset</a></form>
<p class="summary"><?= count($documents) ?> <?= count($documents) === 1 ? 'document' : 'documents' ?></p>
<div class="table-container"><table><thead><tr><th>Reference</th><th>Version</th><th>Title</th><th>Approved</th></tr></thead><tbody>
<?php if ($documents === []): ?><tr><td class="empty" colspan="4">No documents match the selected filters.</td></tr><?php endif; ?>
<?php foreach ($documents as $document): ?><tr><td><?= h($document['reference']) ?></td><td><?= h($document['version']) ?></td><td><a href="document.php?id=<?= (int) $document['id'] ?>"><?= h($document['title']) ?></a></td><td><?php if ((bool) $document['approved']): ?><span class="status-check" aria-label="Approved">&#10003;</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><script>document.getElementById('reference').addEventListener('change',function(){const v=document.getElementById('version');v.value='';v.disabled=!this.value;document.getElementById('filters').submit();});</script>
<?php endif; ?></main></body></html>
