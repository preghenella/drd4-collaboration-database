<?php

declare(strict_types=1);

session_set_cookie_params([
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

function escapeHtml(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function configurationValue(string $name)
{
    $value = getenv($name);
    if ($value !== false && $value !== '') {
        return $value;
    }
    foreach ([$_ENV, $_SERVER] as $source) {
        foreach ([$name, 'REDIRECT_' . $name] as $candidate) {
            if (isset($source[$candidate]) && $source[$candidate] !== '') {
                return (string) $source[$candidate];
            }
        }
    }
    if (function_exists('apache_getenv')) {
        $value = apache_getenv($name, true);
        if ($value !== false && $value !== '') {
            return $value;
        }
    }
    return false;
}

function requireEditor(): string
{
    $identity = configurationValue('REMOTE_USER')
        ?: configurationValue('OIDC_CLAIM_preferred_username')
        ?: configurationValue('OIDC_CLAIM_cern_upn')
        ?: configurationValue('HTTP_X_FORWARDED_USER');
    $configured = configurationValue('ADMIN_USERS');

    if ($configured === false) {
        http_response_code(503);
        exit('Database editing is disabled: ADMIN_USERS is not configured.');
    }
    $allowed = array_filter(array_map('trim', explode(',', $configured)));
    if ($identity === false || !in_array($identity, $allowed, true)) {
        http_response_code(403);
        exit('You are not authorized to edit this database.');
    }
    return $identity;
}

function database(): PDO
{
    $user = configurationValue('MYSQL_WRITE_USER');
    $password = configurationValue('MYSQL_WRITE_PASSWORD');
    if ($user === false || $password === false) {
        throw new RuntimeException('Dedicated database writer credentials are not configured.');
    }
    $host = configurationValue('MYSQL_HOST') ?: 'dbod-drd4-collaboration-database.cern.ch';
    $port = configurationValue('MYSQL_PORT') ?: '5547';
    $name = configurationValue('MYSQL_DATABASE') ?: 'drd4';
    return new PDO(
        sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name),
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(): void
{
    $provided = $_POST['csrf_token'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        http_response_code(400);
        exit('Invalid form token. Reload the form and try again.');
    }
}

function nullable(string $value): ?string
{
    $value = trim($value);
    return $value === '' ? null : $value;
}

function listJson(string $value): string
{
    $items = preg_split('/[\r\n,]+/', $value) ?: [];
    $items = array_values(array_unique(array_filter(array_map('trim', $items))));
    return json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function listText(?string $json): string
{
    $values = json_decode($json ?: '[]', true);
    return is_array($values) ? implode("\n", array_filter($values, 'is_string')) : '';
}

function validateEmail(?string $email, string $label, array &$errors): void
{
    if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors[] = $label . ' is not a valid email address.';
    }
}

function audit(
    PDO $database,
    string $table,
    string $key,
    string $action,
    ?array $old,
    array $new,
    string $editor
): void {
    $statement = $database->prepare(
        'INSERT INTO audit_log
         (table_name, record_key, action, old_values, new_values, changed_by)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->execute([
        $table,
        $key,
        $action,
        $old === null ? null : json_encode($old, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        json_encode($new, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        $editor,
    ]);
}

$definitions = [
    'institutions' => [
        'label' => 'Institution',
        'tab_label' => 'Institutions',
        'table' => 'institutions',
        'key' => 'code',
        'fields' => [
            'code' => ['label' => 'Code', 'required' => true],
            'name' => ['label' => 'Institution', 'required' => true],
            'country' => ['label' => 'Country', 'required' => true],
            'town' => ['label' => 'Town'],
            'contact' => ['label' => 'Contact'],
        ],
    ],
    'agencies' => [
        'label' => 'Funding agency',
        'tab_label' => 'Funding agencies',
        'table' => 'agencies',
        'key' => 'id',
        'fields' => [
            'code' => ['label' => 'Code'],
            'name' => ['label' => 'Funding agency', 'required' => true],
            'country' => ['label' => 'Country', 'required' => true],
            'representative' => ['label' => 'Representative'],
            'email' => ['label' => 'Representative email', 'type' => 'email'],
            'function' => ['label' => 'Function'],
            'institutions_represented' => ['label' => 'Institution codes', 'type' => 'list'],
            'mou_signed' => ['label' => 'MoU signed', 'type' => 'checkbox'],
        ],
    ],
    'resources-board' => [
        'label' => 'Resources Board member',
        'tab_label' => 'Resources Board',
        'table' => 'resources_board',
        'key' => 'id',
        'fields' => [
            'code' => ['label' => 'Code'],
            'name' => ['label' => 'Founding agency', 'required' => true],
            'country' => ['label' => 'Country', 'required' => true],
            'representative' => ['label' => 'Representative'],
            'email' => ['label' => 'Email', 'type' => 'email'],
        ],
    ],
    'deliverables' => [
        'label' => 'Deliverable',
        'tab_label' => 'Deliverables',
        'table' => 'deliverables',
        'key' => 'id',
        'fields' => [
            'work_package' => ['label' => 'Work package', 'required' => true, 'type' => 'number'],
            'number' => ['label' => 'Number', 'required' => true, 'type' => 'number'],
            'title' => ['label' => 'Title', 'required' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
            'status' => ['label' => 'Status'],
            'institutions' => ['label' => 'Participating institution codes', 'type' => 'list'],
        ],
    ],
];

$editor = requireEditor();
$requestedView = $_GET['view'] ?? $_POST['view'] ?? 'institutions';
$view = isset($definitions[$requestedView]) ? $requestedView : 'institutions';
$definition = $definitions[$view];
try {
    $database = database();
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('Unable to connect to the DRD4 database: ' . $exception->getMessage());
    exit('The database administration interface is temporarily unavailable.');
}
$errors = [];
$loadError = null;
$record = array_fill_keys(array_keys($definition['fields']), '');
$editing = isset($_GET['id']) && $_GET['id'] !== '';
$recordKey = $editing ? (string) $_GET['id'] : '';
$recordVersion = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $editing = ($_POST['editing'] ?? '') === '1';
    $recordKey = trim((string) ($_POST['record_key'] ?? ''));
    $recordVersion = trim((string) ($_POST['record_version'] ?? ''));
    foreach ($definition['fields'] as $name => $field) {
        $record[$name] = ($field['type'] ?? '') === 'checkbox'
            ? (isset($_POST[$name]) ? '1' : '0')
            : trim((string) ($_POST[$name] ?? ''));
        if (($field['required'] ?? false) && $record[$name] === '') {
            $errors[] = $field['label'] . ' is required.';
        }
        if (($field['type'] ?? '') === 'email') {
            validateEmail(nullable($record[$name]), $field['label'], $errors);
        }
        if (($field['type'] ?? '') === 'number'
            && (!ctype_digit($record[$name]) || (int) $record[$name] < 1)) {
            $errors[] = $field['label'] . ' must be a positive integer.';
        }
    }
    if ($view === 'deliverables' && (int) $record['work_package'] > 5) {
        $errors[] = 'Work package must be between 1 and 5.';
    }

    if ($errors === []) {
        $values = [];
        foreach ($definition['fields'] as $name => $field) {
            if (($field['type'] ?? '') === 'list') {
                $values[$name] = listJson($record[$name]);
            } elseif (($field['type'] ?? '') === 'checkbox') {
                $values[$name] = $record[$name] === '1' ? 1 : 0;
            } else {
                $values[$name] = nullable($record[$name]);
            }
        }
        $database->beginTransaction();
        try {
            $old = null;
            if ($editing) {
                $select = $database->prepare(
                    sprintf('SELECT * FROM `%s` WHERE `%s` = ? FOR UPDATE', $definition['table'], $definition['key'])
                );
                $select->execute([$recordKey]);
                $old = $select->fetch();
                if ($old === false) {
                    throw new RuntimeException('The record no longer exists.');
                }
                $currentVersion = hash('sha256', json_encode(
                    $old,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                ));
                if ($recordVersion === '' || !hash_equals($recordVersion, $currentVersion)) {
                    throw new RuntimeException(
                        'This record was changed after the form was opened. Reload it and try again.'
                    );
                }
                $assignments = implode(', ', array_map(
                    static fn(string $name): string => sprintf('`%s` = ?', $name),
                    array_keys($values)
                ));
                $statement = $database->prepare(sprintf(
                    'UPDATE `%s` SET %s WHERE `%s` = ?',
                    $definition['table'],
                    $assignments,
                    $definition['key']
                ));
                $statement->execute([...array_values($values), $recordKey]);
                $newKey = (string) ($values[$definition['key']] ?? $recordKey);
                $action = 'update';
            } else {
                $columns = array_keys($values);
                $statement = $database->prepare(sprintf(
                    'INSERT INTO `%s` (`%s`) VALUES (%s)',
                    $definition['table'],
                    implode('`, `', $columns),
                    implode(', ', array_fill(0, count($columns), '?'))
                ));
                $statement->execute(array_values($values));
                $newKey = $definition['key'] === 'id'
                    ? $database->lastInsertId()
                    : (string) $values[$definition['key']];
                $action = 'insert';
            }
            audit($database, $definition['table'], $newKey, $action, $old ?: null, $values, $editor);
            $database->commit();
            $_SESSION['notice'] = $definition['label'] . ' saved successfully.';
            header('Location: ./?view=' . rawurlencode($view) . '&id=' . rawurlencode($newKey));
            exit;
        } catch (Throwable $exception) {
            $database->rollBack();
            error_log('Unable to save DRD4 data: ' . $exception->getMessage());
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                $errors[] = 'That code or name is already in use.';
            } elseif (str_starts_with($exception->getMessage(), 'This record was changed')) {
                $errors[] = $exception->getMessage();
            } else {
                $errors[] = 'The record could not be saved. Check that the audit table migration has been applied.';
            }
        }
    }
} elseif ($editing) {
    try {
        $statement = $database->prepare(sprintf(
            'SELECT * FROM `%s` WHERE `%s` = ?',
            $definition['table'],
            $definition['key']
        ));
        $statement->execute([$recordKey]);
        $stored = $statement->fetch();
        if ($stored === false) {
            http_response_code(404);
            $loadError = 'The requested record was not found.';
            $editing = false;
        } else {
            $recordVersion = hash('sha256', json_encode(
                $stored,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ));
            foreach ($definition['fields'] as $name => $field) {
                $record[$name] = ($field['type'] ?? '') === 'list'
                    ? listText($stored[$name] ?? null)
                    : (string) ($stored[$name] ?? '');
            }
        }
    } catch (Throwable $exception) {
        error_log('Unable to load DRD4 record: ' . $exception->getMessage());
        $loadError = 'This record could not be loaded. Check the server log for details.';
        $editing = false;
    }
}

$listColumns = array_keys($definition['fields']);
$rows = [];
try {
    $listStatement = $database->query(sprintf(
        'SELECT `%s` FROM `%s` ORDER BY `%s` LIMIT 500',
        implode('`, `', array_unique([$definition['key'], ...$listColumns])),
        $definition['table'],
        isset($definition['fields']['country']) ? 'country' : $definition['key']
    ));
    $rows = $listStatement->fetchAll();
} catch (Throwable $exception) {
    error_log('Unable to list DRD4 records: ' . $exception->getMessage());
    $loadError = 'This table could not be loaded. Check the server log for details.';
}
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage DRD4 Collaboration Database</title>
    <style>
        :root { font-family: Arial, sans-serif; color: #202124; background: #f4f6f8; }
        body { margin: 0; }
        main { max-width: 1400px; margin: auto; padding: 2rem 1rem 3rem; }
        h1, h2 { color: #17365d; }
        .tabs { display: flex; gap: .25rem; margin: 1.25rem 0; border-bottom: 2px solid #17365d; }
        .tab { padding: .7rem 1rem; border-radius: .35rem .35rem 0 0; color: #17365d; background: #e5eaf0; text-decoration: none; font-weight: bold; }
        .tab:hover, .tab:focus { background: #d5dfeb; }
        .tab.active { color: white; background: #17365d; }
        .button { padding: .65rem .9rem; border: 0; border-radius: .35rem; color: white; background: #17365d; text-decoration: none; cursor: pointer; font-weight: bold; }
        .layout { display: grid; grid-template-columns: minmax(20rem, 1fr) minmax(30rem, 2fr); gap: 1.5rem; align-items: start; }
        .panel { padding: 1.2rem; border: 1px solid #c8d0da; border-radius: .4rem; background: white; }
        label { display: block; margin: .8rem 0; font-weight: bold; }
        input, textarea { box-sizing: border-box; width: 100%; margin-top: .3rem; padding: .55rem; border: 1px solid #9aa4af; border-radius: .3rem; font: inherit; }
        .checkbox-field { display: flex; align-items: center; gap: .5rem; }
        .checkbox-field input { width: auto; margin: 0; }
        textarea { min-height: 7rem; resize: vertical; }
        .actions { display: flex; gap: .6rem; margin-top: 1rem; }
        .secondary { color: #17365d; background: #e5eaf0; }
        .notice, .errors { padding: .8rem 1rem; border-radius: .35rem; }
        .notice { background: #e6f4ea; color: #176b31; }
        .errors { background: #fce8e6; color: #8c1d18; }
        .identity { color: #5f6368; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: .55rem; border-bottom: 1px solid #dfe3e8; text-align: left; vertical-align: top; }
        th { color: white; background: #17365d; }
        td { max-width: 24rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 900px) { .layout { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<main>
    <h1>Manage DRD4 Collaboration Database</h1>
    <p class="identity">Signed in as <?= escapeHtml($editor) ?> · <a href="../?view=<?= escapeHtml($view) ?>">Public view</a></p>
    <nav class="tabs" aria-label="Database tables">
    <?php foreach ($definitions as $name => $item): ?>
        <a
            class="tab<?= $view === $name ? ' active' : '' ?>"
            href="?view=<?= escapeHtml($name) ?>"
            <?= $view === $name ? 'aria-current="page"' : '' ?>
        ><?= escapeHtml($item['tab_label']) ?></a>
    <?php endforeach; ?>
    </nav>
    <?php if ($notice !== null): ?><p class="notice"><?= escapeHtml($notice) ?></p><?php endif; ?>
    <?php if ($loadError !== null): ?><p class="errors"><?= escapeHtml($loadError) ?></p><?php endif; ?>
    <?php if ($errors !== []): ?>
        <div class="errors"><strong>Please correct the following:</strong><ul>
        <?php foreach ($errors as $error): ?><li><?= escapeHtml($error) ?></li><?php endforeach; ?>
        </ul></div>
    <?php endif; ?>
    <div class="layout">
        <section class="panel">
            <h2><?= $editing ? 'Edit' : 'Add' ?> <?= escapeHtml($definition['label']) ?></h2>
            <form method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= escapeHtml(csrfToken()) ?>">
                <input type="hidden" name="view" value="<?= escapeHtml($view) ?>">
                <input type="hidden" name="editing" value="<?= $editing ? '1' : '0' ?>">
                <input type="hidden" name="record_key" value="<?= escapeHtml($recordKey) ?>">
                <input type="hidden" name="record_version" value="<?= escapeHtml($recordVersion) ?>">
                <?php foreach ($definition['fields'] as $name => $field): ?>
                    <?php if (($field['type'] ?? '') === 'checkbox'): ?>
                    <label class="checkbox-field">
                        <input
                            type="checkbox"
                            name="<?= escapeHtml($name) ?>"
                            value="1"
                            <?= $record[$name] === '1' ? 'checked' : '' ?>
                        >
                        <?= escapeHtml($field['label']) ?>
                    </label>
                    <?php else: ?>
                    <label>
                        <?= escapeHtml($field['label']) ?><?= ($field['required'] ?? false) ? ' *' : '' ?>
                        <?php if (in_array($field['type'] ?? '', ['textarea', 'list'], true)): ?>
                            <textarea name="<?= escapeHtml($name) ?>"<?= ($field['required'] ?? false) ? ' required' : '' ?>><?= escapeHtml($record[$name]) ?></textarea>
                        <?php else: ?>
                            <input type="<?= escapeHtml($field['type'] ?? 'text') ?>" name="<?= escapeHtml($name) ?>" value="<?= escapeHtml($record[$name]) ?>"<?= ($field['required'] ?? false) ? ' required' : '' ?>>
                        <?php endif; ?>
                    </label>
                    <?php endif; ?>
                <?php endforeach; ?>
                <div class="actions">
                    <button class="button" type="submit"<?= $loadError !== null ? ' disabled' : '' ?>>Save</button>
                    <?php if ($editing): ?>
                        <a class="button secondary" href="?view=<?= escapeHtml($view) ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
        <section class="panel">
            <h2>Existing records</h2>
            <div class="table-wrap"><table><thead><tr>
                <th>Edit</th>
                <?php foreach ($definition['fields'] as $field): ?><th><?= escapeHtml($field['label']) ?></th><?php endforeach; ?>
            </tr></thead><tbody>
            <?php foreach ($rows as $row): ?>
                <tr><td><a href="?view=<?= escapeHtml($view) ?>&amp;id=<?= rawurlencode((string) $row[$definition['key']]) ?>">Edit</a></td>
                <?php foreach ($definition['fields'] as $name => $field): ?>
                    <td><?php
                        if (($field['type'] ?? '') === 'list') {
                            echo escapeHtml(str_replace("\n", ', ', listText($row[$name] ?? null)));
                        } elseif (($field['type'] ?? '') === 'checkbox') {
                            echo (bool) ($row[$name] ?? false) ? '&#10003;' : '';
                        } else {
                            echo escapeHtml((string) ($row[$name] ?? ''));
                        }
                    ?></td>
                <?php endforeach; ?></tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </section>
    </div>
</main>
</body>
</html>
