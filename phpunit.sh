#!/usr/bin/env bash
set -e

rm -rf /tmp/docker
chown www-data /tmp/
su -c "./vendor/bin/phpunit --testsuite $1 --debug" www-data
