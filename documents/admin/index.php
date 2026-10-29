<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/app.php';
startSecureSession(); $editor = requireAdmin(); $errors = []; $notice = $_SESSION['notice'] ?? null; unset($_SESSION['notice']);
$action = (string) ($_GET['action'] ?? $_POST['action'] ?? 'list'); $id = validId($_GET['id'] ?? $_POST['id'] ?? null);
$agencies = fundingAgencies(); $agencyIds = array_map(static fn(array $a): string => (string) $a['id'], $agencies);
$form = ['reference' => '', 'version' => '', 'title' => '', 'document_date' => '', 'access_level' => 'coordination', 'funding_agency_id' => '', 'approved' => '0', 'approval_date' => ''];
try { $database = documentsDatabase(true); }
catch (Throwable $exception) { error_log('Unable to connect to document writer: ' . $exception->getMessage()); http_response_code(500); exit('The document administration interface is temporarily unavailable.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    if ($action === 'upload') {
        foreach ($form as $name => $unused) $form[$name] = $name === 'approved' ? (isset($_POST[$name]) ? '1' : '0') : trim((string) ($_POST[$name] ?? ''));
        foreach (['reference' => 'Reference', 'version' => 'Version', 'title' => 'Title', 'document_date' => 'Document date'] as $name => $label) if ($form[$name] === '') $errors[] = "$label is required.";
        if (strlen($form['reference']) > 256 || strlen($form['version']) > 32 || strlen($form['title']) > 512) $errors[] = 'One or more text fields exceed the database limit.';
        if ($form['document_date'] !== '' && !validateDateValue($form['document_date'])) $errors[] = 'Document date must be a valid date.';
        if (!isset(DOCUMENT_ACCESS_LEVELS[$form['access_level']])) $errors[] = 'Select a valid access level.';
        if ($form['access_level'] === 'funding_agency' && !in_array($form['funding_agency_id'], $agencyIds, true)) $errors[] = 'Select a valid funding agency.';
        if ($form['approved'] === '1' && ($form['approval_date'] === '' || !validateDateValue($form['approval_date']))) $errors[] = 'A valid approval date is required for an approved document.';
        $file = $_FILES['pdf'] ?? null; $limit = (int) (config('DOCUMENT_MAX_UPLOAD_BYTES') ?: 20 * 1024 * 1024);
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) $errors[] = 'Select a PDF that uploaded successfully.';
        elseif ((int) $file['size'] < 1 || (int) $file['size'] > $limit) $errors[] = 'The PDF exceeds the configured size limit (' . round($limit / 1048576) . ' MiB).';
        else {
            $path = (string) $file['tmp_name']; $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path); $prefix = file_get_contents($path, false, null, 0, 5);
            if ($mime !== 'application/pdf' || $prefix !== '%PDF-') $errors[] = 'The uploaded file is not a valid PDF.';
        }
        if ($errors === []) {
            $database->beginTransaction();
            try {
                $statement = $database->prepare('INSERT INTO document_metadata (reference, version, title, document_date, approved, approval_date, access_level, funding_agency_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $statement->execute([$form['reference'], $form['version'], $form['title'], $form['document_date'], $form['approved'] === '1' ? 1 : 0, $form['approved'] === '1' ? $form['approval_date'] : null, $form['access_level'], $form['access_level'] === 'funding_agency' ? $form['funding_agency_id'] : null]);
                $documentId = (int) $database->lastInsertId(); $pdf = file_get_contents((string) $file['tmp_name']);
                if ($pdf === false) throw new RuntimeException('Unable to read uploaded PDF.');
                $statement = $database->prepare('INSERT INTO document_files (document_id, filename, pdf_data) VALUES (?, ?, ?)');
                $statement->bindValue(1, $documentId, PDO::PARAM_INT); $statement->bindValue(2, basename((string) $file['name']), PDO::PARAM_STR); $statement->bindValue(3, $pdf, PDO::PARAM_LOB); $statement->execute();
                $database->commit(); $_SESSION['notice'] = 'Document uploaded successfully.'; header('Location: ./'); exit;
            } catch (PDOException $exception) {
                if ($database->inTransaction()) $database->rollBack();
                if ($exception->getCode() === '23000') $errors[] = 'That reference and version already exist.';
                else { error_log('Document upload failed: ' . $exception->getMessage()); $errors[] = 'The document could not be uploaded.'; }
            } catch (Throwable $exception) { if ($database->inTransaction()) $database->rollBack(); error_log('Document upload failed: ' . $exception->getMessage()); $errors[] = 'The document could not be uploaded.'; }
        }
    } elseif ($action === 'approval') {
        if ($id === null) $errors[] = 'Invalid document ID.';
        $approved = isset($_POST['approved']); $approvalDate = trim((string) ($_POST['approval_date'] ?? ''));
        if ($approved && ($approvalDate === '' || !validateDateValue($approvalDate))) $errors[] = 'A valid approval date is required when approved.';
        if ($errors === []) {
            try {
                $exists = $database->prepare('SELECT 1 FROM document_metadata WHERE id = ?');
                $exists->execute([$id]);
                if ($exists->fetchColumn() === false) throw new RuntimeException('Document not found.');
                $statement = $database->prepare('UPDATE document_metadata SET approved = ?, approval_date = ? WHERE id = ?');
                $statement->execute([$approved ? 1 : 0, $approved ? $approvalDate : null, $id]);
                $_SESSION['notice'] = 'Approval updated.'; header('Location: ./'); exit;
            }
            catch (Throwable $exception) { error_log('Approval update failed: ' . $exception->getMessage()); $errors[] = 'The approval could not be updated.'; }
        }
    } elseif ($action === 'delete') {
        if ($id === null || ($_POST['confirm'] ?? '') !== 'yes') $errors[] = 'Explicit deletion confirmation is required.';
        if ($errors === []) {
            try { $statement = $database->prepare('DELETE FROM document_metadata WHERE id = ?'); $statement->execute([$id]); if ($statement->rowCount() !== 1) throw new RuntimeException('Document not found.'); $_SESSION['notice'] = 'Document deleted.'; header('Location: ./'); exit; }
            catch (Throwable $exception) { error_log('Document deletion failed: ' . $exception->getMessage()); $errors[] = 'The document could not be deleted.'; }
        }
    }
}
$selected = null;
if ($id !== null && in_array($action, ['approval', 'delete'], true)) { $statement = $database->prepare('SELECT id, reference, version, title, approved, approval_date FROM document_metadata WHERE id = ?'); $statement->execute([$id]); $selected = $statement->fetch() ?: null; if ($selected === null) { http_response_code(404); $errors[] = 'Document not found.'; } }
try { $documents = $database->query('SELECT id, reference, version, title, document_date, approved, approval_date, access_level FROM document_metadata ORDER BY document_date DESC, reference, version')->fetchAll(); }
catch (Throwable $exception) { error_log('Unable to list admin documents: ' . $exception->getMessage()); $documents = []; $errors[] = 'The document list could not be loaded.'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Manage DRD4 Documents</title><link rel="stylesheet" href="../assets/style.css"></head><body><main>
<h1>Manage DRD4 Documents</h1><p class="identity">Signed in as <?= h($editor) ?> · <a href="../">Public view</a></p><nav class="tabs"><a class="tab active" href="./">Documents</a></nav>
<?php if ($notice !== null): ?><p class="notice"><?= h($notice) ?></p><?php endif; ?><?php if ($errors !== []): ?><div class="errors"><strong>Please correct the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="layout"><section class="panel">
<?php if ($action === 'approval' && $selected !== null): ?><h2>Update approval</h2><p><strong><?= h($selected['reference']) ?> <?= h($selected['version']) ?></strong><br><?= h($selected['title']) ?></p><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="approval"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><label class="checkbox-field"><input type="checkbox" name="approved" value="1"<?= (bool) $selected['approved'] ? ' checked' : '' ?>> Approved</label><label>Approval date <input type="date" name="approval_date" value="<?= h($selected['approval_date']) ?>"></label><div class="actions"><button class="button">Save approval</button><a class="button secondary" href="./">Cancel</a></div></form>
<?php elseif ($action === 'delete' && $selected !== null): ?><h2>Delete document</h2><p>This permanently deletes <strong><?= h($selected['reference']) ?> <?= h($selected['version']) ?></strong>: <?= h($selected['title']) ?> and its PDF.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $selected['id'] ?>"><input type="hidden" name="confirm" value="yes"><div class="actions"><button class="button danger">Confirm deletion</button><a class="button secondary" href="./">Cancel</a></div></form>
<?php else: ?><h2>Upload new document</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= h(csrfToken()) ?>"><input type="hidden" name="action" value="upload"><label>PDF * <input type="file" name="pdf" accept="application/pdf,.pdf" required></label><label>Reference * <input name="reference" maxlength="256" value="<?= h($form['reference']) ?>" required></label><label>Version * <input name="version" maxlength="32" value="<?= h($form['version']) ?>" required></label><label>Title * <input name="title" maxlength="512" value="<?= h($form['title']) ?>" required></label><label>Document date * <input type="date" name="document_date" value="<?= h($form['document_date']) ?>" required></label><label>Access level * <select name="access_level" id="access-level"><?php foreach (DOCUMENT_ACCESS_LEVELS as $value => $label): ?><option value="<?= h($value) ?>"<?= $form['access_level'] === $value ? ' selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></label><label id="agency-field">Funding Agency * <select name="funding_agency_id"><option value="">Select…</option><?php foreach ($agencies as $agency): ?><option value="<?= h((string) $agency['id']) ?>"<?= $form['funding_agency_id'] === (string) $agency['id'] ? ' selected' : '' ?>><?= h(trim(($agency['code'] ? $agency['code'] . ' — ' : '') . $agency['name'] . ' (' . $agency['country'] . ')')) ?></option><?php endforeach; ?></select></label><label class="checkbox-field"><input type="checkbox" name="approved" value="1"<?= $form['approved'] === '1' ? ' checked' : '' ?>> Approved</label><label>Approval date <input type="date" name="approval_date" value="<?= h($form['approval_date']) ?>"></label><button class="button">Upload document</button></form><script>const a=document.getElementById('access-level'),f=document.getElementById('agency-field');function show(){f.hidden=a.value!=='funding_agency'}a.addEventListener('change',show);show();</script><?php endif; ?>
</section><section class="panel"><h2>Existing documents</h2><div class="table-container"><table><thead><tr><th>Reference</th><th>Version</th><th>Title</th><th>Approved</th><th>Actions</th></tr></thead><tbody><?php if ($documents === []): ?><tr><td class="empty" colspan="5">No documents.</td></tr><?php endif; ?><?php foreach ($documents as $document): ?><tr><td><?= h($document['reference']) ?></td><td><?= h($document['version']) ?></td><td><?= h($document['title']) ?></td><td><?php if ((bool) $document['approved']): ?><span class="status-check" aria-label="Approved">&#10003;</span><?php endif; ?></td><td><a href="?action=approval&amp;id=<?= (int) $document['id'] ?>">Approval</a> · <a href="?action=delete&amp;id=<?= (int) $document['id'] ?>">Delete</a></td></tr><?php endforeach; ?></tbody></table></div></section></div>
</main></body></html>
