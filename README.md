# XAseco for PHP 8

A port of [XAseco](https://www.gamers.org/tmn/) 1.16, the server controller for
TrackMania Forever / Nations Forever dedicated servers, to PHP 8.

The original XAseco 1.16 requires PHP 5 and the `mysql_*` extension, which was
removed in PHP 7. This repository migrates its source to run on current PHP
(target: PHP 8.5, as shipped by Ubuntu 26.04 LTS) with `mysqli`.

**Status:** work in progress. The first commit is the unmodified XAseco 1.16
release (`xaseco_116.zip` from gamers.org); every later commit is part of the
migration, so `git diff` against it shows exactly what changed.

## License

XAseco is free software under the GNU General Public License, version 2 or
(at your option) any later version; see [LICENSE](LICENSE). This is a
modified version of XAseco 1.16 by Xymph and the ASECO/RASP authors.
