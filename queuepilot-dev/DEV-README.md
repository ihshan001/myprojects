# queuepilot-dev — development sandbox

**This is not the main system.** It is a working copy of `../queuepilot` where
changes are made and exercised across every role before being promoted.

The previous incarnation of this folder was untracked in Git and was lost on
the next clone. This one is **committed**, so the work survives.

---

## Quick start

```bash
cd queuepilot-dev
npm install                 # or: ln -s ../queuepilot/node_modules node_modules
npm run migrate && npm run seed
npm run fleet               # all five role ports
```

Then open the ports. **No login, no password** — each port is already signed in
as its assigned person.

| Port | Who | Lands on | What it is for |
|------|-----|----------|----------------|
| 8080 | Clinic owner — `admin@serenity.lk` | `/app` | Full clinic control: settings, staff, billing, audit |
| 8081 | Receptionist — `reception@serenity.lk` | `/app` | Front desk: issue, call, complete. Cannot set priority |
| 8082 | Doctor — `amara@serenity.lk` | `/app` | Own queue only, start session, priority with a reason |
| 8083 | Platform admin — `owner@queuepilot.app` | `/admin` | Tenants, plans, feature gates, payment verification |
| 8084 | Second clinic — `admin@lakeview.lk` | `/app` | Tenant isolation: must never see Serenity's patients |

Every page carries an orange **DEV** bar at the bottom naming the port and the
signed-in identity, so a screenshot always says where it came from.

One port at a time:

```bash
npm run p8081               # just the receptionist
npm run fleet 8080 8082     # a subset
```

Change who a port becomes by editing `dev/roles.json` and restarting that port.

---

## Why five ports share one database

They all read and write `storage/db/queuepilot.sqlite`. Issue a token as the
receptionist on 8081 and it appears on the doctor's board on 8082 — that is the
point of the fleet. SQLite is opened in WAL mode with a 5-second busy timeout
(`app/Core/Db.php`), which is what makes concurrent access from five processes
safe rather than a race.

The sandbox database is **separate from the main system's**. Seeding or wiping
this one cannot touch `../queuepilot`.

```bash
npm run fresh               # drop the sandbox DB, re-migrate, re-seed
```

---

## How the no-login harness works — and why it cannot leak

The app is **not modified** to bypass authentication. `public/index.php`,
`routes/web.php` and everything in `app/` are byte-identical to the main system.

Instead, `tools/dev-serve.mjs`:

1. reads `public/index.php`,
2. replaces the single `Session::start();` call with a require of
   `dev/auto-signin.php`,
3. writes the result to `storage/dev-front-<port>.php` and executes that.

`dev/auto-signin.php` sets a **per-port session name and save path**, then signs
in the user named in `dev/roles.json`. The per-port cookie is essential: cookies
are scoped by host, not by port, so without it all five ports would share one
`qp_session` cookie and continuously sign each other out.

The consequence is the safety property: **no application code references the
bypass.** Under nginx, php-fpm, Docker, or the plain `npm run dev` server,
`dev/auto-signin.php` is never loaded. It only exists inside a request that
`dev-serve.mjs` constructed.

> The main system still has its own separate `/dev/role` route, inherited from
> `queuepilot`. That is a pre-existing issue in the reference app, not something
> this sandbox added. It should be gated or removed before any real deployment.

---

## Promoting to the main system

Promotion is **dry-run by default**:

```bash
npm run promote             # show exactly what would change
npm run promote:apply       # copy it
```

Copied: `app/`, `config/`, `database/`, `resources/`, `routes/`, `public/`,
`docs/`, `tests/`, standard `tools/`, and the usual project files.

**Never copied** — this is the safety boundary:

| Excluded | Why |
|----------|-----|
| `dev/` | the auto-sign-in harness and role map |
| `tools/dev-serve.mjs` | the server that injects it |
| `tools/dev-fleet.mjs` | the fleet runner |
| `tools/promote.mjs` | the promotion tool itself |
| `storage/` | databases, logs, sessions, generated front controllers |
| `node_modules/`, `.env` | dependencies and local secrets |
| `DEV-README.md` | this file |

Files that exist only in the main system are **reported, never deleted**.
Removing something is a decision you make by hand.

After promoting, always verify the main system:

```bash
cd ../queuepilot
npm run lint && npm test && npm run health
```

---

## Everyday commands

```bash
npm run lint                # PHP parse check across every file
npm test                    # 246-check suite, throw-away SQLite DB
npm run health              # deploy sanity: schema, plans, CSS, schema parity
npm run css                 # rebuild Tailwind after editing views
npm run css:watch           # rebuild on change
npm run worker              # drain the notification outbox once
npm run security            # dependency + posture check
```

**Rebuild CSS after changing views.** Tailwind scans `resources/views/**/*.php`
for class names; a new utility class will not exist in the stylesheet until you
run `npm run css`. This is the most common "my change didn't work" in this repo.

---

## Layout of the sandbox-only files

```
dev/
  roles.json              port -> identity map (edit this)
  auto-signin.php         per-port session + sign-in, injected in memory
tools/
  dev-serve.mjs           one port, one role
  dev-fleet.mjs           all ports at once
  promote.mjs             dev -> main, dry-run by default
storage/
  dev-front-<port>.php    generated per port, gitignored, not web-reachable
  sessions/dev-<port>/    per-port session files, gitignored
DEV-README.md             this file
```

Everything else is the application, and is promotable.
