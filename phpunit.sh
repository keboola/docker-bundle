#!/usr/bin/env bash
set -e

rm -rf /tmp/docker
su -c "./vendor/bin/phpunit --testsuite $1 --debug" www-data
