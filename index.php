<?php

declare(strict_types=1);

function escapeHtml(?string $value): string
{
    return htmlspecialchars(
        $value ?? '',
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function configurationValue(string $name)
{
    $value = getenv($name);

    if ($value !== false && $value !== '') {
        return $value;
    }

    foreach ([$_ENV, $_SERVER] as $source) {
        if (isset($source[$name]) && $source[$name] !== '') {
            return (string) $source[$name];
        }

        $redirectedName = 'REDIRECT_' . $name;

        if (isset($source[$redirectedName]) && $source[$redirectedName] !== '') {
            return (string) $source[$redirectedName];
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

function authenticatedEditor(): ?string
{
    $identity = configurationValue('REMOTE_USER')
        ?: configurationValue('OIDC_CLAIM_preferred_username')
        ?: configurationValue('OIDC_CLAIM_cern_upn')
        ?: configurationValue('HTTP_X_FORWARDED_USER');
    $configured = configurationValue('ADMIN_USERS');

    if ($identity === false || $configured === false) {
        return null;
    }

    $allowed = array_filter(array_map('trim', explode(',', $configured)));

    return in_array($identity, $allowed, true) ? $identity : null;
}

function csvCell(?string $value): string
{
    $value = $value ?? '';

    // Prevent spreadsheet applications from interpreting database text as a formula.
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
        return "'" . $value;
    }

    return $value;
}

function representedInstitutions(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }

    $values = json_decode($json, true);

    if (!is_array($values)) {
        return [];
    }

    return array_values(array_filter($values, 'is_string'));
}

$requestedView = $_GET['view'] ?? 'institutions';
$view = in_array(
    $requestedView,
    ['institutions', 'agencies', 'resources-board', 'deliverables'],
    true
)
    ? $requestedView
    : 'institutions';
$rows = [];
$errorMessage = null;
$editor = authenticatedEditor();

try {
    $databaseUser = configurationValue('MYSQL_USER');
    $databasePassword = configurationValue('MYSQL_PASSWORD');

    if ($databaseUser === false || $databaseUser === '') {
        throw new RuntimeException('MYSQL_USER is not configured');
    }

    if ($databasePassword === false) {
        throw new RuntimeException('MYSQL_PASSWORD is not configured');
    }

    $databaseHost = configurationValue('MYSQL_HOST')
        ?: 'dbod-drd4-collaboration-database.cern.ch';
    $databasePort = configurationValue('MYSQL_PORT') ?: '5547';
    $databaseName = configurationValue('MYSQL_DATABASE') ?: 'drd4';

    $dataSourceName = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $databaseHost,
        $databasePort,
        $databaseName
    );

    $database = new PDO(
        $dataSourceName,
        $databaseUser,
        $databasePassword,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    if ($view === 'deliverables') {
        $statement = $database->query(
            'SELECT work_package, number, title, description, institutions
             FROM deliverables
             ORDER BY work_package, number'
        );
    } elseif ($view === 'agencies') {
        $statement = $database->query(
            'SELECT id, code, name, country, representative, email, `function`,
                    institutions_represented
             FROM agencies
             ORDER BY country, name'
        );
    } elseif ($view === 'resources-board') {
        $statement = $database->query(
            'SELECT id, code, name, country, representative, email
             FROM resources_board
             ORDER BY country, name'
        );
    } else {
        $statement = $database->query(
            'SELECT code, name, country, town, contact
             FROM institutions
             ORDER BY country, name'
        );
    }

    $rows = $statement->fetchAll();

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header(
            'Content-Disposition: attachment; filename="drd4-'
            . $view
            . '.csv"'
        );
        header('Cache-Control: no-store');

        $output = fopen('php://output', 'wb');

        if ($output === false) {
            throw new RuntimeException('Unable to open CSV output');
        }

        // UTF-8 byte-order mark helps Excel recognize non-ASCII characters.
        fwrite($output, "\xEF\xBB\xBF");
        if ($view === 'deliverables') {
            fputcsv(
                $output,
                [
                    'Work Package',
                    'Deliverable',
                    'Title',
                    'Description',
                    'Participating institutes',
                ],
                ',',
                '"',
                ''
            );

            foreach ($rows as $deliverable) {
                fputcsv(
                    $output,
                    [
                        (string) $deliverable['work_package'],
                        'D' . $deliverable['work_package'] . '.' . $deliverable['number'],
                        csvCell($deliverable['title']),
                        csvCell($deliverable['description']),
                        csvCell(implode(', ', representedInstitutions(
                            $deliverable['institutions']
                        ))),
                    ],
                    ',',
                    '"',
                    ''
                );
            }
        } elseif ($view === 'agencies') {
            fputcsv(
                $output,
                [
                    'id',
                    'code',
                    'name',
                    'country',
                    'representative',
                    'email',
                    'function',
                    'institutions_represented',
                ],
                ',',
                '"',
                ''
            );

            foreach ($rows as $agency) {
                fputcsv(
                    $output,
                    [
                        csvCell((string) $agency['id']),
                        csvCell($agency['code']),
                        csvCell($agency['name']),
                        csvCell($agency['country']),
                        csvCell($agency['representative']),
                        csvCell($agency['email']),
                        csvCell($agency['function']),
                        csvCell($agency['institutions_represented']),
                    ],
                    ',',
                    '"',
                    ''
                );
            }
        } elseif ($view === 'resources-board') {
            fputcsv(
                $output,
                [
                    'id',
                    'code',
                    'name',
                    'country',
                    'representative',
                    'email',
                ],
                ',',
                '"',
                ''
            );

            foreach ($rows as $member) {
                fputcsv(
                    $output,
                    [
                        csvCell((string) $member['id']),
                        csvCell($member['code']),
                        csvCell($member['name']),
                        csvCell($member['country']),
                        csvCell($member['representative']),
                        csvCell($member['email']),
                    ],
                    ',',
                    '"',
                    ''
                );
            }
        } else {
            fputcsv(
                $output,
                ['Code', 'Institution', 'Country', 'Town', 'Contact'],
                ',',
                '"',
                ''
            );

            foreach ($rows as $institution) {
                fputcsv(
                    $output,
                    [
                        csvCell($institution['code']),
                        csvCell($institution['name']),
                        csvCell($institution['country']),
                        csvCell($institution['town']),
                        csvCell($institution['contact']),
                    ],
                    ',',
                    '"',
                    ''
                );
            }
        }

        fclose($output);
        exit;
    }
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('Unable to load DRD4 data: ' . $exception->getMessage());
    $errorMessage = 'The requested list is temporarily unavailable.';
}

?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DRD4 Collaboration Database</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Arial, Helvetica, sans-serif;
            color: #202124;
            background: #f4f6f8;
        }

        body {
            margin: 0;
        }

        main {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1rem 3rem;
        }

        h1 {
            margin-bottom: 0.35rem;
            color: #17365d;
        }

        .identity {
            margin-top: 0;
            color: #5f6368;
        }

        .tabs {
            display: flex;
            gap: 0.25rem;
            margin: 1.25rem 0;
            border-bottom: 2px solid #17365d;
        }

        .tab {
            padding: 0.7rem 1rem;
            border-radius: 0.35rem 0.35rem 0 0;
            color: #17365d;
            background: #e5eaf0;
            text-decoration: none;
            font-weight: bold;
        }

        .tab:hover,
        .tab:focus {
            background: #d5dfeb;
        }

        .tab.active {
            color: white;
            background: #17365d;
        }

        .summary {
            margin-top: 0;
            color: #5f6368;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1rem;
        }

        .toolbar .summary {
            margin: 0;
        }

        .toolbar-actions {
            display: flex;
            gap: 0.6rem;
        }

        .export-button {
            display: inline-block;
            padding: 0.6rem 0.9rem;
            border-radius: 0.35rem;
            color: white;
            background: #17365d;
            text-decoration: none;
            white-space: nowrap;
        }

        .export-button:hover,
        .export-button:focus {
            background: #254f7f;
        }

        .table-container {
            overflow-x: auto;
            border: 1px solid #c8d0da;
            border-radius: 0.4rem;
            background: white;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 0.7rem 0.8rem;
            border-bottom: 1px solid #dfe3e8;
            text-align: left;
            vertical-align: middle;
        }

        th {
            background: #17365d;
            color: white;
            white-space: nowrap;
        }

        tbody tr:nth-child(even) {
            background: #f7f9fb;
        }

        tbody tr:hover {
            background: #eaf2fb;
        }

        tbody tr:last-child td {
            border-bottom: 0;
        }

        .deliverable-title {
            border-bottom: 1px dotted #5f6368;
            cursor: help;
        }

        .contact-name {
            border-bottom: 1px dotted #5f6368;
            cursor: help;
        }

        .institutes-help {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.6rem;
            min-height: 1.6rem;
            padding: 0 0.25rem;
            border-radius: 999px;
            color: white;
            background: #17365d;
            font-weight: bold;
            cursor: help;
            line-height: 1;
        }

        .work-package-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.6rem;
            min-height: 1.6rem;
            padding: 0 0.25rem;
            border-radius: 999px;
            color: white;
            background: #5f6368;
            font-weight: bold;
            line-height: 1;
        }

        .work-package-badge.wp-1 { background: #1565c0; }
        .work-package-badge.wp-2 { background: #2e7d32; }
        .work-package-badge.wp-3 { background: #6a1b9a; }
        .work-package-badge.wp-4 { background: #b45309; }
        .work-package-badge.wp-5 { background: #b3261e; }

        .error {
            padding: 1rem;
            border: 1px solid #b3261e;
            border-radius: 0.4rem;
            color: #8c1d18;
            background: #fce8e6;
        }
    </style>
</head>
<body>
<main>
    <h1>DRD4 Collaboration Database</h1>
    <?php if ($editor !== null): ?>
        <p class="identity">
            Signed in as <?= escapeHtml($editor) ?> ·
            <a href="admin/?view=<?= escapeHtml($view) ?>">Admin view</a>
        </p>
    <?php endif; ?>

    <nav class="tabs" aria-label="Database tables">
        <a
            class="tab<?= $view === 'institutions' ? ' active' : '' ?>"
            href="?view=institutions"
            <?= $view === 'institutions' ? 'aria-current="page"' : '' ?>
        >Institutions</a>
        <a
            class="tab<?= $view === 'agencies' ? ' active' : '' ?>"
            href="?view=agencies"
            <?= $view === 'agencies' ? 'aria-current="page"' : '' ?>
        >Funding agencies</a>
        <a
            class="tab<?= $view === 'resources-board' ? ' active' : '' ?>"
            href="?view=resources-board"
            <?= $view === 'resources-board' ? 'aria-current="page"' : '' ?>
        >Resources Board</a>
        <a
            class="tab<?= $view === 'deliverables' ? ' active' : '' ?>"
            href="?view=deliverables"
            <?= $view === 'deliverables' ? 'aria-current="page"' : '' ?>
        >Deliverables</a>
    </nav>

    <?php if ($errorMessage !== null): ?>
        <p class="error"><?= escapeHtml($errorMessage) ?></p>
    <?php else: ?>
        <div class="toolbar">
            <p class="summary">
                <?= count($rows) ?>
                <?php if ($view === 'agencies'): ?>
                    funding agencies
                <?php elseif ($view === 'resources-board'): ?>
                    Resources Board members
                <?php elseif ($view === 'deliverables'): ?>
                    deliverables
                <?php else: ?>
                    institutions
                <?php endif; ?>
            </p>
            <div class="toolbar-actions">
                <?php if ($editor !== null): ?>
                    <a class="export-button" href="admin/?view=<?= $view ?>">Manage data</a>
                <?php endif; ?>
                <a class="export-button" href="?view=<?= $view ?>&amp;export=csv">
                    Export CSV
                </a>
            </div>
        </div>

        <div class="table-container">
        <?php if ($view === 'deliverables'): ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Work Package</th>
                        <th scope="col">Deliverable</th>
                        <th scope="col">Title</th>
                        <th scope="col">Participating institutes</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $deliverable): ?>
                    <?php $institutes = representedInstitutions($deliverable['institutions']); ?>
                    <tr>
                        <td>
                            <span class="work-package-badge wp-<?= (int) $deliverable['work_package'] ?>">
                                <?= (int) $deliverable['work_package'] ?>
                            </span>
                        </td>
                        <td>
                            <?= escapeHtml(
                                'D'
                                . $deliverable['work_package']
                                . '.'
                                . $deliverable['number']
                            ) ?>
                        </td>
                        <td>
                            <span
                                class="deliverable-title"
                                title="<?= escapeHtml($deliverable['description']) ?>"
                            >
                                <?= escapeHtml($deliverable['title']) ?>
                            </span>
                        </td>
                        <td>
                        <?php if ($institutes === []): ?>
                            &mdash;
                        <?php else: ?>
                            <span
                                class="institutes-help"
                                title="<?= escapeHtml(implode("\n", $institutes)) ?>"
                                aria-label="Institutes: <?= escapeHtml(implode(', ', $institutes)) ?>"
                            ><?= count($institutes) ?></span>
                        <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php elseif ($view === 'agencies' || $view === 'resources-board'): ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">
                            <?= $view === 'agencies' ? 'Funding agency' : 'Founding agency' ?>
                        </th>
                        <th scope="col">Country</th>
                        <th scope="col">Representative</th>
                        <?php if ($view === 'agencies'): ?>
                            <th scope="col">Institutions represented</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $agency): ?>
                    <?php
                    $represented = $view === 'agencies'
                        ? representedInstitutions($agency['institutions_represented'])
                        : [];
                    $representativeDetails = array_filter([
                        isset($agency['function']) && $agency['function'] !== ''
                            ? 'Function: ' . $agency['function']
                            : null,
                        $agency['email'] !== null && $agency['email'] !== ''
                            ? 'Email: ' . $agency['email']
                            : null,
                    ]);
                    ?>
                    <tr>
                        <td><?= escapeHtml($agency['code']) ?></td>
                        <td><?= escapeHtml($agency['name']) ?></td>
                        <td><?= escapeHtml($agency['country']) ?></td>
                        <td>
                        <?php if ($agency['representative'] !== null && $agency['representative'] !== ''): ?>
                            <span
                                class="contact-name"
                                <?php if ($representativeDetails !== []): ?>
                                title="<?= escapeHtml(implode("\n", $representativeDetails)) ?>"
                                <?php endif; ?>
                            ><?= escapeHtml($agency['representative']) ?></span>
                        <?php endif; ?>
                        </td>
                        <?php if ($view === 'agencies'): ?>
                            <td>
                            <?php if ($represented !== []): ?>
                                <span
                                    class="institutes-help"
                                    title="<?= escapeHtml(implode("\n", $represented)) ?>"
                                    aria-label="Institutions represented: <?= escapeHtml(implode(', ', $represented)) ?>"
                                ><?= count($represented) ?></span>
                            <?php endif; ?>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th scope="col">Code</th>
                        <th scope="col">Institution</th>
                        <th scope="col">Country</th>
                        <th scope="col">Town</th>
                        <th scope="col">Contact</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $institution): ?>
                    <tr>
                        <td><?= escapeHtml($institution['code']) ?></td>
                        <td><?= escapeHtml($institution['name']) ?></td>
                        <td><?= escapeHtml($institution['country']) ?></td>
                        <td><?= escapeHtml($institution['town']) ?></td>
                        <td><?= escapeHtml($institution['contact']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        </div>
    <?php endif; ?>
</main>
</body>
</html>
