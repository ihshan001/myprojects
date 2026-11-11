#!/usr/bin/env python3
"""Static audit: routes -> controller actions, views -> routes.
Reports broken links and half-built endpoints. Read-only."""
import re, os, sys, json

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
routes_src = open(os.path.join(ROOT, 'routes/web.php')).read()

# ---- 1. parse routes -------------------------------------------------
# Router::get('/path', [Controller::class, 'action'], [opts]);
route_re = re.compile(
    r"Router::(get|post|put|patch|delete)\s*\(\s*'([^']+)'\s*,\s*\[\s*(\w+)::class\s*,\s*'(\w+)'\s*\]")
routes = []
for m in route_re.finditer(routes_src):
    verb, path, ctrl, action = m.groups()
    routes.append(dict(verb=verb.upper(), path=path, ctrl=ctrl, action=action,
                       line=routes_src[:m.start()].count('\n')+1))

# any Router:: call the regex missed (different form) is worth knowing about
all_calls = len(re.findall(r"Router::(?:get|post|put|patch|delete)\s*\(", routes_src))

# ---- 2. every controller action must exist ---------------------------
missing_actions = []
for r in routes:
    f = os.path.join(ROOT, 'app/Http/Controllers', r['ctrl'] + '.php')
    if not os.path.exists(f):
        missing_actions.append((r, 'no controller file'))
        continue
    src = open(f).read()
    if not re.search(r"function\s+%s\s*\(" % re.escape(r['action']), src):
        missing_actions.append((r, 'method missing'))

# ---- 3. build the set of routable URL shapes -------------------------
def to_regex(path):
    # /patients/{id}/edit -> ^/patients/[^/]+/edit$
    parts = path.strip('/').split('/')
    out = []
    for p in parts:
        if p.startswith('{') and p.endswith('}'):
            out.append(r'[^/?#]+')
        else:
            out.append(re.escape(p))
    return '^/' + '/'.join(out) + '$'

path_res = [(to_regex(r['path']), r) for r in routes]

# ---- 4. views referenced by controllers must exist -------------------
view_refs = []   # (file, viewname)
for base, _, files in os.walk(os.path.join(ROOT, 'app')):
    for fn in files:
        if not fn.endswith('.php'):
            continue
        p = os.path.join(base, fn)
        src = open(p).read()
        for m in re.finditer(r"view\s*\(\s*'([^']+)'", src):
            view_refs.append((os.path.relpath(p, ROOT), m.group(1)))
        for m in re.finditer(r"render\s*\(\s*'([^']+)'", src):
            view_refs.append((os.path.relpath(p, ROOT), m.group(1)))

missing_views = []
for srcfile, v in view_refs:
    # view('pages/foo') -> resources/views/pages/foo.php
    for cand in (os.path.join(ROOT, 'resources/views/pages', v.replace('.', '/') + '.php'),
                 os.path.join(ROOT, 'resources/views', v.replace('.', '/') + '.php')):
        if os.path.exists(cand):
            break
    else:
        missing_views.append((srcfile, v))

# ---- 5. every internal href in every view must match a route ---------
views_dir = os.path.join(ROOT, 'resources/views')
href_re = re.compile(r'href="(/[^"{}]*)"')
action_re = re.compile(r'action="(/[^"{}]*)"')
bad_links = []
checked = 0
skipped = []
for base, _, files in os.walk(views_dir):
    for fn in files:
        if not fn.endswith('.php'):
            continue
        p = os.path.join(base, fn)
        rel = os.path.relpath(p, ROOT)
        src = open(p).read()
        for kind, rx in (('href', href_re), ('action', action_re)):
            for m in rx.finditer(src):
                url = m.group(1)
                if url.startswith('//'):
                    continue
                # strip PHP interpolation FIRST: the '?' in '<?=' is not a
                # query separator, and splitting on it truncates the path.
                norm = re.sub(r'<\?.*?\?>', 'ID', url, flags=re.S)
                if '{' in norm or '<?' in norm:
                    skipped.append((rel, url))
                    continue
                path = norm.split('?')[0].split('#')[0].rstrip('/') or '/'
                checked += 1
                if not any(re.match(pr, path) for pr, _ in path_res):
                    bad_links.append((rel, kind, url,
                                      src[:m.start()].count('\n')+1))

print(json.dumps(dict(
    routes_parsed=len(routes),
    router_calls=all_calls,
    missing_actions=[(r['verb'], r['path'], r['ctrl']+'::'+r['action'], why,
                      'routes/web.php:%d' % r['line']) for r, why in missing_actions],
    view_refs=len(view_refs),
    missing_views=missing_views,
    links_checked=checked,
    bad_links=['%s:%d %s -> %s' % (f, ln, k, u) for f, k, u, ln in bad_links],
    skipped_dynamic=len(skipped),
), indent=2))
