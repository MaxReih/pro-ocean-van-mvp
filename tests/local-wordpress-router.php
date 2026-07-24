<?php

declare(strict_types=1);

$documentRoot = dirname(__DIR__, 4);
$path = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
$file = $documentRoot . str_replace('/', DIRECTORY_SEPARATOR, $path);

if (PHP_SAPI === 'cli-server' && is_file($file)) {
    return false;
}

if (PHP_SAPI === 'cli-server') {
    $host = preg_replace('/[^a-zA-Z0-9.:\-\[\]]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1'));
    if (! defined('WP_HOME')) {
        define('WP_HOME', 'http://' . $host);
    }
    if (! defined('WP_SITEURL')) {
        define('WP_SITEURL', 'http://' . $host);
    }
}

require $documentRoot . DIRECTORY_SEPARATOR . 'index.php';
