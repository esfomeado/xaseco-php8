# Tests

Runs XAseco against a fake TrackMania Forever dedicated server
(`fake_tmserver.php`): it speaks the server's XML-RPC protocol, answers the
methods XAseco calls with data from real track files, plays a scenario of game
events (players joining, chat commands, checkpoints, finishes, race ends,
window clicks) and records every call XAseco makes.

Everything runs in Docker on an internal network with no internet access, with
a MariaDB 12 database. XAseco runs on Ubuntu 26.04 / PHP 8.5, or on Ubuntu
14.04 / PHP 5.4.45 for the original 1.16 (to compare the two).

## Setup

```sh
tests/setup.sh
```

## Running

```sh
export GAMEDATA_DIR=/path/to/GameData        # with Tracks/Campaigns/Downloaded/*.Challenge.Gbx
export CONFIG_DIR=/path/to/server/xaseco      # optional: config.xml, plugins.xml, plugin configs
export PLUGINS_DIR=/path/to/plugins           # optional: extra plugins

tests/run.sh port smoke.php                   # this version
tests/run.sh orig smoke.php                   # XAseco 1.16 on PHP 5.4
PRE=log-all-levels.sh tests/run.sh port commands.php
```

XAseco's own error handler only prints fatal errors and warnings;
`PRE=log-all-levels.sh` makes it print deprecations and notices too.

| Scenario | |
|---|---|
| `smoke.php` | two players join, chat, drive, race ends, next track |
| `commands.php` | every chat command and `/admin`, `/jfreu`, `/music`, `/list` subcommand, as admin and as player, with and without arguments |
| `commands-deterministic.php` | the same without commands whose result depends on the order of tied entries |
| `db-restart.php` | the database restarts with players online (`DB_RESTART_AFTER=18`) |
| `crawl.php` | clicks every button in `scenarios/data/buttons.json` |
| `pay2play.php` | Pay2Play bills: double clicks, paying, clicking again |

`crawl.sh` repeats the crawl and adds the buttons of the windows that opened
until no new ones appear. `gen-commands.sh` regenerates the command lists for a
`plugins.xml`.

## Comparing with the original

```sh
tests/run.sh orig commands-deterministic.php
tests/run.sh port commands-deterministic.php
python3 tests/classify.py tests/run-orig/calls.jsonl tests/run-port/calls.jsonl
```

`compare.py` diffs the calls step by step, `compare_set.py` counts them over
the whole run, and `classify.py` sorts the differences into known causes
(clock times, random music, order of tied entries).
