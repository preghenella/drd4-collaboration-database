<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';
$id = validId($_GET['id'] ?? null);
if ($id === null) { http_response_code(400); exit('Invalid document ID.'); }
try { $document = findAccessibleDocument(documentsDatabase(), $id, true); }
catch (Throwable $exception) { error_log('Unable to serve PDF: ' . $exception->getMessage()); http_response_code(500); exit('The document is temporarily unavailable.'); }
if ($document === null) { http_response_code(404); exit('The document was not found or you do not have access to it.'); }
$filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename((string) $document['filename'])) ?: 'document.pdf';
$disposition = isset($_GET['download']) && $_GET['download'] === '1' ? 'attachment' : 'inline';
header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($document['pdf_data']));
header("Content-Disposition: $disposition; filename=\"$filename\"; filename*=UTF-8''" . rawurlencode($filename));
header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store'); echo $document['pdf_data'];
