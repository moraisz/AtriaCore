#!/usr/bin/env sh
# Runs the quality pipeline in the order of .github/workflows/quality.yml.
# Invoked by `composer ci` inside the php service of docker-compose.ci.yml.
set -eu

step() {
    printf '\n==> %s\n' "$*"
    "$@"
}

step composer validate --no-check-publish
step composer install --prefer-dist --no-progress --no-interaction
step composer audit
step composer cs-check
step composer phpstan
# The databases are up, so nothing may be skipped: a skip means untested code.
step composer test -- --fail-on-skipped

printf '\nCI passed.\n'
