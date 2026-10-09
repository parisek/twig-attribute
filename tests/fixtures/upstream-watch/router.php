<?php

// Router for `php -S` in UpstreamCheckTest. Fake upstream host with a redirect and an oversized answer.
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = $_SERVER['DOCUMENT_ROOT'];

if (str_starts_with($path, '/redirect/')) {
    header('Location: /ok/' . basename($path), true, 302);

    return true;
}
if (str_starts_with($path, '/big/')) {
    header('Content-Type: text/plain');
    echo str_repeat('a', 3 * 1024 * 1024);

    return true;
}
if (str_starts_with($path, '/ok/')) {
    readfile($root . '/' . basename($path));

    return true;
}
http_response_code(404);

return true;
