# Moodle 5.3 for the vZDC Learning Center (https://learn.vzdc.org).
#
# Pinned versions are listed in VERSIONS; keep the ARG defaults below in step.

ARG BASE_IMAGE=moodlehq/moodle-php-apache:8.3-bookworm@sha256:1db7b6ed013fcb5268095b5e28c410ba9117d38d9b6a324c100c48fcbe69745e

ARG COMPOSER_IMAGE=composer:2.10.3@sha256:2cf275428757e5435a667bfc0d038b14520ca02be2b1b70d44a05c8c19e2de48

FROM ${COMPOSER_IMAGE} AS composer

# ---------------------------------------------------------------------------
# Sources, fetched by exact commit and verified.
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE} AS source

COPY --from=composer /usr/bin/composer /usr/local/bin/composer

ARG MOODLE_COMMIT=cfe123f26fc7071981fe949cb16cd5a08a8234f1
ARG BOOST_UNION_COMMIT=0a4c5951cdbf2a1a45a6be1f855b076af37bcfdb

# fetch_commit <repository> <commit> <directory>
RUN set -eu; \
    fetch_commit() { \
        git init -q "$3"; \
        git -C "$3" fetch -q --depth 1 "$1" "$2"; \
        git -C "$3" -c advice.detachedHead=false checkout -q FETCH_HEAD; \
        test "$(git -C "$3" rev-parse HEAD)" = "$2"; \
        rm -rf "$3/.git"; \
    }; \
    fetch_commit https://github.com/moodle/moodle.git "$MOODLE_COMMIT" /src/moodle; \
    fetch_commit https://github.com/moodle-an-hochschulen/moodle-theme_boost_union.git "$BOOST_UNION_COMMIT" /src/boost_union

# Moodle 5.1+ expects its Composer dependencies in vendor/, installed exactly
# as the Environment page checks for. It only adds vendor/, from Moodle's own
# composer.lock; no Moodle file changes.
RUN cd /src/moodle \
    && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --classmap-authoritative \
        --no-interaction --no-progress --no-scripts

# Additional plugins go here, each pinned to a commit and fetched the same way,
# then copied into its own directory under public/ in the final stage. Slots
# reserved for vZDC's own plugins (see README.md, "Plugin slots"):
#
#   local_vzdc         -> /var/www/html/public/local/vzdc
#   availability_vzdc  -> /var/www/html/public/availability/condition/vzdc
#   quizaccess_vzdc    -> /var/www/html/public/mod/quiz/accessrule/vzdc
#
# RUN fetch ... (as above) https://github.com/vzdc-artcc/moodle-local_vzdc.git <commit> /src/local_vzdc

# ---------------------------------------------------------------------------
# Runtime image.
# ---------------------------------------------------------------------------
FROM ${BASE_IMAGE}

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# Moodle core, unmodified, owned by root so the web server cannot change it.
COPY --from=source /src/moodle /var/www/html
COPY --from=source /src/boost_union /var/www/html/public/theme/boost_union
COPY theme/vzdc /var/www/html/public/theme/vzdc

# COPY --from=source /src/local_vzdc        /var/www/html/public/local/vzdc
# COPY --from=source /src/availability_vzdc /var/www/html/public/availability/condition/vzdc
# COPY --from=source /src/quizaccess_vzdc   /var/www/html/public/mod/quiz/accessrule/vzdc

COPY config/config.php /var/www/html/config.php
COPY config/php/zz-vzdc.ini /usr/local/etc/php/conf.d/zz-vzdc.ini
COPY config/apache/moodle.conf /etc/apache2/sites-available/000-default.conf
COPY config/apache/docker-php.conf /etc/apache2/conf-available/docker-php.conf
COPY config/apache/security.conf /etc/apache2/conf-available/security.conf
COPY bin/moodle-init bin/moodle-cron /usr/local/bin/
COPY bin/site-config.php bin/preflight.php /opt/vzdc/

RUN set -eux; \
    # The base image's global "FallbackResource /r.php" moves into our vhost.
    rm -f /etc/apache2/conf-enabled/fallback.conf; \
    a2enmod rewrite; \
    # Code-coverage driver: development only.
    rm -f /usr/local/etc/php/conf.d/docker-php-ext-pcov.ini /usr/local/etc/php/conf.d/10-docker-php-ext-pcov.ini; \
    chown -R root:root /var/www/html; \
    chmod -R u=rwX,go=rX /var/www/html; \
    chmod 0755 /usr/local/bin/moodle-init /usr/local/bin/moodle-cron; \
    mkdir -p /var/www/moodledata /var/www/moodlecache; \
    chown www-data:www-data /var/www/moodledata /var/www/moodlecache; \
    chmod 2770 /var/www/moodledata /var/www/moodlecache; \
    php -l /var/www/html/config.php; \
    php -l /opt/vzdc/site-config.php; \
    php -l /opt/vzdc/preflight.php; \
    apache2ctl -t

# The build that is running; site-config.php purges caches when it changes.
ARG VZDC_BUILD=dev
ENV VZDC_BUILD=${VZDC_BUILD}

LABEL org.opencontainers.image.source="https://github.com/vzdc-artcc/vzdc-moodle" \
      org.opencontainers.image.description="Moodle 5.3 for the vZDC Learning Center" \
      org.opencontainers.image.licenses="GPL-3.0-or-later"

WORKDIR /var/www/html
EXPOSE 80

# The base image's entrypoint rewrites PHP and Apache config at start-up from
# environment variables; this image is configured at build time instead.
ENTRYPOINT ["docker-php-entrypoint"]
CMD ["apache2-foreground"]
