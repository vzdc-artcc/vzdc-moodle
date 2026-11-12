<?php
// Test helper: prints Moodle's environment checks (what admin/environment.php
// shows) and fails on any that do not pass. Run as www-data inside the image:
//   docker compose -f test/compose.yaml exec -T --user www-data web php < test/environment.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->libdir . '/environmentlib.php');

[$ok, $results] = check_moodle_environment(normalize_version($CFG->release), ENV_SELECT_RELEASE);
$bad = 0;
foreach ($results as $r) {
    $level = $r->getLevel();
    $status = $r->getStatus() ? 'ok' : ($level === 'required' ? 'FAIL' : 'check');
    $name = $r->getPart() . ':' . ($r->getInfo() ?: '');
    $feedback = $r->getFeedbackStr() ? (is_array($r->getFeedbackStr()) ? $r->getFeedbackStr()[0] : $r->getFeedbackStr()) : '';
    if (!$r->getStatus()) {
        $bad++;
    }
    printf("%-6s %-9s %-55s %s\n", $status, $level, $name, $feedback);
}
echo $ok ? "ENVIRONMENT OK\n" : "ENVIRONMENT FAILED\n";
exit($ok ? 0 : 1);
