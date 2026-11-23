#!/bin/bash
# Run XAseco through a scenario against the fake dedicated server and record
# every call it makes. Run setup.sh once first.
#
# Usage: run.sh port|orig <scenario.php> [max seconds]
#   port = this repository (working tree) on Ubuntu 26.04 / PHP 8.5
#   orig = XAseco 1.16 (the repository's first commit) on Ubuntu 14.04 / PHP 5.4.45
#
# Environment:
#   CONFIG_DIR    server files laid over XAseco: config.xml, plugins.xml, plugin
#                 configs, rasp.settings.php, plugins/ (default: the stock newinstall/ files)
#   GAMEDATA_DIR  dedicated server GameData/ with Tracks/Campaigns/Downloaded/*.Challenge.Gbx
#   PLUGINS_DIR   extra plugins/ copied over CONFIG_DIR's (port runs); orig runs use
#                 the first commit of PLUGINS_DIR's git repository, if it has one
#   PRE           script run in the assembled XAseco directory (e.g. log-all-levels.sh)
#   DB_RESTART_AFTER  restart the database this many seconds into the run
#
# Output in run-<mode>/: aseco.out (XAseco's console, ends with SCENARIO-DONE or
# SCENARIO-STOPPED-EARLY), calls.jsonl (every call: [scenario step, method, params]),
# fake.log.
set -e
T=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$T/.." && pwd)
MODE=$1; SCEN=$2; SECS=${3:-900}
[ "$MODE" = port ] || [ "$MODE" = orig ] || { echo "usage: $0 port|orig <scenario.php> [seconds]" >&2; exit 2; }
[ -f "$SCEN" ] || SCEN=$T/scenarios/$SCEN
[ -f "$SCEN" ] || { echo "no scenario $2" >&2; exit 2; }
SCEN=$(cd "$(dirname "$SCEN")" && pwd)/$(basename "$SCEN")
[ -d "$GAMEDATA_DIR/Tracks" ] || { echo "set GAMEDATA_DIR to a GameData/ directory with Tracks/" >&2; exit 2; }
R=$T/run-$MODE
docker rm -f xaseco-test-fake xaseco-test-aseco >/dev/null 2>&1 || true
rm -rf "$R" && mkdir -p "$R"

# assemble XAseco like a fresh installation
if [ "$MODE" = orig ]; then
	git -C "$REPO" archive --format=tar --prefix=xaseco/ "$(git -C "$REPO" rev-list --max-parents=0 HEAD)" | tar x -C "$R"
	IMG=xaseco-test-php54
else
	git -C "$REPO" archive --format=tar --prefix=xaseco/ HEAD | tar x -C "$R"
	(cd "$REPO" && git diff --name-only HEAD; git ls-files --others --exclude-standard) | grep -v '^tests/' | while read -r f; do
		[ -f "$REPO/$f" ] && cp "$REPO/$f" "$R/xaseco/$f"
	done
	IMG=xaseco-test-php85
fi
rm -rf "$R/xaseco/tests"
cd "$R/xaseco"
mv newinstall/*.php includes/ && mv newinstall/*.xml . && mv newinstall/AsecoF.sh .
[ -n "$CONFIG_DIR" ] && cp -r "$CONFIG_DIR"/. .
if [ -n "$PLUGINS_DIR" ]; then
	if [ "$MODE" = port ]; then
		cp -r "$PLUGINS_DIR"/. plugins/
	elif git -C "$PLUGINS_DIR" rev-parse >/dev/null 2>&1; then
		top=$(git -C "$PLUGINS_DIR" rev-parse --show-toplevel)
		rel=$(realpath --relative-to="$top" "$PLUGINS_DIR")
		git -C "$top" archive --format=tar "$(git -C "$top" rev-list --max-parents=0 HEAD)" "$rel" | tar x -C "$R"
		cp -r "$R/$rel"/. plugins/
	fi
fi
[ -f rasp.settings.php ] && mv rasp.settings.php includes/rasp.settings.php

# point it at the fake server and the test database, add a test MasterAdmin
php -r '
$c = file_get_contents("config.xml");
$c = preg_replace("#(<tmserver>.*?<password>)[^<]*#s", "\${1}x", $c);
$c = preg_replace("#(<tmserver>.*?<ip>)[^<]*#s", "\${1}xaseco-test-fake", $c);
$c = preg_replace("#<masteradmins>#", "<masteradmins>\n      <tmlogin>testadmin</tmlogin>", $c, 1);
file_put_contents("config.xml", $c);
$l = file_get_contents("localdatabase.xml");
foreach (["mysql_server" => "xaseco-test-db", "mysql_login" => "root", "mysql_password" => "testpass", "mysql_database" => "xaseco"] as $k => $v)
	$l = preg_replace("#<$k>[^<]*#", "<$k>$v", $l);
file_put_contents("localdatabase.xml", $l);
'
[ -n "$PRE" ] && bash "$(cd "$T" && realpath "$PRE")"
mkdir -p "$R/GameData" && cp -r "$GAMEDATA_DIR"/. "$R/GameData/"
# the fake server reports Dedimania's configured login as its own (Dedimania checks it)
SL=$(grep -oP '(?<=<login>)[^<]*' dedimania.xml 2>/dev/null | head -1)

# fresh database for every run
docker exec xaseco-test-db mariadb -uroot -ptestpass -e 'DROP DATABASE IF EXISTS xaseco; CREATE DATABASE xaseco;'

U="$(id -u):$(id -g)"
docker run -d --name xaseco-test-fake --user "$U" --network xaseco-test-net -e SERVER_LOGIN="${SL:-testserver}" -e CALLS_FILE=/r/calls.jsonl \
	-v "$R":/r -v "$T":/t -v "$(dirname "$SCEN")":/s xaseco-test-php85 \
	php -d error_reporting=-1 /t/fake_tmserver.php 5000 /r/GameData/Tracks "/s/$(basename "$SCEN")" /r/fake.log /r/xaseco/includes/gbxdatafetcher.inc.php >/dev/null
sleep 2
docker run -d --name xaseco-test-aseco --user "$U" --network xaseco-test-net -v "$R":/r -w /r/xaseco $IMG \
	bash -c 'php -d error_reporting=-1 -d display_errors=stderr -d date.timezone=UTC aseco.php TMF > /r/aseco.out 2>&1' >/dev/null
[ -n "$DB_RESTART_AFTER" ] && (sleep "$DB_RESTART_AFTER"; docker restart xaseco-test-db >/dev/null) &
for i in $(seq 1 "$SECS"); do
	[ -f "$R/scenario.done" ] && break
	[ "$(docker inspect -f '{{.State.Running}}' xaseco-test-aseco 2>/dev/null)" = true ] || break
	sleep 1
done
[ -f "$R/scenario.done" ] && echo SCENARIO-DONE >> "$R/aseco.out" || echo SCENARIO-STOPPED-EARLY >> "$R/aseco.out"
docker rm -f xaseco-test-aseco xaseco-test-fake >/dev/null 2>&1
wait

# summary: PHP errors of any level (XAseco's handler prints warnings; PRE=log-all-levels.sh the rest)
echo "$MODE: $(tail -1 "$R/aseco.out"), $(wc -l < "$R/calls.jsonl") server calls, PHP messages: $(tr -d '\r' < "$R/aseco.out" | grep -cE '^\[PHP |^PHP |^(Warning|Deprecated|Notice|Fatal error):' || true)"
tr -d '\r' < "$R/aseco.out" | grep -E '^\[PHP |^PHP ' | sed -E 's/ on line ([0-9]+) in file \/r\/xaseco\/(.*)$/ @\2:\1/' | sort | uniq -c | sort -rn | head -20 || true
