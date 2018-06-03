#!/usr/bin/env bash
set -e
echo "export DOCKER_HOST=$DOCKER_HOST" >> /etc/profile
echo "export DOCKER_HOST DEFAULT=$DOCKER_HOST" >> /etc/security/pam_env.conf

/code/docker-tests/wait-for-it.sh -t 0 database:3306 -- echo "Database is up"
sleep 5s

# run composer without scripts copy parameters manually and run install scripts to build bootstrap
composer install --no-scripts
cp /code/docker-tests/parameters.yml /code/vendor/keboola/syrup/app/config/
cp /code/docker-tests/parameters_shared.yml /code/vendor/keboola/syrup/app/config/
cp /code/docker-tests/config_dev.yml /code/vendor/keboola/syrup/app/config/
cp /code/docker-tests/config_prod.yml /code/vendor/keboola/syrup/app/config/
cp /code/docker-tests/config.yml /code/vendor/keboola/syrup/app/config/
composer run-script build-bootstrap
php /code/vendor/keboola/syrup/app/console syrup:create-index

service ssh start
mkdir -p /var/run/apache2
chmod a+rw /var/run/apache2

echo "Creating the job"
export JOB_ID=279278925
/code/docker-tests/run-job/createJob.sh

echo "Running the job"
/usr/local/bin/php -dxdebug.remote_enable=1 -dxdebug.remote_mode=req -dxdebug.remote_port=9000 -dxdebug.remote_connect_back=0 -dxdebug.remote_autostart=1 -dxdebug.idekey=PHPSTORM -dxdebug.remote_host=host.docker.internal /code/vendor/keboola/syrup/app/console syrup:run-job $JOB_ID
echo "Job finished with result $?"
exit