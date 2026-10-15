<?php
$siteName = 'example';
function siteName(): string
{
    global $siteName;
    return $siteName;
}
header('Content-Type: application/json');
echo json_encode([
    'script' => 'example/index.php',
    'global' => siteName(),
    'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
    'SCRIPT_NAME' => $_SERVER['SCRIPT_NAME'] ?? null,
    'PHP_SELF' => $_SERVER['PHP_SELF'] ?? null,
    'PATH_INFO' => $_SERVER['PATH_INFO'] ?? null,
    'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? null,
    'DOCUMENT_ROOT' => $_SERVER['DOCUMENT_ROOT'] ?? null,
    'REDIRECT_URL' => $_SERVER['REDIRECT_URL'] ?? null,
    'REDIRECT_STATUS' => $_SERVER['REDIRECT_STATUS'] ?? null,
    'REDIRECT_HTTP_AUTHORIZATION' => $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
    'APP_ENV' => getenv('APP_ENV'),
    'timezone' => ini_get('date.timezone'),
    'cwd' => basename(getcwd()),
    'get' => $_GET,
]);
