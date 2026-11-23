# XAseco for PHP 8

XAseco 1.16, the server controller for TrackMania Forever and Nations Forever
dedicated servers, ported to PHP 8.

XAseco connects to the dedicated server and adds local records, Dedimania
world records, karma voting, a track jukebox, chat and admin commands,
statistics and in-game windows. The original release needs PHP 5 and the
`mysql_*` extension; this version runs on current PHP (tested on 8.5) with
`mysqli`, and fixes the bugs that PHP 8 exposed in the original.

## Requirements

- PHP 8 with the `mysqli`, `xml` and `mbstring` extensions
- MySQL or MariaDB
- A TrackMania Forever dedicated server

## Installation

1. Copy the files to the server, move `newinstall/*.php` to `includes/` and
   `newinstall/*.xml` and `newinstall/AsecoF.sh` to the main directory.
2. Set the dedicated server login and the MasterAdmin(s) in `config.xml`, and
   the database in `localdatabase.xml`.
3. Start it with `./AsecoF.sh`.
