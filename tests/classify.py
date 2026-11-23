import json, re, sys, difflib
from collections import Counter
sys.path.insert(0, __import__('os').path.dirname(__import__('os').path.abspath(__file__)))
from compare import norm
def load(p):
    c = Counter()
    for l in open(p, encoding='utf-8', errors='replace'):
        s, m, a = json.loads(l)
        c[norm(m + ' ' + json.dumps(a, ensure_ascii=False, sort_keys=True))] += 1
    return c
a, b = load(sys.argv[1]), load(sys.argv[2])
A = [k for k in a for _ in range(max(0, a[k] - b[k]))]
B = [k for k in b for _ in range(max(0, b[k] - a[k]))]
known = [('apostrophe &#039;', r"&#039;"), ('chatlog clock', r"\$ff0\d\d:\d\d:\d\d"), ('tied order: tracks', r"LoL \d+"),
         ('tied order: kicked player', r"Spec Three|TestAdmin|Test\$fffAdmin|spec3|testadmin\"|player2\""), ('tied order: nations', r"POR|FRA|GER"),
         ('random: music', r"Falden|Sabrepulse|Initial_D|Kavinsky|Waters|Carpenter|Pendulum|Nazareth|Witchcraft|Arcanine|song"),
         ('tied order: numbering/actions', r'text=\\"0\d\.\\"|action=\\"-?\d+\\"'),
         ('fix: dedimania stats', r'No Dedimania stats'), ('fix: TMX id message', r'valid Track/TMX ID')]
def frag(x, y):
    p = 0
    while p < min(len(x), len(y)) and x[p] == y[p]: p += 1
    q = 0
    while q < min(len(x), len(y)) - p and x[-1-q] == y[-1-q]: q += 1
    return x[max(0, p-40):len(x)-q+10], y[max(0, p-40):len(y)-q+10]
res = Counter(); unexplained = []
used = set()
for x in A:
    best, bi = 0, None
    for i, y in enumerate(B):
        if i in used or y.split(' ')[0] != x.split(' ')[0]: continue
        r = difflib.SequenceMatcher(None, x[:3000], y[:3000], autojunk=False).quick_ratio()
        if r > best: best, bi = r, i
    if bi is not None and best > 0.8:
        used.add(bi); fx, fy = frag(x, B[bi]); f = fx + ' ' + fy
    else:
        fx, fy, f = x[:200], '(no counterpart)', x
    for name, rx in known:
        if re.search(rx, f): res[name] += 1; break
    else: unexplained.append((fx[:250], fy[:250]))
for i, y in enumerate(B):
    if i in used: continue
    for name, rx in known:
        if re.search(rx, y): res[name] += 1; break
    else: unexplained.append(('(no counterpart)', y[:250]))
for k, v in res.most_common(): print(f'{v:4d}  {k}')
print(len(unexplained), 'unexplained')
for u in unexplained: print('  orig:', u[0]); print('  port:', u[1])
