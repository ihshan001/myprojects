# GitDock — Internal Edition (you + your team)

> **Scope of this document:** a *private* tool — no signups, no billing, no strangers, no marketing.
> Revised 13 Sep 2026. Supersedes the product-version plan, which is archived at
> `archive/blueprint-v1-product-version.md` (still useful: it holds the deep technical detail on
> Laravel/PHP traps, the manifest spec, the data model and the gap analysis — all of which still apply).
> Sections marked **[unchanged]** are deliberately not repeated here; read them in the archive.
>
> **Revision note (after your answers):** you chose *public URL + GitHub OAuth allowlist*, *host = InfinityFree*,
> *mixed devs + non-devs*, *clients occasionally*. The hosting answer breaks the plan as written — InfinityFree
> cannot run containers, processes, ports or databases, and its TOS forbids storing project archives and backups.
> See **`hosting-and-access.md`** for the full reality check, the hosting options, the hardening list and the
> revised v0. §2 and §3 below are superseded by that document.

---

## 0. What your clarification changes

| | Public product (old plan) | **Internal team tool (now)** |
|---|---|---|
| Users | strangers, signup, email verification | you + 3–10 trusted people, invited |
| Auth | full account system, OAuth app review | GitHub OAuth **allowlist** or just SSO/VPN — ~30 lines |
| Isolation purpose | **defence against hostile tenants** | **containment** — one bad project can't take the box down |
| Isolation cost | microVM mandatory, adversarial hardening | Docker + cgroup limits is fine for v1; gVisor optional |
| Economics | unit costs, marginal cost per user, billing | fixed cost of one box; question is only "big enough?" |
| Legal/abuse | ToS, DMCA, takedown runbook, phishing reports | nearly all gone — just keep it off the public internet |
| Biggest risk | abuse, cost, tenant escape | **concurrency** (two people clobbering each other) and **it becoming team infrastructure** |
| Time to first useful version | months | **days** |

**Net effect:** you can delete about 40% of the plan, drop ~3 months of work, and get a tool that's genuinely yours in under a week. The thing you must *add* is everything that only matters when more than one person touches the same project.

---

## 1. What gets deleted

- ❌ Signup, email verification, password reset, account management
- ❌ Billing, plans, quotas-as-a-business-model, metering for invoicing
- ❌ Public abuse tooling: DMCA agent, abuse inbox, phishing takedown runbook, mining detection at scale
- ❌ Public subdomain strategy (you don't need `*.preview.example.dev` on the open internet — see §3)
- ❌ Terms of Service, privacy policy theatre (still be sensible with teammates' data, but no legal apparatus)
- ❌ Marketing pages, onboarding funnels, template gallery for strangers
- ❌ Multi-node/horizontal scaling, tenant-affinity, node placement — **one box is enough for a team of 10**

Keep from the old plan: the manifest contract, subdomain-per-port *(internally)*, bulk git via real `git`, Docker/DB isolation between projects, all the Laravel/PHP gotchas, `upload ≠ deploy` staging, versions/rollback with DB snapshots, auto-sleep, backups.

---

## 2. Auth & access — the single biggest simplification

> **Superseded by `hosting-and-access.md` §4.** You chose a public URL with a GitHub OAuth allowlist; that document
> holds the hardening checklist (org/team membership check, not just "logged in", session rules, CSRF, rate limits,
> 2FA, forward-auth-gated previews, signed client share links, panic switch). The options below are kept for context.

Do **not** build an auth system. Three good options, best first:

1. **Tailscale (or WireGuard) + no public exposure at all.** The whole panel and all preview URLs live inside your tailnet. Teammates install the Tailscale client once. Identity comes free (device + user), you can restrict by ACL. Preview URLs become `http://gitdock:3001` style internal names, or `https://8000-acme.tailnet-name.ts.net` via Tailscale Serve/Funnel (Funnel = temporarily public when you need to show a client). **This is my recommendation** — it deletes an entire class of risk (bots, port scanners, credential attacks) and takes an afternoon.
2. **Cloudflare Access / Zero Trust in front of everything.** You get a login wall you didn't write, per-email policies, and audit logs at the edge. Slightly more moving parts than Tailscale, nicer for people who won't install a VPN client.
3. **GitHub OAuth + login allowlist** (if you insist on a plain public URL). Restrict to your org/members, store nothing but a session cookie. Never build passwords.

**Attribution without personal PATs:** install **one GitHub App** on your org with `contents: write` + `pull_requests: write`, and attribute commits to the actual person (`author`/`committer` = their GitHub identity, taken from the app's repo permission check). Your audit log records the real actor even though one app token did the pushing. This is the clean answer — personal PATs in a shared tool are a leak waiting to happen, and you can't tell who did what.

**Rule:** the panel is never reachable from the open internet without one of the above. If you must expose a preview publicly for a client demo, make it a temporary, token-gated link with an expiry — not a permanent public subdomain.

---

## 3. Hosting & sizing (fixed cost, no unit economics)

| Option | Cost | Good for | Watch out for |
|---|---|---|---|
| **Mini PC at the office** (32 GB RAM, 1 TB NVMe) | ~$400–700 one-off | Solo + small team; best perf/₨; great for DB-heavy Laravel | Uptime, power, ISP failure, no easy scaling. Backups must go offsite. Tailscale required for remote access |
| **Single VPS** (8 vCPU / 16 GB / 320 GB) | ~$70–120/mo | The sane default. Offsite, static IP, snapshots | Disk IOPS on cheap VPSs hurts MySQL-heavy imports |
| **Cloud VM with snapshots** (Hetzner/DO/AWS Lightsail) | ~$50–100/mo | Same as above with easier restore | Egress fees |
| **GitHub Codespaces for the team** | free tier ~120 core-hours/user/mo, then $0.18/core-hour | Zero infra work, browser VS Code, port forwarding already solved | Per-user hours, storage always billed, no custom UI, no bulk-drop UX, no shared project DBs [1](https://makerstack.co/reviews/github-codespaces-review/) |

> ⚠️ **InfinityFree is not an option** — no SSH, no cron, no Node, no containers, no ports, 10-second PHP
> execution limit, 50 MB per MySQL database, TOS forbids storing archives/backups, and a browser-only security
> challenge blocks all API and webhook traffic. Full breakdown in `hosting-and-access.md` §1.

**Honest check before you build:** for a small team doing mostly web work, Codespaces org already gives browser VS Code + terminal + forwarded ports, and costs nothing to try for a week. What it does *not* give you is your actual pain points: drag-drop whole folders, non-git teammates, CMS/Laravel one-click with DB import, one project → many ports served without a terminal. If after a week's trial those still grind, you've validated the build. It's a cheap way to buy certainty.

**Capacity guide (with idle-sleep on, ~15 min):**

| Box | Sleeping projects kept | Comfortably awake at once |
|---|---|---|
| 4 vCPU / 8 GB | 20–30 | 5–8 (PHP/static), 2–3 Laravel-with-queue |
| 8 vCPU / 16 GB | 50+ | 12–20 |
| 8 vCPU / 32 GB | 100+ | 25–40, or 6–10 dedicated-MySQL projects |

Rough per-project awake footprint: static ~0 (serve from proxy), PHP 50–150 MB, Laravel 250–500 MB, MySQL shared instance 400 MB–1.5 GB total, per-project MySQL container 300–500 MB each. Builds: 1–2 vCPU and RAM spikes >1 GB for 1–3 min — **cap concurrent builds at 2–3**, queue the rest.

---

## 4. The new risk area: teammates, not strangers

### 4.1 Concurrency — the #1 thing you'll hit in week two
Two people pushing, two tabs editing, a webhook arriving mid-upload. Fixes, in priority order:

- **Per-project deploy lock (mutex).** One deploy at a time; everyone else sees "Kavindu is deploying… (2 min)" and can queue behind it. Without this, two simultaneous deploys produce a half-old/half-new sandbox that's maddening to debug.
- **Optimistic locking on every file write.** ETag/commit-SHA compare-and-swap (`If-Match`). Second writer gets "this file changed — reload / compare / overwrite", never a silent clobber.
- **Fetch-before-push.** Always `git fetch` + check the remote hasn't moved; if it has, force a pull/merge step instead of a rejected push with a scary error.
- **Presence, later.** "Nimal is editing `routes/web.php`" — cheap to add, kills the most common surprise.

### 4.2 Environments per team (dev → staging → live)
A team needs tiers even if it's only internal. Model each project with up to three **environments**, each with its **own env vars, own database, own URL, own release pointer**:

```
dev-<member>  → my own copy of the DB, my own URL         (safe to break)
staging       → shared, tracks `main`, mirrors live config  (where you review)
live          → only promoted releases, prod secrets, no auto-deploy
```
Branch mapping: any branch → `dev` preview; `main` → staging; a **tag/promotion** → live. Promotion is one button ("Promote build #47 to live") and it's the only action that touches production secrets. This single feature prevents the classic disaster: someone testing a migration against the live database.

### 4.3 Roles (keep it to three, no more)
- **Admin** (you): everything, including delete project, edit prod secrets, restore backups, manage access.
- **Member**: push, deploy to dev/staging, run DB imports, open PRs. **Cannot** delete projects or touch live.
- **Guest/read-only** (optional, for a designer or client): view previews and logs only.

Put it in the manifest (`§7 team:` block) and enforce server-side. Also gate the destructive verbs behind a typed confirmation ("type the project name") — for a team tool that takes 10 lines and saves an evening.

### 4.4 Audit log — "who dropped the table?"
Every action that touches state gets a row: who, what, which project, which environment, before/after, IP, timestamp. Especially: DB imports, restores, deletes, secret changes, live promotions, force-push attempts. Show it as a project activity feed. This is the feature you'll thank yourself for the first time something breaks and nobody admits it.

### 4.5 Notifications (make the tool ambient)
- Slack/Discord/Teams webhook per project.
- Events: deploy started/succeeded/failed, preview URL ready, PR opened/merged, DB import finished, **sandbox crashed**, disk/CPU high.
- Also: post the preview URL to the PR as a comment (via the GitHub App) — that's the workflow everyone ends up wanting.

### 4.6 Fairness instead of abuse control
Still enforce per-project **and per-user** caps even though everyone's trusted: CPU/RAM/PID/disk limits, max 2–3 concurrent sandboxes per person, max 3 concurrent builds, per-project disk quota (a stray 20 GB `node_modules` is not hypothetical), and a global kill switch. Not for security — for the Tuesday afternoon when one person's build eats the box and three people are blocked.

### 4.7 It's now team infrastructure — behave accordingly
The moment a teammate relies on it, you're on the hook:
- **Backups, tested.** Nightly DB dumps + project volumes to offsite object storage (S3/R2/B2); weekly rehearsed restore. An untested backup is a rumour.
- **Monitoring + alerting.** Uptime check on the panel, disk-space alert at 80%, a "sandbox crash-looping" alert, a "MySQL is down" alert. Uptime Kuma is 10 minutes of work.
- **Documented runbook** (a `RUNBOOK.md` in your repo): how to restart the stack, restore a DB, rotate the GitHub App key, add a teammate, free disk space, take the box down for updates.
- **Pinned versions + update windows.** Don't let `latest` tags break the stack on a random Thursday. Update deliberately, and keep the ability to roll your own panel back.
- **One-command bring-up** (`docker compose up -d` / a `make bootstrap`) so you can rebuild the box from scratch on a bad day. Snapshot the host weekly.

---

## 5. Security — revised threat model

**Old model:** hostile strangers trying to escape a sandbox.
**New model:** *trusted people, untrusted software.* You trust your teammates; you don't trust a random WordPress plugin, an abandoned Composer package, or a `curl | bash` in someone's Dockerfile.

That means you can relax **isolation between tenants** (no microVM needed on day one) but you must keep **containment and hygiene**:

**Keep (mandatory even internally):**
- Containers with `--cap-drop ALL`, `--no-new-privileges`, non-root user, read-only root + writable project volume, seccomp/AppArmor defaults.
- cgroup v2: CPU quota, `memory.max` (+ `memory.swap.max=0`), `pids.max`, disk quota.
- **Never mount `/var/run/docker.sock` into a project container** (that's a host takeover by any PHP shell), never `--privileged`, never host network.
- **Block outbound port 25/SMTP** and block cloud metadata `169.254.169.254`. An unpatched CMS on your box *will* be found by scanners if it's reachable — one more reason Tailscale-first.
- **Run builds inside the sandbox**, never on the control plane — `composer install` / `npm install` execute untrusted post-install scripts. This is the single most common way internal tools get owned.
- Secrets: encrypted at rest (libsodium/age, key outside the DB), redacted in logs and diffs, `.env` never committed, prod secrets reveal-only-for-admins.
- Uploaded `.git/`: default **discard** with an opt-in "keep history" checkbox, strip remotes and credentials, never execute hooks. Reject symlinks pointing outside the project.
- PHP version pinning per project, and document that `phpinfo()` on a preview leaks absolute paths — normal for internal use, not for anything client-facing.

**Relax (fine for internal):**
- Plain Docker + limits instead of gVisor/Firecracker. (If a teammate's project will run genuinely sketchy code, add gVisor as an opt-in runtime flag — it's a one-line `--runtime=runsc`.)
- Shared MySQL instance with **database + user per project** instead of per-project containers, until someone actually needs isolation.
- Simpler secret distribution: still encrypt at rest, but you can use a single box-level key in your password manager rather than KMS.

---

## 6. Manifest additions for team use

Everything from `gitdock.example.yaml` still applies. Add a `team:` block:

```yaml
team:
  owner: you@yourteam.dev
  roles:                        # enforced server-side
    admin:  [you-github-login]
    member: [teammate1, teammate2]
    viewer: [designer]
  environments:
    dev:     { ttlHours: 168, autoDeployBranches: ["*"] }
    staging: { ttlHours: 0,   autoDeployBranches: [main] }
    live:    { ttlHours: 0,   deploy: manual, secrets: restricted }
  locks:
    deploy: single              # serialize deploys per project
    live:   manual-approval     # someone must approve a live promotion
  notify:
    slack: "https://hooks.slack.com/services/..."
    on: [deploy, preview-ready, pr-opened, db-import, crash]
  limits:
    disk: 5Gi
    maxSandboxesPerUser: 3
    maxConcurrentBuilds: 2
```

---

## 7. Revised roadmap (much shorter)

**v0 — "Useful this week" (3–5 days)**
Tailscale-only access, GitHub OAuth allowlist for your org. Static + PHP + Laravel + SQLite. Folder/ZIP drop → single atomic commit → run → internal preview URL per service. `.env` paste + `.env.example` missing-key checklist. One shared MySQL with DB-per-project. Logs visible (no terminal needed). Deploy button with a build log. That's already better than what you have today.

**v1 — "Team-ready" (2–3 weeks)**
Git push/pull/branches/commit-diff/revert. Bulk delete/move. Manifest + auto-detection chips. Multi-service ports. `.sql`/`.sqlite` import + export + Adminer. Releases + deploy history with **code+DB rollback**. **Environments (dev/staging/live) with promotion.** Roles + audit log + activity feed. Deploy lock. Slack notifications. Per-user and per-project limits. Nightly backups.

**v2 — "Comfortable" (1–2 months)**
PR list/detail/merge + **auto preview URL per PR, posted as a PR comment, auto-torn-down**. Templates (your Laravel starter, WordPress, static) for new projects. Web terminal inside the sandbox. Mobile polish + QR codes for testing on phones. Staging→live blue/green with auto-rollback on failed health check. Postgres runtime. Restore drill documented and done once.

**Explicitly never:** signup, billing, marketing site, multi-node scheduling, public template gallery, path-based routing, hand-rolled isolation.

---

## 8. Team conventions to write down now (one page in the repo)

Small things that prevent 80% of team friction:
- Branch naming (`feature/…`, `fix/…`) and who merges to `main`.
- `main` = staging by default; nothing reaches `live` without a promotion + approval.
- Which files are never uploaded (`vendor/`, `node_modules/`, `.env`, `*.sqlite`, dumps > 25 MB, media).
- DB naming (`proj_env_<member>`), and "never import a dump into live without a snapshot first".
- Secret rotation cadence and who can see prod values (admins only).
- What "done" means: runs in dev → deployed to staging → PR reviewed → promoted to live.
- Where the runbook lives and who's on call for the box (i.e. you).

---

## 9. Decisions I need from you

1. **Team size & shape** — how many people, are they developers or mostly not? (Non-devs change the UI priorities a lot: more templates, more plain-English errors, fewer git concepts.)
2. **Where it runs** — office mini PC, a VPS, or a cloud VM? And is remote access needed from outside your network?
3. **Access method** — Tailscale/Cloudflare Access (recommended, costs you an afternoon, removes a whole risk class) or a public URL with GitHub OAuth + allowlist?
4. **Do you need to show previews to *clients*?** If yes, I'll spec a token-gated, expiring share-link flow on top of the internal-only design.

Answer those and I'll tighten §2/§3/§6 to one concrete path and start scaffolding: `docker-compose` stack + `gitdock.yaml` parser + the folder-drop → commit → run pipeline is a realistic first build.
