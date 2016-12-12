#!/usr/bin/env bash
set -e
echo "export DOCKER_HOST=$DOCKER_HOST" >> /etc/profile
echo "export DOCKER_HOST DEFAULT=$DOCKER_HOST" >> /etc/security/pam_env.conf

# run composer without scripts copy parameters manually and run install scripts to build bootstrap
composer install --no-scripts
cp /buildcode/parameters.yml /code/vendor/keboola/syrup/app/config/
cp /buildcode/parameters_shared.yml /code/vendor/keboola/syrup/app/config/
cp /buildcode/config_dev.yml /code/vendor/keboola/syrup/app/config/
composer run-script build-bootstrap
php /code/vendor/keboola/syrup/app/console syrup:create-index

# curl -X POST -H "Content-Type: application/json" -H "Cache-Control: no-cache" -d '{
#	# "actions" : [
#        { "add" : { "index" : "devel_syrup_docker_2016_1", "alias" : "devel_syrup_docker" } }
#    ]
#}' "http://elastic:9200/_aliases/"

service ssh start
mkdir -p /var/run/apache2
chmod a+rw /var/run/apache2
apache2-foreground
