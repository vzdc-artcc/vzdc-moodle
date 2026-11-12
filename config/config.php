<?php
// Moodle configuration for the vZDC Learning Center.
//
// Baked into the image at /var/www/html/config.php. Everything site-specific
// comes from the environment, so the same image runs anywhere; see README.md
// for the variables. Nothing here is a secret.

unset($CFG);
global $CFG;
$CFG = new stdClass();

/**
 * Reads an environment variable, falling back to a default.
 *
 * A required variable that is missing stops the request with a 503 (or exit
 * code 1 on the CLI) and names the variable, never its value.
 *
 * @param string $name
 * @param string|null $default null means required
 * @return string
 */
function vzdc_env(string $name, ?string $default = null): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        if ($default !== null) {
            return $default;
        }
        $message = "Moodle is not configured: the environment variable {$name} is not set.";
        error_log($message);
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, $message . PHP_EOL);
            exit(1);
        }
        http_response_code(503);
        header('Content-Type: text/plain');
        echo "Service unavailable\n";
        exit;
    }
    return $value;
}

$CFG->dbtype    = 'pgsql';
$CFG->dblibrary = 'native';
$CFG->dbhost    = vzdc_env('MOODLE_DB_HOST');
$CFG->dbname    = vzdc_env('MOODLE_DB_NAME');
$CFG->dbuser    = vzdc_env('MOODLE_DB_USER');
$CFG->dbpass    = vzdc_env('MOODLE_DB_PASSWORD');
$CFG->prefix    = 'mdl_';
$CFG->dboptions = [
    'dbpersist' => false,
    'dbport'    => (int) vzdc_env('MOODLE_DB_PORT', '5432'),
    'dbsocket'  => '',
];

$CFG->wwwroot   = rtrim(vzdc_env('MOODLE_WWWROOT'), '/');
$CFG->dataroot  = '/var/www/moodledata';
$CFG->localcachedir = '/var/www/moodlecache';
$CFG->admin     = 'admin';

// Group-writable, because the pod gives the volumes fsGroup 33 (www-data).
$CFG->directorypermissions = 02770;

// TLS ends at Traefik (behind Cloudflare). Apache only ever sees plain HTTP;
// this makes Moodle treat every request as https. It also lets a kubelet probe
// over plain HTTP get a 200 instead of a redirect.
$CFG->sslproxy = true;

// Apache sends every unknown path to public/r.php (FallbackResource, see
// config/apache/moodle.conf). Moodle cannot detect that by itself; this flag
// says it is done, and the Environment page's router check then verifies it
// with real requests to wwwroot.
$CFG->routerconfigured = true;

// Sessions in Redis (Valkey), with session locking.
$CFG->session_handler_class = '\core\session\redis';
$CFG->session_redis_host = vzdc_env('MOODLE_REDIS_HOST');
$CFG->session_redis_port = (int) vzdc_env('MOODLE_REDIS_PORT', '6379');
$CFG->session_redis_auth = vzdc_env('MOODLE_REDIS_PASSWORD', '');
$CFG->session_redis_database = 0;
$CFG->session_redis_prefix = 'vzdc_sess_';
$CFG->session_redis_acquire_lock_timeout = 120;
$CFG->session_redis_lock_expire = 7200;
$CFG->session_redis_acquire_lock_retry = 100;
$CFG->session_redis_serializer_use_igbinary = true;

// The code in the image is immutable: no installing or updating plugins from
// the web UI, and no setting paths to executables from it either.
$CFG->disableupdateautodeploy = true;
$CFG->disableonclickaddoninstall = true;
$CFG->preventexecpath = true;

require_once(__DIR__ . '/public/lib/setup.php');
// There is no closing PHP tag on purpose.
