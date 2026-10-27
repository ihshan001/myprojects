# Hosting & access — reality check after your answers

> Companion to `blueprint.md`. Your answers: **public URL + GitHub OAuth allowlist** · **host: InfinityFree** ·
> **team: mixed devs + non-devs** · **clients: occasionally**.
> Three of those four are easy to design around. The hosting one isn't, so it comes first.

---

## 0. Your answers → what each one changes

| Your answer | Consequence |
|---|---|
| Public URL + GitHub OAuth allowlist | Panel needs real hardening (sessions, CSRF, rate limits, 2FA, audit). Your domain becomes the abuse surface for public previews. §4 |
| **Host: InfinityFree** | 🛑 **InfinityFree cannot run this system.** Not "hard" — structurally impossible. §1 |
| Mixed devs + non-devs | UI must hide git behind plain-English verbs, with templates and guardrails. §5 |
| Clients occasionally | Add signed, expiring share links + a read-only "client view" to v1. §5 |

---

## 1. InfinityFree: the honest verdict

InfinityFree is **shared PHP website hosting**. GitDock is an **orchestrator that runs other people's code in containers**. Those are different categories of machine, and no amount of cleverness bridges them.

### What GitDock needs vs what InfinityFree gives

| GitDock needs | InfinityFree free tier |
|---|---|
| Long-running processes (php-fpm, node, queue workers, cron) | ❌ No SSH, **no cron jobs**, no Node.js/Python, no daemons [1](https://blog.webhostmost.com/infinityfree-review-2026/) |
| Docker / containers / cgroups to sandbox projects | ❌ Not available, ever, on shared hosting |
| Arbitrary ports + per-port subdomain routing | ❌ One website, one docroot, no port binding |
| Big uploads, ZIP unpack, DB imports, builds | ❌ **10-second max PHP execution time** [3](https://www.deployhq.com/blog/how-to-deploy-a-php-website-for-free) |
| Real databases for real projects | ❌ **50 MB per MySQL database** cap; 30,000-inode file cap [1](https://blog.webhostmost.com/infinityfree-review-2026/) |
| Store project files, `.sql` dumps, backups, archives | 🛑 **Forbidden by TOS.** "Sites should not contain any backups, downloads, or other non-web based content… all password protected archive (zip/rar) files [are] unacceptable" [4](https://www.infinityfree.com/terms/) |
| APIs, webhook receivers, worker agents, automated Git push | 🛑 Non-browser clients are **blocked by a JS security challenge**: "the hosting doesn't allow APIs and blocks everything that is not a browser" — and it cannot be disabled on free plans [3](https://forum.infinityfree.com/t/server-protection/116799) |
| Traffic spikes from previews | ⚠️ EP (entry process) limits **auto-suspend** accounts on short bursts [4](https://cpanelfree.com/infinityfree-alternative) |

### The four hard blockers

1. **No runtime.** Without SSH, cron, containers or ports, there is no way to *run* a project there — not Laravel, not WordPress with plugins, not Node, not a static site with a build step. The core promise of your tool ("it instantly opens up the website in a port") is unreachable.
2. **10-second PHP limit + 50 MB DB cap.** Even a pure-PHP control panel breaks: a folder upload, a `.sql` import, or a push would time out; a single moderate WordPress database won't fit.
3. **TOS risk.** GitDock's whole job is storing projects, dumps and backups on the host. InfinityFree's Terms explicitly treat that as unacceptable content and reserve the right to terminate and **destroy the data**. [4](https://www.infinityfree.com/terms/)
4. **Browser-only access.** A GitHub webhook POST, a worker agent polling for jobs, or CI-style automation all look like "not a browser" and get challenged. That kills the async architecture the whole design depends on. [3](https://forum.infinityfree.com/t/server-protection/116799)

**Conclusion: don't build on it. Not even for the panel.** If you already have data there, keep it for a brochure/landing page for GitDock — that's a legitimate use.

### The one thing you *could* legitimately ship on free PHP hosting

If free hosting is a hard constraint, there is a TOS-clean subset that solves **only pain #1** (push/pull/upload without a terminal):

> **A browser-side GitHub bulk file manager.** The browser does all the work: unzip locally with JSZip, then commit hundreds of files in one shot via GitHub's **Git Data API** (blobs → tree → commit → ref update) using the user's own token. Shared PHP hosting then stores nothing but the panel's own code and a bit of metadata — no archives on disk, no long PHP executions, no processes needed.

That is genuinely useful, honest, and buildable in days. But it is **not** GitDock: no sandbox, no ports, no databases, no Laravel, no previews. Decide which of the two you actually want — that's the fork in the road.

---

## 2. Where GitDock should actually live

| Option | Monthly | Verdict |
|---|---|---|
| **Oracle Cloud Always Free** (Ampere ARM: up to 4 OCPU / 24 GB RAM / 200 GB block volume) | **$0** | Best free option by far — full root, Docker, ports, tunnels [3](https://www.deployhq.com/blog/how-to-deploy-a-php-website-for-free). Caveats: card verification, ARM capacity can be unavailable in your region, and Oracle may reclaim long-idle always-free compute (verify current policy) |
| **Hetzner CAX11 / CX22** (2 vCPU / 4 GB / 40 GB, x86 or ARM) | ~€4–5 | The sane paid default. Predictable, fast NVMe, snapshots. Handles ~5–8 awake PHP projects |
| **Google Cloud e2-micro free tier** (1 GB RAM, 30 GB) | $0 | Too tight for Laravel + MySQL + several projects; okay for a solo static/PHP tool |
| **Mini PC at home + Cloudflare Tunnel** | one-off ~$400 + $0/mo | Best performance per rupee, free tunnel avoids port-forwarding, but it's your home ISP and power on the hook |
| **InfinityFree / free shared PHP hosts** | $0 | ❌ Structurally can't run it (§1) |

**My recommendation:** Oracle Cloud Always Free if the budget must be zero; Hetzner CAX11 if ~$5/month is acceptable (it will save you more than $5 of your own time per month). Both run the identical stack, so you can switch later without redesign.

---

## 3. Two viable deployment shapes

### Shape A — one box, public URL (recommended, matches your answer)

```
Internet ──► Cloudflare (proxy, WAF, rate limit, DDoS)
              ├── gitdock.yourdomain.com   → Caddy → control panel (OAuth-gated)
              ├── 8000-acme.yourdomain.com → Caddy → project service :8000
              └── 3001-acme.yourdomain.com → Caddy → project service :3001
             [ single box: panel + Docker sandboxes + MySQL + volumes ]
```

Everything on one machine: panel, Docker, MySQL, backups. No worker agent, no queue across the network, no NAT problems. If the box is at home, put **Cloudflare Tunnel** on it instead of opening ports — you get a public HTTPS URL with zero firewall work.

### Shape B — panel on a host, worker on another (only if you later must split)

Panel holds state; the worker **polls outbound** for jobs (works behind NAT, no inbound ports needed). Worth knowing, not worth building yet — and **not possible on InfinityFree**, because their browser-only challenge blocks the API calls this shape depends on. [3](https://forum.infinityfree.com/t/server-protection/116799)

---

## 4. Hardening the panel, now that it's public

Since you chose a public URL (rather than a private tailnet), these stop being optional:

**Access**
- GitHub OAuth, then **check org/team membership** — "logged in with GitHub" is not authorization. Allowlist by org *and* team.
- Require **2FA on the GitHub accounts** of admins (GitHub's org policy can enforce it for you).
- Optional: IP/geo allowlist for admin actions; instant session revoke; log every login with IP + user agent.

**Sessions & requests**
- `Secure` + `HttpOnly` + `SameSite=Lax` cookies, session rotation on login, short idle timeout.
- CSRF tokens on every mutating request; CORS locked to your own origins.
- Rate limiting on login, uploads, and every API route; lockout after N failures.
- Never expose a token to the browser: keep GitHub App credentials and `.env` values server-side only.

**Previews & exposure**
- Private by default: preview URLs require the panel session (Caddy `forward_auth` to the panel).
- Client share links: signed token, per-service, expiring (24 h default), optional password, `noindex`, revocable from the UI.
- Per-project subdomains so a bad project doesn't taint your main domain's reputation.
- A **panic switch** in the panel: stop all sandboxes, disable all share links, rotate credentials.

**Operational**
- Automated dependency updates + a staging instance of the panel itself; weekly snapshots of the box; nightly offsite DB dumps with a tested restore.
- Cloudflare in front from day one (free tier): WAF, bot fight mode, caching off for the panel, and it hides your origin IP.

---

## 5. What "mixed devs + non-devs" and "occasional clients" change in the product

**For non-devs (big UI decisions):**
- Verbs, not git nouns: **Upload** · **Save & publish** (= commit + deploy) · **Go back to Tuesday's version** · **Restore the database too?**
- Templates with a "runs in 60 seconds" promise: Laravel starter, WordPress, static site, plain PHP.
- Guardrails everywhere: typed confirmation on delete, dry-run list for bulk deletes, "you're about to commit 6,412 files (1.2 GB)" warnings, protected paths (`.env`, `vendor/`, `storage/`).
- Error translation to plain English with one suggested fix ("Composer failed: missing `ext-intl` → [Install extension]").
- Git stays available as an "advanced" drawer for you and the devs — never as the entry point.

**For clients:**
- Share links (above) + a read-only **client view** with the project name, a QR code, and a "last updated" stamp.
- One-click screenshot/PDF of a preview page for sending over WhatsApp — sounds small, gets used constantly.

---

## 6. Revised v0 (after all four answers)

1. **Get the box** (Oracle Always Free / Hetzner CAX11 / mini PC) + Cloudflare DNS + Caddy + Docker. *This is now step zero — nothing works without it.*
2. Panel with GitHub OAuth + org allowlist; one project, one user (you), audit log on from the start.
3. Folder/ZIP drop → single atomic commit via real `git` → deploy → private preview URL per service.
4. Runtimes: static + PHP (bare) + **Laravel** + SQLite, then shared MySQL with DB-per-project.
5. `.env` paste + `.env.example` missing-key checklist; missing `APP_KEY` auto-generated.
6. Deploy lock + visible build log + "wake from sleep" handling.
7. Invite one teammate and one non-dev, watch them use it for an afternoon, then fix what confused them.

---

## 7. Your call before I scaffold

The blocker is hosting, not design. Once you pick the box, the next concrete artifact is a `docker-compose` stack (panel + Caddy + MySQL + sandbox runner) plus the drop → commit → run pipeline. Tell me which path and I'll start building it.
