"""One-off: remove CSS rules whose every selector targets a dead .nav-* class (see docs/ui-components.md).
Usage: python tests/UiLinks/prune_nav_css.py public/assets/style.css [--write]
Reports rules removed and rules left (mixed selectors). Comments are kept; @media blocks left empty are dropped."""
import re, sys

DEAD = re.compile(r'\.nav-(brand|links|btn|icon|user|burger|overlay|logout-form|account|account-avatar|account-label)\b')
path = sys.argv[1]
write = '--write' in sys.argv
src = open(path, encoding='utf-8').read()

def split_top(s):
    """Yield (kind, text) chunks: 'comment', 'rule' (selector{body}), 'at' (@media {...} with children parsed lazily)."""
    i, n, out = 0, len(s), []
    while i < n:
        if s.startswith('/*', i):
            j = s.index('*/', i) + 2
            out.append(('comment', s[i:j])); i = j; continue
        if s[i].isspace():
            j = i
            while j < n and s[j].isspace(): j += 1
            out.append(('ws', s[i:j])); i = j; continue
        j = s.index('{', i)
        depth, k = 1, j + 1
        while depth:
            if s.startswith('/*', k):
                k = s.index('*/', k) + 2; continue
            c = s[k]
            if c == '{': depth += 1
            elif c == '}': depth -= 1
            k += 1
        head = s[i:j].strip()
        out.append(('at' if head.startswith('@') else 'rule', s[i:k], head, s[j + 1:k - 1]))
        i = k
    return out

removed, left = [], []

def process(chunks, ctx):
    res = []
    for ch in chunks:
        if ch[0] == 'rule':
            sels = [x.strip() for x in ch[2].split(',')]
            dead = [bool(DEAD.search(x)) for x in sels]
            if all(dead):
                removed.append((ctx, ch[2].replace('\n', ' ')[:70])); continue
            if any(dead):
                left.append((ctx, ch[2].replace('\n', ' ')[:90]))
            res.append(ch[1])
        elif ch[0] == 'at':
            inner = process(split_top(ch[3]), ch[2])
            body = ''.join(inner)
            if not [c for c in split_top(body) if c[0] in ('rule', 'at')] and ch[2].startswith('@media'):
                continue
            res.append(ch[2] + ' {' + body + '}')
        else:
            res.append(ch[1])
    return res

out = ''.join(process(split_top(src), ''))
out = re.sub(r'\n{4,}', '\n\n\n', out)
print('rules removed:', len(removed))
for r in removed: print('  -', r[0][:30], '|', r[1])
print('mixed selector lists left alone:', len(left))
for r in left: print('  ?', r[0][:30], '|', r[1])
if write:
    open(path, 'w', encoding='utf-8', newline='').write(out)
