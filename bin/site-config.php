<?php
// This file is part of the vZDC Moodle image.
//
// It is free software: you can redistribute it and/or modify it under the
// terms of the GNU General Public License as published by the Free Software
// Foundation, either version 3 of the License, or (at your option) any later
// version.

/**
 * Site configuration for the vZDC Learning Center.
 *
 * Run by moodle-init after every install or upgrade. Every step reads the
 * current state first and only writes what differs, so a second run changes
 * nothing. The settings named here are owned by this script: a value changed
 * through the admin UI is put back on the next rollout.
 *
 * Values from the environment are never printed, only the names of the
 * settings that changed.
 *
 * Usage: php /opt/vzdc/site-config.php
 */

define('CLI_SCRIPT', true);

require('/var/www/html/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

use core\oauth2\endpoint;
use core\oauth2\issuer;
use core\oauth2\user_field_mapping;

const VZDC_FULLNAME = 'Virtual Washington ARTCC Learning Center';
const VZDC_SHORTNAME = 'vZDC Learn';
const VZDC_NOREPLY = 'no-reply@vzdc.org';
const VZDC_ADMIN_USERNAME = 'vzdcadmin';
const VZDC_WS_NAME = 'vzdc-website';
const VZDC_WS_ROLE = 'vzdcwebsite';
const VZDC_WS_FUNCTION_PREFIXES = [
    'core_user_',
    'core_cohort_',
    'enrol_manual_',
    'core_completion_',
    'gradereport_user_',
];
// Capabilities a service function may declare but the website role never gets.
// core_user_set_user_preferences declares moodle/site:config so that it can set
// other people's preferences; granting it would make the token a site admin in
// all but name. Without it, that function works on the website user's own
// preferences only.
const VZDC_WS_EXCLUDED_CAPABILITIES = ['moodle/site:config'];
const VZDC_VATSIM_NAME = 'VATSIM';

/** @var bool Set when anything changed that needs the caches purged. */
$vzdcpurge = false;

/**
 * Prints a progress line. Never pass a secret.
 *
 * @param string $message
 */
function vzdc_log(string $message): void {
    mtrace('site-config: ' . $message);
}

/**
 * Reads an optional environment variable.
 *
 * @param string $name
 * @return string The value, or '' when unset.
 */
function vzdc_getenv(string $name): string {
    $value = getenv($name);
    return $value === false ? '' : trim($value);
}

/**
 * Sets a config value if it differs. Logs the name only.
 *
 * @param string $name
 * @param string|int $value
 * @param string|null $plugin
 * @return bool true if it changed
 */
function vzdc_config(string $name, $value, ?string $plugin = null): bool {
    $current = get_config($plugin ?? 'core', $name);
    if ($current !== false && (string) $current === (string) $value) {
        return false;
    }
    set_config($name, $value, $plugin);
    vzdc_log('set ' . ($plugin ? "{$plugin}/" : '') . $name);
    return true;
}

// Act as the main admin, so capability checks pass and records get a sensible creator.
\core\session\manager::set_user(get_admin());

// -----------------------------------------------------------------------------
// Break-glass admin.
// -----------------------------------------------------------------------------
$adminpassword = vzdc_getenv('MOODLE_ADMIN_PASSWORD');
$adminemail = vzdc_getenv('MOODLE_ADMIN_EMAIL');
$admin = $DB->get_record('user', ['username' => VZDC_ADMIN_USERNAME, 'mnethostid' => $CFG->mnet_localhost_id, 'deleted' => 0]);
if ($admin) {
    if ($adminemail !== '' && $admin->email !== $adminemail) {
        $DB->set_field('user', 'email', $adminemail, ['id' => $admin->id]);
        vzdc_log('updated the ' . VZDC_ADMIN_USERNAME . ' email address');
    }
    // The Kubernetes secret is the source of truth: rotating it rotates the password.
    if ($adminpassword !== '' && !validate_internal_user_password($admin, $adminpassword)) {
        update_internal_user_password($admin, $adminpassword);
        vzdc_log('updated the ' . VZDC_ADMIN_USERNAME . ' password');
    }
} else {
    vzdc_log('warning: no user ' . VZDC_ADMIN_USERNAME . ' found');
}

// -----------------------------------------------------------------------------
// Site identity and access.
// -----------------------------------------------------------------------------
$site = $DB->get_record('course', ['id' => SITEID], 'id, fullname, shortname', MUST_EXIST);
if ($site->fullname !== VZDC_FULLNAME || $site->shortname !== VZDC_SHORTNAME) {
    $DB->update_record('course', (object) ['id' => SITEID, 'fullname' => VZDC_FULLNAME,
        'shortname' => VZDC_SHORTNAME, 'timemodified' => time()]);
    vzdc_log('set the site full and short name');
    $vzdcpurge = true;
}

$vzdcpurge = vzdc_config('timezone', 'America/New_York') || $vzdcpurge;
$vzdcpurge = vzdc_config('lang', 'en') || $vzdcpurge;
$vzdcpurge = vzdc_config('forcelogin', 1) || $vzdcpurge;
$vzdcpurge = vzdc_config('guestloginbutton', 0) || $vzdcpurge;
$vzdcpurge = vzdc_config('registerauth', '') || $vzdcpurge;
$vzdcpurge = vzdc_config('noreplyaddress', VZDC_NOREPLY) || $vzdcpurge;
// "Prevent account creation when authenticating": VATSIM may only log in people
// who already have an account (created by the website through the web service).
$vzdcpurge = vzdc_config('authpreventaccountcreation', 1) || $vzdcpurge;

// Email-based self-registration off; the web service user's auth method on.
if (is_enabled_auth('email')) {
    \core\plugininfo\auth::enable_plugin('email', 0);
    vzdc_log('disabled auth_email');
}
if (!is_enabled_auth('webservice')) {
    \core\plugininfo\auth::enable_plugin('webservice', 1);
    vzdc_log('enabled auth_webservice');
}

// -----------------------------------------------------------------------------
// Outgoing mail (AWS SES SMTP). Skipped while the credentials are not set.
// -----------------------------------------------------------------------------
$smtpuser = vzdc_getenv('MOODLE_SMTP_USER');
$smtppass = vzdc_getenv('MOODLE_SMTP_PASSWORD');
if ($smtpuser !== '' && $smtppass !== '') {
    vzdc_config('smtphosts', 'email-smtp.us-east-1.amazonaws.com:587');
    vzdc_config('smtpsecure', 'tls');
    vzdc_config('smtpauthtype', 'LOGIN');
    vzdc_config('smtpuser', $smtpuser);
    vzdc_config('smtppass', $smtppass);
} else {
    vzdc_log('SMTP credentials not set; leaving outgoing mail settings alone');
}

// -----------------------------------------------------------------------------
// Theme.
// -----------------------------------------------------------------------------
$footnote = '<nav class="vzdc-footer-links" aria-label="vZDC">'
    . '<a href="https://vzdc.org">Virtual Washington ARTCC</a>'
    . '<a href="https://vzdc.org/privacy">Privacy</a>'
    . '<a href="https://vzdc.org">Back to vZDC</a>'
    . '</nav>';
$themechanged = false;
$themechanged = vzdc_config('brandcolor', '#500E0E', 'theme_boost_union') || $themechanged;
$themechanged = vzdc_config('footnote', $footnote, 'theme_boost_union') || $themechanged;
// Moodle 5.3 core colour modes (light, dark, follow the device). Experimental in
// core, so the site default stays light and each person can switch.
$themechanged = vzdc_config('enablecolourmodes', 1, 'theme_boost') || $themechanged;
$themechanged = vzdc_config('defaultcolourmode', 'light', 'theme_boost') || $themechanged;
$themechanged = vzdc_config('theme', 'vzdc') || $themechanged;
if ($themechanged) {
    $vzdcpurge = true;
}

// -----------------------------------------------------------------------------
// Redis (Valkey) as the application cache, through the cache config API so the
// store shows on Site administration > Plugins > Caching > Configuration.
// Session-mode caches stay on the default session store: that store keeps its
// data in the PHP session, which already lives in Redis.
// -----------------------------------------------------------------------------
$redisstore = 'redis';
$redisconfig = [
    'server' => vzdc_getenv('MOODLE_REDIS_HOST') . ':' . (vzdc_getenv('MOODLE_REDIS_PORT') ?: '6379'),
    'prefix' => 'vzdc_muc_',
    'password' => vzdc_getenv('MOODLE_REDIS_PASSWORD'),
    'serializer' => \Redis::SERIALIZER_IGBINARY,
    'compressor' => \cachestore_redis::COMPRESSOR_NONE,
    'connectiontimeout' => 3,
    'readtimeout' => 3,
    'encryption' => false,
    'cafile' => '',
    'clustermode' => false,
];
$cacheconfig = \core_cache\config::instance();
$writer = \core_cache\config_writer::instance();
$stores = $cacheconfig->get_all_stores();
if (!array_key_exists($redisstore, $stores)) {
    $writer->add_store_instance($redisstore, 'redis', $redisconfig);
    vzdc_log('added the Redis cache store');
} else if ($stores[$redisstore]['configuration'] != $redisconfig) {
    $writer->edit_store_instance($redisstore, 'redis', $redisconfig);
    vzdc_log('updated the Redis cache store');
}
$wanted = [
    \core_cache\store::MODE_APPLICATION => [$redisstore],
    \core_cache\store::MODE_SESSION => ['default_session'],
    \core_cache\store::MODE_REQUEST => ['default_request'],
];
$current = [
    \core_cache\store::MODE_APPLICATION => [],
    \core_cache\store::MODE_SESSION => [],
    \core_cache\store::MODE_REQUEST => [],
];
foreach (\core_cache\config::instance()->get_mode_mappings() as $mapping) {
    $current[$mapping['mode']][(int) $mapping['sort']] = $mapping['store'];
}
foreach ($current as $mode => $list) {
    ksort($list);
    $current[$mode] = array_values($list);
}
if ($current != $wanted) {
    $writer->set_mode_mappings($wanted);
    vzdc_log('mapped the application cache mode to the Redis store');
}

// -----------------------------------------------------------------------------
// VATSIM Connect as a custom OAuth 2 issuer. It is enabled, together with the
// OAuth 2 authentication plugin, only once it has a client ID and secret.
// -----------------------------------------------------------------------------
$issuer = issuer::get_record(['name' => VZDC_VATSIM_NAME]);
if (!$issuer) {
    $issuer = new issuer(0, (object) [
        'name' => VZDC_VATSIM_NAME,
        'image' => '',
        'baseurl' => '',
        'clientid' => '',
        'clientsecret' => '',
        'loginscopes' => 'full_name vatsim_details email',
        'loginscopesoffline' => 'full_name vatsim_details email',
        'loginparams' => '',
        'loginparamsoffline' => '',
        'alloweddomains' => '',
        'showonloginpage' => issuer::LOGINONLY,
        'basicauth' => 0,
        // VATSIM only hands out verified addresses; an account the website
        // created with the same address is linked on first login.
        'requireconfirmation' => 0,
        'enabled' => 0,
        'servicetype' => '',
        'loginpagename' => 'VATSIM',
    ]);
    $issuer->create();
    vzdc_log('created the VATSIM OAuth 2 issuer');
}
$endpoints = [
    'authorization_endpoint' => 'https://auth.vatsim.net/oauth/authorize',
    'token_endpoint' => 'https://auth.vatsim.net/oauth/token',
    'userinfo_endpoint' => 'https://auth.vatsim.net/api/user',
];
foreach ($endpoints as $name => $url) {
    $endpoint = endpoint::get_record(['issuerid' => $issuer->get('id'), 'name' => $name]);
    if (!$endpoint) {
        (new endpoint(0, (object) ['issuerid' => $issuer->get('id'), 'name' => $name, 'url' => $url]))->create();
        vzdc_log("created the VATSIM {$name}");
    } else if ($endpoint->get('url') !== $url) {
        $endpoint->set('url', $url);
        $endpoint->update();
        vzdc_log("updated the VATSIM {$name}");
    }
}
$mappings = [
    'data-personal-name_first' => 'firstname',
    'data-personal-name_last' => 'lastname',
    'data-personal-email' => 'email',
    'data-cid' => 'idnumber',
];
$existing = user_field_mapping::get_records(['issuerid' => $issuer->get('id')]);
foreach ($existing as $mapping) {
    $internal = $mapping->get('internalfield');
    if (in_array($internal, $mappings, true) && ($mappings[$mapping->get('externalfield')] ?? null) !== $internal) {
        // Same Moodle field mapped from another claim: replace it.
        $mapping->delete();
        vzdc_log("removed the VATSIM mapping for {$internal}");
    }
}
foreach ($mappings as $external => $internal) {
    if (!user_field_mapping::record_exists_select('issuerid = ? AND externalfield = ? AND internalfield = ?',
            [$issuer->get('id'), $external, $internal])) {
        (new user_field_mapping(0, (object) ['issuerid' => $issuer->get('id'),
            'externalfield' => $external, 'internalfield' => $internal]))->create();
        vzdc_log("mapped VATSIM {$external} to {$internal}");
    }
}
$scopes = 'full_name vatsim_details email';
foreach (['loginscopes', 'loginscopesoffline'] as $field) {
    if ($issuer->get($field) !== $scopes) {
        $issuer->set($field, $scopes);
        $issuer->update();
        vzdc_log("set the VATSIM {$field}");
    }
}
$clientid = vzdc_getenv('MOODLE_VATSIM_CLIENT_ID');
$clientsecret = vzdc_getenv('MOODLE_VATSIM_CLIENT_SECRET');
if ($clientid !== '' && $clientsecret !== '') {
    if ($issuer->get('clientid') !== $clientid || $issuer->get('clientsecret') !== $clientsecret) {
        $issuer->set('clientid', $clientid);
        $issuer->set('clientsecret', $clientsecret);
        $issuer->update();
        vzdc_log('set the VATSIM client ID and secret');
    }
}
$hasclient = $issuer->get('clientid') !== '' && $issuer->get('clientsecret') !== '';
if ((bool) $issuer->get('enabled') !== $hasclient) {
    $issuer->set('enabled', (int) $hasclient);
    $issuer->update();
    vzdc_log(($hasclient ? 'enabled' : 'disabled') . ' the VATSIM issuer');
}
if (is_enabled_auth('oauth2') !== $hasclient) {
    \core\plugininfo\auth::enable_plugin('oauth2', (int) $hasclient);
    vzdc_log(($hasclient ? 'enabled' : 'disabled') . ' auth_oauth2');
}
if (!$hasclient) {
    vzdc_log('VATSIM client not set; the issuer and auth_oauth2 stay disabled');
}

// -----------------------------------------------------------------------------
// Web services for the vZDC website.
// -----------------------------------------------------------------------------
vzdc_config('enablewebservices', 1);
$protocols = array_filter(explode(',', (string) get_config('core', 'webserviceprotocols')));
if (!in_array('rest', $protocols, true)) {
    $protocols[] = 'rest';
    vzdc_config('webserviceprotocols', implode(',', $protocols));
}

// The functions, resolved from the prefixes against what this Moodle version has.
$functions = [];
foreach ($DB->get_records('external_functions', null, 'name', 'id, name, capabilities') as $function) {
    foreach (VZDC_WS_FUNCTION_PREFIXES as $prefix) {
        if (str_starts_with($function->name, $prefix)) {
            $functions[$function->name] = $function;
            break;
        }
    }
}

$service = $DB->get_record('external_services', ['shortname' => VZDC_WS_NAME]);
if (!$service) {
    $service = (object) [
        'name' => VZDC_WS_NAME,
        'shortname' => VZDC_WS_NAME,
        'enabled' => 1,
        'requiredcapability' => '',
        'restrictedusers' => 1,
        'component' => null,
        'timecreated' => time(),
        'timemodified' => time(),
        'downloadfiles' => 0,
        'uploadfiles' => 0,
    ];
    $service->id = $DB->insert_record('external_services', $service);
    vzdc_log('created the ' . VZDC_WS_NAME . ' external service');
} else if (!$service->enabled || !$service->restrictedusers) {
    $DB->update_record('external_services', (object) ['id' => $service->id, 'enabled' => 1,
        'restrictedusers' => 1, 'timemodified' => time()]);
    vzdc_log('enabled the ' . VZDC_WS_NAME . ' external service');
}
$present = $DB->get_records_menu('external_services_functions', ['externalserviceid' => $service->id], '', 'id, functionname');
foreach (array_diff(array_keys($functions), $present) as $name) {
    $DB->insert_record('external_services_functions', (object) ['externalserviceid' => $service->id,
        'functionname' => $name]);
}
foreach (array_diff($present, array_keys($functions)) as $id => $name) {
    $DB->delete_records('external_services_functions', ['id' => $id]);
}
$added = count(array_diff(array_keys($functions), $present));
$removed = count(array_diff($present, array_keys($functions)));
if ($added || $removed) {
    vzdc_log("service functions: {$added} added, {$removed} removed, " . count($functions) . ' total');
}

// The user the website authenticates as. Its auth method refuses interactive logins.
$wsuser = $DB->get_record('user', ['username' => VZDC_WS_NAME, 'mnethostid' => $CFG->mnet_localhost_id]);
if (!$wsuser) {
    $id = user_create_user((object) [
        'username' => VZDC_WS_NAME,
        'auth' => 'webservice',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'firstname' => 'vZDC',
        'lastname' => 'Website',
        'email' => VZDC_NOREPLY,
        'emailstop' => 1,
        'password' => \core\di::get(\core\authentication\password::class)->generate(32),
    ], true, false);
    $wsuser = $DB->get_record('user', ['id' => $id], '*', MUST_EXIST);
    vzdc_log('created the ' . VZDC_WS_NAME . ' user');
} else if ($wsuser->auth !== 'webservice' || $wsuser->deleted || $wsuser->suspended) {
    $DB->update_record('user', (object) ['id' => $wsuser->id, 'auth' => 'webservice', 'deleted' => 0,
        'suspended' => 0, 'timemodified' => time()]);
    vzdc_log('restored the ' . VZDC_WS_NAME . ' user');
}

// A role with exactly what the functions declare they need, plus REST access.
$systemcontext = context_system::instance();
$roleid = $DB->get_field('role', 'id', ['shortname' => VZDC_WS_ROLE]);
if (!$roleid) {
    $roleid = create_role('vZDC website', VZDC_WS_ROLE,
        'Used by the vZDC website through the ' . VZDC_WS_NAME . ' web service. Managed by site-config.php.');
    vzdc_log('created the ' . VZDC_WS_ROLE . ' role');
}
if (array_values(get_role_contextlevels($roleid)) != [CONTEXT_SYSTEM]) {
    set_role_contextlevels($roleid, [CONTEXT_SYSTEM]);
}
$capabilities = ['webservice/rest:use'];
foreach ($functions as $function) {
    foreach (explode(',', (string) $function->capabilities) as $capability) {
        $capability = trim($capability);
        if ($capability !== '' && !in_array($capability, VZDC_WS_EXCLUDED_CAPABILITIES, true)) {
            $capabilities[] = $capability;
        }
    }
}
$capabilities = array_unique($capabilities);
sort($capabilities);
$granted = $DB->get_records_menu('role_capabilities',
    ['roleid' => $roleid, 'contextid' => $systemcontext->id, 'permission' => CAP_ALLOW], '', 'id, capability');
$changedcaps = 0;
foreach ($capabilities as $capability) {
    if (!get_capability_info($capability)) {
        vzdc_log("warning: a service function names the unknown capability {$capability}");
        continue;
    }
    if (!in_array($capability, $granted, true)) {
        assign_capability($capability, CAP_ALLOW, $roleid, $systemcontext->id, true);
        $changedcaps++;
    }
}
foreach ($granted as $capability) {
    if (!in_array($capability, $capabilities, true)) {
        unassign_capability($capability, $roleid, $systemcontext->id);
        $changedcaps++;
    }
}
if ($changedcaps) {
    vzdc_log("{$changedcaps} capability changes on the " . VZDC_WS_ROLE . ' role');
    $systemcontext->mark_dirty();
}
if (!user_has_role_assignment($wsuser->id, $roleid, $systemcontext->id)) {
    role_assign($roleid, $wsuser->id, $systemcontext->id);
    vzdc_log('assigned the ' . VZDC_WS_ROLE . ' role');
}
if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $wsuser->id])) {
    $DB->insert_record('external_services_users', (object) ['externalserviceid' => $service->id,
        'userid' => $wsuser->id, 'iprestriction' => null, 'validuntil' => null, 'timecreated' => time()]);
    vzdc_log('authorised the ' . VZDC_WS_NAME . ' user on the service');
}

// The token the website already holds. When it is set, it is the only token
// for this user and service, so changing the secret rotates it.
$token = strtolower(vzdc_getenv('MOODLE_WEBSITE_TOKEN'));
if ($token === '') {
    vzdc_log('MOODLE_WEBSITE_TOKEN not set; no token created');
} else if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
    vzdc_log('warning: MOODLE_WEBSITE_TOKEN is not 32 hexadecimal characters; no token created');
} else {
    $tokens = $DB->get_records('external_tokens', ['userid' => $wsuser->id, 'externalserviceid' => $service->id,
        'tokentype' => EXTERNAL_TOKEN_PERMANENT]);
    $found = false;
    foreach ($tokens as $record) {
        if (hash_equals($record->token, $token)) {
            $found = true;
        } else {
            $DB->delete_records('external_tokens', ['id' => $record->id]);
            vzdc_log('removed an older ' . VZDC_WS_NAME . ' token');
        }
    }
    if (!$found) {
        $DB->insert_record('external_tokens', (object) [
            'token' => $token,
            'privatetoken' => random_string(64),
            'tokentype' => EXTERNAL_TOKEN_PERMANENT,
            'userid' => $wsuser->id,
            'externalserviceid' => $service->id,
            'contextid' => $systemcontext->id,
            'creatorid' => get_admin()->id,
            'validuntil' => 0,
            'timecreated' => time(),
            'name' => VZDC_WS_NAME,
        ]);
        vzdc_log('created the ' . VZDC_WS_NAME . ' token');
    }
}

// -----------------------------------------------------------------------------
// Caches. A new image can change the theme's SCSS or images without a plugin
// version bump, and the theme revision in the CSS URL is what makes browsers
// and Cloudflare fetch the new CSS. So purge whenever the image changes.
// -----------------------------------------------------------------------------
$build = vzdc_getenv('VZDC_BUILD') ?: 'unknown';
if (vzdc_config('imagebuild', $build, 'theme_vzdc')) {
    $vzdcpurge = true;
}
if ($vzdcpurge) {
    purge_all_caches();
    vzdc_log('purged all caches');
}

vzdc_log('done');
exit(0);
