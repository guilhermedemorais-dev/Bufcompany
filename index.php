<?php
declare(strict_types=1);

$homepage = __DIR__ . '/index.html';

if (!is_file($homepage)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Missing homepage file.';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
readfile($homepage);
