#!/bin/bash
# Click every known button (scenarios/data/buttons.json), add the buttons of the
# windows that opened, and repeat until no new buttons appear.
# Same environment as run.sh.
T=$(cd "$(dirname "$0")" && pwd); cd "$T"
B=scenarios/data/buttons.json
for round in 1 2 3 4 5 6; do
	before=$(python3 -c "import json; print(len(json.load(open('$B'))))")
	PRE=${PRE:-log-all-levels.sh} ./run.sh port crawl.php 1800
	python3 - "$B" <<'PY'
import json, re, sys
path = sys.argv[1]
acts = {k: set(v) for k, v in json.load(open(path)).items()}
for l in open('run-port/calls.jsonl', encoding='utf-8', errors='replace'):
    s, m, a = json.loads(l)
    if m in ('SendDisplayManialinkPageToLogin', 'SendDisplayManialinkPage'):
        xml = a[1] if m.endswith('ToLogin') else a[0]
        for x in re.findall(r'action="(-?\d+)"', xml):
            acts.setdefault(str(int(x)), set()).add(a[0] if m.endswith('ToLogin') else '*')
json.dump({k: sorted(v) for k, v in acts.items()}, open(path, 'w'))
PY
	after=$(python3 -c "import json; print(len(json.load(open('$B'))))")
	echo "round $round: $before -> $after buttons"
	[ "$after" = "$before" ] && break
done
