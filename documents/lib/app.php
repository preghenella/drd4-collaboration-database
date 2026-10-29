<?php
declare(strict_types=1);

const DOCUMENT_ACCESS_LEVELS = [
    'public' => 'Public', 'rb_members' => 'Resources Board members',
    'rb_full_members' => 'Full Resources Board members',
    'funding_agency' => 'Funding agency', 'coordination' => 'Coordination',
];

function config(string $name) {
    $value = getenv($name);
    if ($value !== false && $value !== '') return $value;
    foreach ([$_ENV, $_SERVER] as $source) {
        foreach ([$name, 'REDIRECT_' . $name] as $candidate) {
            if (isset($source[$candidate]) && $source[$candidate] !== '') return (string) $source[$candidate];
        }
    }
    if (function_exists('apache_getenv')) {
        $value = apache_getenv($name, true);
        if ($value !== false && $value !== '') return $value;
    }
    return false;
}
function h(?string $value): string { return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function identity(): ?string {
    $value = config('REMOTE_USER') ?: config('OIDC_CLAIM_preferred_username')
        ?: config('OIDC_CLAIM_cern_upn') ?: config('HTTP_X_FORWARDED_USER');
    return $value === false ? null : trim((string) $value);
}
function configuredUsers(string $name): array {
    $value = config($name);
    return $value === false ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $value))));
}
function userIsConfigured(string $name, ?string $user = null): bool {
    $user ??= identity();
    return $user !== null && in_array($user, configuredUsers($name), true);
}
function isAdmin(): bool { return userIsConfigured('ADMIN_USERS'); }
function requireAdmin(): string {
    $user = identity();
    if ($user === null || !isAdmin()) { http_response_code(403); exit('You are not authorized to administer the document database.'); }
    return $user;
}
function pdo(
    string $userVariable,
    string $passwordVariable,
    string $database,
    string $hostVariable = 'MYSQL_HOST',
    string $portVariable = 'MYSQL_PORT'
): PDO {
    $user = config($userVariable); $password = config($passwordVariable);
    if ($user === false || $password === false) throw new RuntimeException('Database credentials are not configured.');
    $host = config($hostVariable) ?: 'dbod-drd4-collaboration-database.cern.ch';
    $port = config($portVariable) ?: '5547';
    return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database), (string) $user, (string) $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}
function documentsDatabase(bool $write = false): PDO {
    return pdo(
        $write ? 'DOCUMENTS_MYSQL_WRITE_USER' : 'DOCUMENTS_MYSQL_USER',
        $write ? 'DOCUMENTS_MYSQL_WRITE_PASSWORD' : 'DOCUMENTS_MYSQL_PASSWORD',
        (string) (config('DOCUMENTS_MYSQL_DATABASE') ?: 'drd4_documents'),
        'DOCUMENTS_MYSQL_HOST',
        'DOCUMENTS_MYSQL_PORT'
    );
}
function collaborationDatabase(): ?PDO {
    try { return pdo('COLLABORATION_MYSQL_USER', 'COLLABORATION_MYSQL_PASSWORD', (string) (config('COLLABORATION_MYSQL_DATABASE') ?: 'drd4')); }
    catch (Throwable $exception) { error_log('Collaboration role lookup unavailable: ' . $exception->getMessage()); return null; }
}
function identityCandidates(?string $user): array {
    if ($user === null) return [];
    $values = [$user]; $email = config('HTTP_X_FORWARDED_EMAIL');
    if ($email !== false) $values[] = trim((string) $email);
    return array_values(array_unique(array_filter($values)));
}
function accessContext(): array {
    $user = identity();
    $context = ['coordination' => userIsConfigured('COORDINATION_USERS', $user), 'rb_member' => false,
        'rb_full_member' => userIsConfigured('RB_FULL_MEMBERS', $user), 'funding_agencies' => []];
    if ($context['coordination']) return $context;
    $candidates = identityCandidates($user); $database = $candidates === [] ? null : collaborationDatabase();
    if ($database === null) return $context;
    $placeholders = implode(',', array_fill(0, count($candidates), '?'));
    try {
        $values = array_map('strtolower', $candidates);
        $statement = $database->prepare("SELECT id FROM agencies WHERE LOWER(email) IN ($placeholders)");
        $statement->execute($values);
        $context['funding_agencies'] = array_map(static fn(array $row): string => (string) $row['id'], $statement->fetchAll());
        $statement = $database->prepare("SELECT 1 FROM resources_board WHERE LOWER(email) IN ($placeholders) LIMIT 1");
        $statement->execute($values); $context['rb_member'] = $statement->fetchColumn() !== false;
    } catch (Throwable $exception) { error_log('Unable to resolve document roles: ' . $exception->getMessage()); }
    $context['rb_member'] = $context['rb_member'] || $context['rb_full_member'];
    return $context;
}
function accessSql(array $context, array &$parameters, string $alias = 'm'): string {
    $parts = ["$alias.access_level = 'public'"];
    if ($context['coordination']) $parts[] = "$alias.access_level IN ('rb_members','rb_full_members','funding_agency','coordination')";
    else {
        if ($context['rb_member']) $parts[] = "$alias.access_level = 'rb_members'";
        if ($context['rb_full_member']) $parts[] = "$alias.access_level = 'rb_full_members'";
        if ($context['funding_agencies'] !== []) {
            $marks = [];
            foreach ($context['funding_agencies'] as $agency) { $name = ':agency_' . count($parameters); $parameters[$name] = $agency; $marks[] = $name; }
            $parts[] = "$alias.access_level = 'funding_agency' AND $alias.funding_agency_id IN (" . implode(',', $marks) . ')';
        }
    }
    return '(' . implode(' OR ', array_map(static fn(string $part): string => "($part)", $parts)) . ')';
}
function findAccessibleDocument(PDO $database, int $id, bool $withFile = false): ?array {
    $parameters = [':id' => $id]; $access = accessSql(accessContext(), $parameters);
    $columns = 'm.id, m.reference, m.version, m.title, m.document_date, m.uploaded_at, m.approved, m.approval_date, m.access_level, m.funding_agency_id';
    if ($withFile) $columns .= ', f.filename, f.pdf_data';
    $sql = "SELECT $columns FROM document_metadata m" . ($withFile ? ' JOIN document_files f ON f.document_id = m.id' : '');
    $statement = $database->prepare("$sql WHERE m.id = :id AND $access"); $statement->execute($parameters);
    $document = $statement->fetch(); return $document === false ? null : $document;
}
function validId($value): ?int {
    $filtered = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $filtered === false ? null : (int) $filtered;
}
function startSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_set_cookie_params(['secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Strict']); session_start();
}
function csrfToken(): string {
    startSecureSession(); if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); return $_SESSION['csrf_token'];
}
function verifyCsrf(): void {
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) { http_response_code(400); exit('Invalid form token. Reload the form and try again.'); }
}
function formatDate(?string $date, bool $withTime = false): string {
    if ($date === null || $date === '') return '—';
    try { return (new DateTimeImmutable($date))->format($withTime ? 'j F Y, H:i' : 'j F Y'); } catch (Throwable $exception) { return $date; }
}
function validateDateValue(string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date); $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $parsed->format('Y-m-d') === $date;
}
function literalLikePattern(string $value): string {
    return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value) . '%';
}
function fundingAgencies(): array {
    $database = collaborationDatabase(); if ($database === null) return [];
    try { return $database->query('SELECT id, code, name, country FROM agencies ORDER BY country, name')->fetchAll(); }
    catch (Throwable $exception) { error_log('Unable to list funding agencies: ' . $exception->getMessage()); return []; }
}
