<?php
declare(strict_types=1);

function load_env(string $filePath): array
{
    $vars = [];
    if (!is_file($filePath) || !is_readable($filePath)) {
        return $vars;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $vars;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function env(string $key, string $default = ''): string
{
    static $loaded = null;
    if ($loaded === null) {
        $loaded = load_env(__DIR__ . DIRECTORY_SEPARATOR . '.env');
    }
    return $loaded[$key] ?? $default;
}
