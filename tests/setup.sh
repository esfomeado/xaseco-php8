#!/bin/bash
# One-time setup: an internal Docker network (no internet access, so nothing
# reaches Dedimania, Mania-Karma or TMX), a MariaDB 12 test database and the
# two PHP images.
set -e
T=$(cd "$(dirname "$0")" && pwd)
docker network inspect xaseco-test-net >/dev/null 2>&1 || docker network create --internal xaseco-test-net >/dev/null
if ! docker inspect xaseco-test-db >/dev/null 2>&1; then
	docker run -d --name xaseco-test-db --network xaseco-test-net -e MARIADB_ROOT_PASSWORD=testpass mariadb:12 >/dev/null
fi
docker build -q -t xaseco-test-php85 "$T/docker/php85"
docker build -q -t xaseco-test-php54 "$T/docker/php54"   # compiles PHP 5.4, takes a few minutes
until docker exec xaseco-test-db mariadb -uroot -ptestpass -e 'select 1' >/dev/null 2>&1; do sleep 1; done
echo "ready"
