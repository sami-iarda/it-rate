<?php
// config.php — loads environment variables from .env and exposes env()/env_required().
// Dependency-free so it works without an extra composer package.
declare(strict_types=1);

/**
 * Parse a .env file into $_ENV / getenv(). Existing real environment
 * variables always win, so a server-level value can override the file.
 */
function loadEnv(string $path): void {
    static $loaded = [];
    if (isset($loaded[$path]) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;

        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));

        // Strip matching surrounding quotes (values with # or spaces need them)
        $len = strlen($val);
        if ($len >= 2 && ($val[0] === '"' || $val[0] === "'") && $val[$len - 1] === $val[0]) {
            $val = substr($val, 1, -1);
        }

        if ($key === '' || getenv($key) !== false) continue;
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}

/** Read a config value, falling back to $default when unset. */
function env(string $key, mixed $default = null): mixed {
    $val = $_ENV[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    return match (strtolower((string)$val)) {
        'true'  => true,
        'false' => false,
        'null'  => null,
        default => $val,
    };
}

/** Read a config value that the app cannot run without. */
function env_required(string $key): string {
    $val = env($key);
    if ($val === null || $val === '') {
        http_response_code(500);
        error_log("Missing required environment variable: $key");
        exit('Server configuration error');
    }
    return (string)$val;
}

loadEnv(__DIR__ . '/.env');
