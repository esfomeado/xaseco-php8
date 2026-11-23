#!/bin/bash
# Regenerate scenarios/data/*.txt (the chat commands the plugins in plugins.xml
# register, and the subcommands of /admin, /jfreu, /music and /list).
# Usage: gen-commands.sh <plugins.xml> <plugin dir>...   (first match wins)
T=$(cd "$(dirname "$0")" && pwd); D=$T/scenarios/data
XML=$1; shift
for p in $(grep -oP '(?<=^\t<plugin>)[^<]+' "$XML"); do
	for d in "$@"; do [ -f "$d/$p" ] && { grep -ohE "addChatCommand\('[^']+'" "$d/$p" | sed -E "s/addChatCommand\('//; s/'//; s#/.*##"; break; }; done
done | sort -u > "$D/cmds.txt"
sub() { for d in "$@"; do [ -f "$d" ] && grep -ohE "\\\$command\['params'\]\[0\] == '[a-z0-9]+'" "$d" | grep -oE "'[a-z0-9]+'" | tr -d "'"; done | sort -u; }
R=$T/..
# /admin shutdown and shutdownall stop XAseco on purpose
sub "$R/plugins/chat.admin.php" | grep -vE '^(shutdown|shutdownall)$' > "$D/admin.txt"
sub "$R/plugins/jfreu.chat.php" > "$D/jfreu.txt"
sub "$R/plugins/plugin.musicserver.php" > "$D/music.txt"
sub "$R/plugins/plugin.rasp_jukebox.php" > "$D/list.txt"
wc -l "$D"/*.txt
