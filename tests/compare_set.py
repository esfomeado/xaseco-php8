#!/usr/bin/env python3
"""Timing-insensitive comparison: count each distinct (normalized) call over
the whole run and list the calls whose counts differ between orig and port.
Usage: compare_set.py run-orig/calls.jsonl run-port/calls.jsonl"""
import json, sys, re
from collections import Counter
sys.path.insert(0, sys.path[0])
from compare import norm  # same normalization rules

def load(path):
    c = Counter()
    with open(path, encoding='utf-8', errors='replace') as f:
        for line in f:
            step, method, params = json.loads(line)
            c[norm(method + ' ' + json.dumps(params, ensure_ascii=False, sort_keys=True))] += 1
    return c

a, b = load(sys.argv[1]), load(sys.argv[2])
only = [(k, a[k], b[k]) for k in set(a) | set(b) if a[k] != b[k]]
only.sort(key=lambda t: (t[0].split(' ')[0], t[0]))
for k, x, y in only:
    print(f'orig {x:3d}  port {y:3d}  {k[:260]}')
print(f'\n{len(only)} distinct calls differ in count (of {len(set(a) | set(b))})', file=sys.stderr)
