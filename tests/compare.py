#!/usr/bin/env python3
"""Diff the server calls of two XAseco runs (orig vs port), step by step.

Usage: compare.py run-orig/calls.jsonl run-port/calls.jsonl [--full]
Prints, per scenario step, the calls that differ after normalizing values
that change between runs (clock times, dates, durations)."""
import json, re, sys, difflib
from collections import defaultdict

NORMALIZE = [
    (re.compile(r'(?<!\d)\d{4}-\d\d-\d\d[ T]\d\d:\d\d(:\d\d)?(?!\d)'), '<DATETIME>'),
    (re.compile(r'\b\d\d/\d\d,\d\d:\d\d(:\d\d)?\b'), '<DATETIME>'),
    (re.compile(r'(?<!\d)\d\d:\d\d:\d\d(?!\d)'), '<TIME>'),
    (re.compile(r'\b\d{9,10}\b'), '<UNIXTIME>'),
    (re.compile(r'(?<![\w.])-0(?![\w.])'), '0'),
    (re.compile(r'text=\\"\d\d:\d\d\\"'), 'text=\\"<HH:MM>\\"'),          # Records-Eyepiece clock
    (re.compile(r'text=\\"(CEST|CET|LMT)\\"'), 'text=\\"<TZ>\\"'),           # its timezone (fixed: was the 1st historic one)
    (re.compile(r'(text=\\"<TZ>\\"/><label [^>]*text=\\")\d+'), r'\1<BEATS>'),   # clock's internet time
    (re.compile(r'"ChallengeMusics/[^"]*"'), '"ChallengeMusics/<song>"'),       # music shuffle is random
    (re.compile(r'\[\$f00INFO\$ff0\] .*?(?=\"\])'), '[INFO] <random tip>'),  # jfreu random tip (PHP 7.1 changed mt_rand)
]

def norm(s):
    for rx, rep in NORMALIZE:
        s = rx.sub(rep, s)
    return s

def load(path):
    steps = defaultdict(list)
    with open(path, encoding='utf-8', errors='replace') as f:
        for line in f:
            step, method, params = json.loads(line)
            steps[step].append(norm(method + ' ' + json.dumps(params, ensure_ascii=False, sort_keys=True)))
    return steps

if __name__ == '__main__':
    a, b = load(sys.argv[1]), load(sys.argv[2])
    full = '--full' in sys.argv
    ndiff = 0
    for step in sorted(set(a) | set(b)):
        if a[step] == b[step]:
            continue
        ndiff += 1
        print(f'=== step {step}')
        sm = difflib.SequenceMatcher(None, a[step], b[step], autojunk=False)
        for op, i1, i2, j1, j2 in sm.get_opcodes():
            if op == 'equal':
                continue
            if op == 'replace' and i2 - i1 == j2 - j1:
                for x, y in zip(a[step][i1:i2], b[step][j1:j2]):
                    # show only where the two lines differ
                    p = 0
                    while p < min(len(x), len(y)) and x[p] == y[p]: p += 1
                    q = 0
                    while q < min(len(x), len(y)) - p and x[-1-q] == y[-1-q]: q += 1
                    head = x[:x.find(' ')]
                    print(f'~ {head} @{p}: ...{x[max(0,p-80):len(x)-q][:300]}...')
                    print(f'~ {"":{len(head)}}    => ...{y[max(0,p-80):len(y)-q][:300]}...')
            else:
                for x in a[step][i1:i2]: print('- ' + (x if full else x[:300]))
                for y in b[step][j1:j2]: print('+ ' + (y if full else y[:300]))
    print(f'\n{ndiff} steps differ (of {len(set(a) | set(b))})', file=sys.stderr)
