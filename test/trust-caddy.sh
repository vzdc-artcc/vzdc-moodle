#!/bin/sh
# Test only: system roots plus Caddy's local root, for zz-test-ca.ini.
set -e
root=/caddy/caddy/pki/authorities/local/root.crt
i=0
while [ ! -f "$root" ] && [ $i -lt 30 ]; do sleep 1; i=$((i+1)); done
cat /etc/ssl/certs/ca-certificates.crt "$root" > /tmp/test-ca.pem
chmod 644 /tmp/test-ca.pem
