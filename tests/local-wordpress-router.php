<?php

declare(strict_types=1);

$documentRoot = dirname(__DIR__, 4);
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$file = $documentRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);

if (PHP_SAPI === 'cli-server' && is_file($file)) {
    return false;
}

require $documentRoot . DIRECTORY_SEPARATOR . 'index.php';
