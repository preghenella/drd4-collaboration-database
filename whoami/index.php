<?php

declare(strict_types=1); // Temporary authentication diagnostics.

header('Content-Type: text/plain; charset=UTF-8');
header('Cache-Control: no-store');

echo 'AUTH_TYPE='
    . ($_SERVER['AUTH_TYPE'] ?? '<not set>')
    . PHP_EOL;

echo 'PHP_AUTH_USER='
    . ($_SERVER['PHP_AUTH_USER'] ?? '<not set>')
    . PHP_EOL;

echo 'REMOTE_USER='
    . ($_SERVER['REMOTE_USER'] ?? '<not set>')
    . PHP_EOL;

echo 'OIDC_CLAIM_preferred_username='
    . ($_SERVER['OIDC_CLAIM_preferred_username'] ?? '<not set>')
    . PHP_EOL;

echo 'OIDC_CLAIM_cern_upn='
    . ($_SERVER['OIDC_CLAIM_cern_upn'] ?? '<not set>')
    . PHP_EOL;

echo 'ADMIN_USERS='
    . (getenv('ADMIN_USERS') ?: '<not set>')
    . PHP_EOL;
