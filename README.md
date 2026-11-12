# vzdc-moodle

The container image for the Virtual Washington ARTCC Learning Center, https://learn.vzdc.org:
Moodle 5.3 LTS with the Boost Union theme and the vZDC child theme, configured entirely
from environment variables. The Kubernetes manifests that run it live in the infrastructure
repository; this repository only builds the image.

```
ghcr.io/vzdc-artcc/vzdc-moodle:<short git SHA>
```

Every push to `main` and every pull request builds `linux/amd64` and pushes a tag named after
the commit's short SHA. There is no `latest`. The job summary prints the full
`image:tag@sha256:digest` reference to deploy.

## What is pinned

| Component | Version | Where |
|---|---|---|
| Moodle | 5.3.1, commit `cfe123f26fc7071981fe949cb16cd5a08a8234f1` (tag `v5.3.1`) | `Dockerfile`, `VERSIONS` |
| Boost Union | `v5.3-r1`, commit `0a4c5951cdbf2a1a45a6be1f855b076af37bcfdb` | `Dockerfile`, `VERSIONS` |
| Base image | `moodlehq/moodle-php-apache:8.3-bookworm@sha256:1db7b6ed…` (PHP 8.3) | `Dockerfile`, `VERSIONS` |
| Composer (build only) | `composer:2.10.3@sha256:2cf27542…` | `Dockerfile`, `VERSIONS` |
| Brand assets | `vzdc-artcc/website` commit `5659dda41ec4377781173afa65d458ed6a660314` | `theme/vzdc/pix/SOURCE.md` |
| Roboto | `@fontsource/roboto` 5.3.0 (SIL OFL 1.1) | `theme/vzdc/fonts/` |

Moodle and Boost Union are fetched by commit and the build fails if the checked-out commit is
not the pinned one. Moodle core is never modified; the build only adds `vendor/` with
`composer install --no-dev --classmap-authoritative`, which Moodle 5.1+ expects.

## Layout inside the image

| Path | What |
|---|---|
| `/var/www/html` | Moodle code, owned by root and read-only to the web server |
| `/var/www/html/public` | Apache's document root (Moodle 5.1+ serves only `public/`) |
| `/var/www/html/config.php` | `config/config.php`, reads only environment variables |
| `/var/www/html/public/theme/boost_union` | Boost Union |
| `/var/www/html/public/theme/vzdc` | this repository's `theme/vzdc` |
| `/var/www/moodledata` | dataroot; mount a persistent volume |
| `/var/www/moodlecache` | `$CFG->localcachedir`; mount an `emptyDir` |
| `/opt/vzdc/` | `site-config.php` and `preflight.php`, used by `moodle-init` |

Both data paths must be writable by `www-data` (uid/gid 33); the pod sets `fsGroup: 33`.

## Commands

| Command | Use |
|---|---|
| *(default)* `apache2-foreground` | The web server, plain HTTP on port 80. Apache starts as root and serves as `www-data`. |
| `moodle-init` | The initContainer, run on every rollout. Waits for PostgreSQL and Redis, installs Moodle into an empty database or upgrades an existing one (a no-op when nothing changed), then runs `site-config.php`. Idempotent, exits 0 on success, prints no secrets. |
| `moodle-cron` | The cron sidecar. Runs `admin/cli/cron.php` as `www-data` every 60 seconds, writes the epoch to `/tmp/cron-last-success` after each successful run, and GETs `MOODLE_CRON_HEARTBEAT_URL` if set. |

Both scripts drop to `www-data` when started as root and run as they are when the pod already
sets `runAsUser: 33`.

The image's stop signal is `SIGWINCH`, inherited from the php:apache base, where it stops
Apache gracefully. `moodle-cron` treats it like `SIGTERM`: it passes the signal on to a running
`cron.php` and exits.

A kubelet probe can use a plain HTTP GET of `/login/index.php` with the header
`Host: learn.vzdc.org`. It returns 200, with or without `X-Forwarded-Proto`, because
`$CFG->sslproxy` makes Moodle treat every request as HTTPS.

## Environment variables

| Variable | Required | Used by | Notes |
|---|---|---|---|
| `MOODLE_WWWROOT` | yes | config.php | `https://learn.vzdc.org` |
| `MOODLE_DB_HOST` | yes | config.php | PostgreSQL 17 |
| `MOODLE_DB_PORT` | no | config.php | default `5432` |
| `MOODLE_DB_NAME` | yes | config.php | |
| `MOODLE_DB_USER` | yes | config.php | |
| `MOODLE_DB_PASSWORD` | yes | config.php | |
| `MOODLE_REDIS_HOST` | yes | config.php, site-config | sessions and the application cache |
| `MOODLE_REDIS_PORT` | no | config.php, site-config | default `6379` |
| `MOODLE_REDIS_PASSWORD` | no | config.php, site-config | |
| `MOODLE_ADMIN_PASSWORD` | on first install | moodle-init, site-config | break-glass local admin `vzdcadmin`; changing it rotates the password on the next rollout |
| `MOODLE_ADMIN_EMAIL` | on first install | moodle-init, site-config | |
| `MOODLE_SMTP_USER` | no | site-config | AWS SES SMTP; when either SMTP variable is empty, mail settings are left alone |
| `MOODLE_SMTP_PASSWORD` | no | site-config | |
| `MOODLE_VATSIM_CLIENT_ID` | no | site-config | when empty, the VATSIM issuer stays disabled |
| `MOODLE_VATSIM_CLIENT_SECRET` | no | site-config | |
| `MOODLE_WEBSITE_TOKEN` | no | site-config | 32 hex characters; when empty, no token is created |
| `MOODLE_CRON_HEARTBEAT_URL` | no | moodle-cron | never printed |
| `MOODLE_CRON_INTERVAL` | no | moodle-cron | seconds, default `60` |
| `MOODLE_INIT_WAIT_SECONDS` | no | moodle-init | how long to wait for PostgreSQL and Redis, default `300` |

`VZDC_BUILD` is set at build time to the short commit SHA. `site-config.php` purges all caches
when it changes, so new theme SCSS or images reach browsers (and Cloudflare) under a new theme
revision.

## Site configuration

`bin/site-config.php` runs at the end of every `moodle-init`. Each step reads the current state
and writes only what differs, so a second run changes nothing. **The settings below are owned by
the script: a value changed in the admin UI is put back on the next rollout.**

- **Site:** full name *Virtual Washington ARTCC Learning Center*, short name *vZDC Learn*,
  timezone America/New_York, language `en`, `forcelogin` on, guest login button off,
  self-registration off (and the email-based self-registration plugin disabled),
  `noreplyaddress` `no-reply@vzdc.org`, "Prevent account creation when authenticating" on.
- **Theme:** `vzdc`; Boost Union brand colour `#500E0E`; Boost Union footnote with the footer
  links; core colour modes on, default light.
- **Cache:** a Redis store named `redis` (prefix `vzdc_muc_`, igbinary) mapped as the default for
  application caches. Session caches stay on the default session store, whose data already lives
  in the PHP session, and so in Redis. PHP sessions use Redis directly (`config.php`, prefix
  `vzdc_sess_`, with locking). Moodle writes the cache store configuration, including the Redis
  password, to `moodledata/muc/config.php`; that is how Moodle's cache API works.
- **SMTP** (when both credentials are set): `email-smtp.us-east-1.amazonaws.com:587`, TLS,
  LOGIN.
- **VATSIM Connect:** a custom OAuth 2 issuer named *VATSIM* (authorize
  `https://auth.vatsim.net/oauth/authorize`, token `https://auth.vatsim.net/oauth/token`,
  userinfo `https://auth.vatsim.net/api/user`), scopes `full_name vatsim_details email`,
  mappings `data-personal-name_first` to firstname, `data-personal-name_last` to lastname,
  `data-personal-email` to email, `data-cid` to idnumber, shown on the login page, email
  confirmation for linking off. The issuer and the OAuth 2 authentication plugin are enabled
  only once the issuer has a client ID and secret, from the environment or the admin UI.
  Because account creation is prevented, VATSIM only logs in people who already have an
  account; an account with the same email address is linked on first login.
- **Web services:** web services and the REST protocol on. External service `vzdc-website`
  (authorised users only) with every function matching `core_user_*`, `core_cohort_*`,
  `enrol_manual_*`, `core_completion_*`, `gradereport_user_*` in this Moodle version (see
  below). User `vzdc-website` (auth `webservice`, which refuses interactive logins). Role
  `vzdcwebsite` at system context with `webservice/rest:use` and the capabilities those
  functions declare, **except `moodle/site:config`** (see below). When `MOODLE_WEBSITE_TOKEN`
  is set, it is the user's only permanent token for the service: changing it rotates the
  token.

### Web service functions (Moodle 5.3.1)

The prefixes resolve to these 40 functions:

```
core_cohort_add_cohort_members
core_cohort_create_cohorts
core_cohort_delete_cohort_members
core_cohort_delete_cohorts
core_cohort_get_cohort_members
core_cohort_get_cohorts
core_cohort_search_cohorts
core_cohort_update_cohorts
core_completion_get_activities_completion_status
core_completion_get_course_completion_status
core_completion_mark_course_self_completed
core_completion_override_activity_completion_status
core_completion_update_activity_completion_status_manually
core_user_add_user_device
core_user_add_user_private_files
core_user_agree_site_policy
core_user_create_users
core_user_delete_users
core_user_get_course_user_profiles
core_user_get_private_files_info
core_user_get_user_preferences
core_user_get_users
core_user_get_users_by_field
core_user_prepare_private_files_for_edition
core_user_remove_user_device
core_user_search_identity
core_user_set_user_preferences
core_user_update_picture
core_user_update_private_files
core_user_update_user_device_public_key
core_user_update_user_preferences
core_user_update_users
core_user_view_user_list
core_user_view_user_profile
enrol_manual_enrol_users
enrol_manual_unenrol_users
gradereport_user_get_access_information
gradereport_user_get_grade_items
gradereport_user_get_grades_table
gradereport_user_view_grade_report
```

`core_user_set_user_preferences` declares `moodle/site:config` so that it can set other
people's preferences. Granting that would make the website token a site administrator in all
but name, so the role leaves it out. That function works on the website user's own preferences
only. The service also does not include `core_webservice_get_site_info`, since it is outside the
prefixes.

With `moodle/site:config` left out, Moodle's security checks raise nothing about the role. The
one remaining security warning on a fresh site is core's default "Backup of user data" for the
Manager role.

## Theme

`theme_vzdc` is a grandchild of Boost (`parents = ['boost_union', 'boost']`), built like the
Boost Union Child boilerplate: it inherits Boost Union's configuration and SCSS and adds its own.

- **Colours:** primary `#500E0E`, text on primary `#EDEDF5` (Bootstrap's
  `$color-contrast-light`, plus explicit button colours).
- **Font:** Roboto 400/500/700 and 400 italic, Latin and Latin Extended, served from the theme.
  No font CDN.
- **Logos and favicon:** shipped in `theme/vzdc/pix/` from the website repository. The renderer
  returns `logo_large` as the main logo (login page) and `logo` as the compact logo (navbar).
  Core picks up `pix/favicon.ico` by itself. Nothing is uploaded through the admin UI.
- **Login page:** Moodle 5.2+ core lays the login page out side by side. The theme replaces
  core's stock photograph (and its "AI-generated image" caption) in the left panel with
  `home-bg.png` on a light `#EDEDF5` field. The form on the right shows the large logo. The left
  panel is hidden on narrow screens, as in core.
- **Footer:** *Virtual Washington ARTCC* (https://vzdc.org), *Privacy*
  (https://vzdc.org/privacy) and *Back to vZDC* (https://vzdc.org), set as Boost Union's
  footnote. That footnote shows on every page, including the login page. Boost Union's
  "Footnote on login page" recommendation shows as *check*: it only asks you to look at how the
  footnote sits in the side-by-side login column.

### Light and dark mode

Moodle 5.3 core has colour modes (light, dark, follow the device), built on Bootstrap 5.3's
`data-bs-theme`. Core marks them **experimental**, and they are off until a site turns on
`theme_boost | enablecolourmodes`. They work for any theme whose parents include Boost, so
they work here; Boost Union 5.3 adapts its navbar to them. `site-config.php` turns them on with
**light** as the site default. Each person can switch from the colour mode menu in the navbar,
and the choice is also kept in a cookie so the login page follows it.

Checked in a browser: the login page and the dashboard render in both modes. In dark mode the
theme swaps the navbar logo for `logo-light.png`, sets link colours to light tints of the
brand colour (`$link-color-dark`), and puts the large login logo on a white plate (the source
PNG is opaque white). What is *not* verified: every core and plugin page in dark mode. Core
calls the feature experimental for exactly that reason. To make dark mode follow the device by
default, change `defaultcolourmode` to `auto` in `site-config.php`.

## Plugin slots

These slots are reserved for vZDC's own plugins. They are not built yet. Each goes in its own
directory under `public/`, is pinned to a commit in the `Dockerfile` the way Boost Union is
(commented placeholders are already there), and is installed by `moodle-init` through
`upgrade.php` on the next rollout.

| Plugin | Directory in the image | Purpose |
|---|---|---|
| `local_vzdc` | `public/local/vzdc` | vZDC-specific integration (roster and training sync with the website, event handlers) |
| `availability_vzdc` | `public/availability/condition/vzdc` | Restrict access by vZDC rating or certification |
| `quizaccess_vzdc` | `public/mod/quiz/accessrule/vzdc` | vZDC quiz access rules |

## Testing locally

`test/compose.yaml` runs the image against `postgres:17` and Valkey 8, with throwaway
credentials. Caddy plays the TLS-terminating proxy as `learn.vzdc.org` on the compose network,
so Moodle's router check can make real HTTPS requests to its own wwwroot. The `web` container
trusts Caddy's local CA through a test-only PHP ini; the image is not changed.

```sh
docker buildx build --platform linux/amd64 --load -t vzdc-moodle:local .
docker compose -f test/compose.yaml up -d db valkey caddy
docker compose -f test/compose.yaml run --rm init      # fresh install
docker compose -f test/compose.yaml run --rm init      # second run: upgrade path, no-op
docker compose -f test/compose.yaml up -d web cron

# The kubelet probe
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: learn.vzdc.org' -H 'X-Forwarded-Proto: https' \
  http://127.0.0.1:8080/login/index.php

# Environment page and system checks (router included)
docker compose -f test/compose.yaml exec -T --user www-data web php < test/environment.php
docker compose -f test/compose.yaml exec -T --user www-data web php admin/cli/checks.php -v --filter=router
for t in status security performance; do
  docker compose -f test/compose.yaml exec -T --user www-data web php admin/cli/checks.php --type=$t
done

docker compose -f test/compose.yaml down -v
```

## Updating

- **Moodle:** change `MOODLE_COMMIT` (a tag commit on `MOODLE_503_STABLE`) in the `Dockerfile`
  and `VERSIONS`. `moodle-init` runs the upgrade on the next rollout.
- **Boost Union:** change `BOOST_UNION_COMMIT` to a `v5.3-rN` tag commit.
- **Brand assets:** copy the files again from the website repository and update the SHA in
  `theme/vzdc/pix/SOURCE.md` and `VERSIONS`.
- **Theme changes** need no version bump: a new image is a new `VZDC_BUILD`, which purges the
  caches.

## Licence

GPL v3 or later, like Moodle. The brand assets come from the vZDC website repository
(Apache-2.0); Roboto is under the SIL Open Font License 1.1 (`theme/vzdc/fonts/LICENSE-OFL.txt`).
