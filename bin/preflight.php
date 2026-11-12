<?php
// This file is part of the vZDC Moodle image. GPL v3 or later.
//
// Checks used by moodle-init before Moodle itself can be loaded:
//
//   php preflight.php wait [seconds]   wait until PostgreSQL and Redis answer
//   php preflight.php installed        exit 0 if mdl_config exists, 3 if not
//
// Reads the same environment variables as config.php. Prints no values.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function env(string $name, string $default = ''): string {
    $value = getenv($name);
    return ($value === false || $value === '') ? $default : $value;
}

function say(string $message): void {
    fwrite(STDOUT, gmdate('Y-m-d\TH:i:s\Z') . " moodle-init: {$message}\n");
}

function pg_dsn(): string {
    $quote = fn(string $v): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $v) . "'";
    return sprintf('host=%s port=%s dbname=%s user=%s password=%s connect_timeout=5',
        $quote(env('MOODLE_DB_HOST')), $quote(env('MOODLE_DB_PORT', '5432')), $quote(env('MOODLE_DB_NAME')),
        $quote(env('MOODLE_DB_USER')), $quote(env('MOODLE_DB_PASSWORD')));
}

function pg(): ?\PgSql\Connection {
    $connection = @pg_connect(pg_dsn(), PGSQL_CONNECT_FORCE_NEW);
    return $connection === false ? null : $connection;
}

function redis_ok(): bool {
    try {
        $redis = new Redis();
        if (!$redis->connect(env('MOODLE_REDIS_HOST'), (int) env('MOODLE_REDIS_PORT', '6379'), 5.0)) {
            return false;
        }
        $password = env('MOODLE_REDIS_PASSWORD');
        if ($password !== '' && !$redis->auth($password)) {
            return false;
        }
        $pong = $redis->ping();
        $redis->close();
        return $pong === true || $pong === '+PONG' || $pong === 'PONG';
    } catch (Throwable $e) {
        return false;
    }
}

$mode = $argv[1] ?? '';

if ($mode === 'wait') {
    foreach (['MOODLE_DB_HOST', 'MOODLE_DB_NAME', 'MOODLE_DB_USER', 'MOODLE_DB_PASSWORD', 'MOODLE_REDIS_HOST'] as $name) {
        if (env($name) === '') {
            say("{$name} is not set");
            exit(1);
        }
    }
    $deadline = time() + (int) ($argv[2] ?? 300);
    $dbup = false;
    $redisup = false;
    while (true) {
        if (!$dbup && ($connection = pg())) {
            pg_close($connection);
            $dbup = true;
            say('PostgreSQL is reachable');
        }
        if (!$redisup && redis_ok()) {
            $redisup = true;
            say('Redis is reachable');
        }
        if ($dbup && $redisup) {
            exit(0);
        }
        if (time() >= $deadline) {
            say('gave up waiting for ' . implode(' and ', array_filter([$dbup ? '' : 'PostgreSQL', $redisup ? '' : 'Redis'])));
            exit(1);
        }
        sleep(3);
    }
}

if ($mode === 'installed') {
    $connection = pg();
    if (!$connection) {
        say('cannot connect to PostgreSQL');
        exit(1);
    }
    $result = pg_query($connection, "SELECT to_regclass('mdl_config') IS NOT NULL");
    $installed = $result && pg_fetch_result($result, 0, 0) === 't';
    pg_close($connection);
    exit($installed ? 0 : 3);
}

fwrite(STDERR, "usage: preflight.php wait [seconds] | installed\n");
exit(2);
