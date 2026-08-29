<?php
require_once __DIR__ . '/config/bootstrap.php';
require_once __DIR__ . '/functions/subscriptions.php';

function currencySaveRedirect(string $status): void
{
    header('Location: admin.php?currency_status=' . rawurlencode($status));
    exit;
}

if (!isAdminLoggedIn()) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrfValidate((string) ($_POST['csrf_token'] ?? ''))) {
    currencySaveRedirect('csrf');
}

$symbol = mb_substr(trim((string) ($_POST['currency_symbol'] ?? '')), 0, 8);
$code = preg_replace('/[^\p{L}\p{N}]/u', '', trim((string) ($_POST['currency_code'] ?? '')));
$code = mb_substr((string) $code, 0, 8);

if ($symbol === '' || $code === '') {
    currencySaveRedirect('invalid');
}

if (!subsEnsureSchema()) {
    currencySaveRedirect('error');
}

$saved = subsSetSetting('currency_symbol', $symbol)
    && subsSetSetting('currency_code', $code);

currencySaveRedirect($saved ? 'saved' : 'error');