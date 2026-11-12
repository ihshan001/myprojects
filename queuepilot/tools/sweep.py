#!/usr/bin/env python3
"""Live sweep: GET every GET route as each role. Flags 500s and PHP errors
leaking into output. Read-only except for login."""
import re, sys, http.cookiejar, urllib.request, urllib.parse, json, collections

BASE = 'http://127.0.0.1:8080'
routes_src = open('routes/web.php').read()
rr = re.compile(r"Router::(get|post|put|patch|delete)\s*\(\s*'([^']+)'\s*,\s*\[\s*(\w+)::class\s*,\s*'(\w+)'\s*\]")

SUB = {
    'id': '1', 'entry': '174', 'entryId': '174', 'patient': '4', 'doctor': '6',
    'queue': '1', 'key': '8bESlrE6cI0XU8eXzvQLliC063sYKBnJ',
    'token': 'lT5GBG1I4GYkmhXhUsQ42tcDyLQvVRRV',
    'appointment': '21', 'invite': '19', 'rule': '1', 'step': '1',
    'policy': '1', 'holiday': '1', 'leave': '1', 'schedule': '1',
    'notification': '1', 'invoice': '3', 'org': '1', 'plan': '3',
    'room': '1', 'department': '1', 'ticket': '1', 'incident': '1',
    'alert': '1', 'user': '1', 'clinic': '1', 'subscription': '1',
    'payment': '1', 'break': '1', 'block': '1', 'owner': '1',
}

ROLES = [
    ('super_admin',  'owner@queuepilot.app',  'QueuePilot#2026'),
    ('clinic_admin', 'admin@serenity.lk',     'Serenity#2026'),
    ('doctor',       'amara@serenity.lk',     'Doctor#2026'),
    ('receptionist', 'reception@serenity.lk', 'Reception#2026'),
]

ERR = re.compile(rb'(Fatal error|Uncaught |Parse error|Warning:|Notice:|Deprecated:|'
                 rb'SQLSTATE\[|Stack trace:|<b>Warning</b>|Undefined array key|'
                 rb'Call to undefined|Trying to access array offset)')


def build(path):
    def rep(m):
        return SUB.get(m.group(1), '1')
    return re.sub(r'\{([^}]+)\}', rep, path)


def get_routes():
    out = []
    for m in rr.finditer(routes_src):
        verb, path, ctrl, action = m.groups()
        if verb == 'get':
            out.append((path, ctrl, action))
    return out


def login(email, pw):
    cj = http.cookiejar.CookieJar()
    op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(cj))
    op.open(BASE + '/login', timeout=30).read()
    data = urllib.parse.urlencode({'email': email, 'password': pw}).encode()
    r = op.open(urllib.request.Request(BASE + '/login', data=data), timeout=30)
    body = r.read()
    if b'Dashboard' not in body and b'/app' not in body and r.status == 200:
        if b'Invalid' in body or b'incorrect' in body.lower():
            return None, 'login rejected'
    return op, None


def main():
    routes = get_routes()
    print(f"GET routes: {len(routes)}   roles: {len(ROLES)}")
    codes = collections.Counter()
    problems = []

    for role, email, pw in ROLES:
        op, err = login(email, pw)
        if err:
            print(f"  !! {role}: {err}")
            continue
        for path, ctrl, action in routes:
            url = BASE + build(path)
            try:
                r = op.open(url, timeout=45)
                body = r.read()
                code = r.status
            except urllib.error.HTTPError as e:
                body = e.read()
                code = e.code
            except Exception as e:
                problems.append((role, path, 'EXC', str(e)[:120]))
                codes['EXC'] += 1
                continue
            codes[code] += 1
            m = ERR.search(body)
            if code >= 500 or m:
                snippet = body[max(0, m.start()-60):m.start()+200].decode('utf8', 'replace') if m else ''
                problems.append((role, path, code, snippet.replace('\n', ' ')[:240]))

    print("\n── status codes ──")
    for c, n in sorted(codes.items(), key=lambda x: str(x[0])):
        print(f"  {c}: {n}")
    print(f"\n── problems: {len(problems)} ──")
    for role, path, code, snip in problems:
        print(f"  [{role}] {path} -> {code}")
        if snip:
            print(f"        {snip}")


main()
