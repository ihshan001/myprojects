# How GitDock talks to GitHub

There are **two independent connections**, and knowing which is which explains almost every behaviour you'll see.

```
                         ┌──────────────────────────────────────────────┐
   your browser ────────►│  GitDock control plane  (app/server.js)      │
   (panel on :3000)      │                                              │
                         │  ① git transport   ② REST API                │
                         └───────┬───────────────────────┬──────────────┘
                                 │                       │
                    real `git` binary over HTTPS    fetch() to
                    clone / fetch / push            api.github.com
                                 │                       │
                                 ▼                       ▼
                         ┌──────────────────────────────────────┐
                         │            github.com                │
                         │  git smart-HTTP   +   REST v3        │
                         └──────────────────────────────────────┘
```

| | ① git transport | ② GitHub REST API |
|---|---|---|
| Used for | clone, pull (fetch + merge), push, fetching PR refs | listing pull requests, verifying your token, reading your account name |
| Mechanism | the real `git` binary, executed as a child process | plain HTTPS `fetch()` with a `Bearer` token |
| Auth | token handed to git **per invocation**, via a credential helper | `Authorization: Bearer <token>` header |
| Needs a token? | only for **private** repos (public repos clone/push-to-your-fork work anonymously) | yes, always (GitDock requires it for PR listing) |
| Code | `gitRemote()`, `runCloneJob()`, `lib/askpass.sh` | `ghApi()` |

---

## 1. Where the token lives

**Settings → GitHub token** → paste a Personal Access Token → **Save**.

What happens in that moment (`POST /api/settings/token`):

1. The token is stored in the process's memory (`state.githubToken`) — **not** written to disk, not in a config file, not in a database.
2. GitDock immediately calls `GET https://api.github.com/user` to prove the token works and learn your login and display name.
3. The header shows `GitHub: <your-login>`. If the call fails, the token is discarded and the error is shown.

**Scopes you need:**

| Task | Fine-grained token | Classic token |
|---|---|---|
| clone / pull public repos | nothing | *(none)* |
| clone / pull private repos | `Contents: read` | `repo` |
| push | `Contents: read and write` | `repo` |
| list pull requests | `Pull requests: read` | `repo` |

**Consequence of memory-only storage:** every time the panel process restarts, the token is gone and you paste it again. That's deliberate for this prototype — a demo shouldn't own your credentials — and it's the first thing a production version replaces with a GitHub App.

---

## 2. How the token reaches git (without ever being written down)

GitDock never edits the remote URL. It uses a **credential helper**, which is the mechanism git provides for exactly this:

```bash
git -c core.askpass=/home/user/gitdock/app/lib/askpass.sh push -u origin main
# with the environment for THIS child process only:
GITDOCK_TOKEN=<your token>
GITDOCK_LOGIN=<your login>
GIT_TERMINAL_PROMPT=0     # fail fast instead of hanging on an invisible prompt
```

`lib/askpass.sh` is four lines: when git asks for a username it prints the login, when it asks for a password it prints `$GITDOCK_TOKEN`. The environment dies with the process, so **the token exists on disk nowhere**.

Why not the obvious `https://user:token@github.com/owner/repo.git`? Because that URL gets written into `.git/config`, copied into logs, and echoed in error messages. Verified on a real clone:

```
[remote "origin"]
    url = https://github.com/octocat/Spoon-Knife.git
    fetch = +refs/heads/*:refs/remotes/origin/*
```
`grep -icE 'token|password|ghp_|credential' .git/config` → **0**. The self-test enforces this (`GitDock never stores credentials in the repository config`), and all output returned to the browser is passed through `redact()`, which strips `ghp_…`, `github_pat_…`, `x-access-token:…@` and any occurrence of the current token.

---

## 3. Connecting a repository — two ways in

**A. Clone (start from GitHub)** — `+ New project` → `clone`:

```
git clone --progress https://github.com/owner/repo.git .     (in data/projects/<slug>/)
  → detection runs        (Laravel / WordPress / PHP / Node / static)
  → service definitions written, ready in the Run bar
  → identity reconciled    (see §5)
```
Keeps the repo's real history and `origin` remote. This is what GitDock did to `octocat/Spoon-Knife`: cloned 16 objects, detected `static`, and the panel then showed the actual upstream history (`The Octocat | Created index page for future collaborative edits`). It starts serving it on its own port immediately.

**B. Connect a remote to an existing project** — GitHub tab → paste URL → **connect**:

```
git remote add origin <url>          # any git host; PR features need github.com
```
GitDock records the URL, marks the provider, and remembers the branch. Push/pull work with any git URL — GitLab, a self-hosted server, or a path on this machine.

---

## 4. Pull, push, and what each answer means

**Pull** = `git fetch origin --prune` then `git merge --no-edit origin/<branch>`. Before merging, GitDock counts your local commits so it can tell you what it's about to do. Failures are specific, not generic:

| Situation | What GitDock says | Status |
|---|---|---|
| Remote needs credentials | "cannot read that remote — check the URL, and add a GitHub token in Settings for private repos" | 401 |
| Repo missing / URL wrong | the real git error | 400 |
| No shared history (empty project vs existing repo) | "no shared history — push first, or start from a clone instead" | 409 |
| Real merge conflict | "GitDock aborted the merge and left your working tree untouched" | 409 |

The merge is **aborted** on conflict, so your working tree is never left half-merged.

**Push** = `git push -u origin <branch>:<branch>`. GitDock never force-pushes. It attempts the push first and only asks for a token if the remote actually refuses anonymous access — so public/fork pushes work without credentials. If the remote moved ahead, git rejects the push (non-fast-forward) and you pull first; that's the intended guardrail, not a bug.

---

## 5. Who the commits are authored as (easy to miss, matters on GitHub)

GitHub attributes a pushed commit to whatever the **author line** says. GitDock resolves that in this order:

1. **The project's own identity** — GitHub tab → *Commit identity* → name + email → *save for this project*
2. **The panel default** — Settings → *Default commit identity*
3. **Your connected GitHub account** — `Your Name` / `<login>@users.noreply.github.com` (GitHub's no-reply address, so your real email stays private)
4. **Fallback** — `GitDock <gitdock@localhost>`, so a commit can never fail

The resolved value is written into that repo's own `.git/config` before every commit (`ensureGitIdentity`). Two fixes landed here:

- **A cloned repo has no local git identity**, so the first edit used to fail with `fatal: empty ident name`. Now the identity is reconciled at clone time *and* re-checked before every commit.
- Commits used to be authored as `GitDock <gitdock@localhost>` even when you were signed in to GitHub. Now, with a token connected, they're authored as you.

---

## 6. Pull requests

**Listing** (`② REST API`): `GET /repos/:owner/:repo/pulls?state=all` → number, title, author, head→base branch, state, link. Requires the token and a github.com remote; a non-GitHub remote gets a clear "PR features need github.com" message instead of a silent failure.

**Previewing a PR** — GitHub publishes every PR's head commit under `refs/pull/<n>/head` in the *upstream* repo, so no API access is needed to get the code:

```
git fetch origin refs/pull/42/head:refs/gitdock/pr-42 --force
   → git worktree add --detach data/previews/<slug>-<id> refs/gitdock/pr-42
   → services start on their own ports, TTL countdown begins
```
Your `main` working tree is never touched, and the preview tears itself down when the TTL expires.

---

## 7. What is *not* connected (and what that costs you)

| Missing | Effect today | Production answer |
|---|---|---|
| **Webhooks** (`POST /webhooks/github`) | GitHub can't notify GitDock. PR previews are created when you click the button, not when the PR opens; nothing auto-deploys on push | A public endpoint with `X-Hub-Signature-256` verification, delivery dedupe, and a durable queue |
| **GitHub App / OAuth login** | You paste a personal token that expires and is re-entered after every restart; no per-person attribution, no org allowlist | GitHub App with short-lived installation tokens; commit attribution per real user; OAuth for panel login |
| **Checks / Actions / statuses** | GitDock can't run or read CI results, and doesn't comment on PRs | Actions API + `gitdock.yaml`-driven pipeline |
| **Force-push / branch protection awareness** | Rejections from protected branches appear as a plain git error | Surface the reason, offer branch creation via PR |

Everything above is *additive* — none of it changes the connection model in §1–§4, which is why the prototype's push/pull/PR-ref paths already behave correctly.

---

## 8. Connecting your own repo: step by step

1. **Create a token.** GitHub → *Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token*.

   On **Add permissions → Repository permissions**, tick exactly these (the screen's own labels):

   | Permission | Set it to | Why GitDock needs it |
   |---|---|---|
   | `Contents` | **Read and write** | clone and pull (read); push commits and delete branches (write) |
   | `Pull requests` | **Read and write** | the PR tab: list, create, merge, close |
   | `Metadata` | **Read-only** | GitHub adds this by itself — leave it, nothing works without it |
   | `Workflows` | Read and write *(optional)* | only needed to push edits to `.github/workflows/`; a push containing one is rejected without it |

   Leave every other permission off, including **Account permissions** (none are needed). GitDock never creates a repository on GitHub — only clones existing ones — so `Administration` is not needed either.

   Rest of the form: name `gitdock`; **Repository access → All repositories** (choosing *Selected repositories* means ticking every repo by hand, and new repos are invisible until you do — this is the usual cause of "the token cannot see my repo"); an expiry of your choosing. If the repo belongs to an **organisation**, an org owner must approve the token under the org's *Settings → Personal access tokens* — selecting the repo alone is not enough.

   **Classic token instead?** Tick a single scope, `repo` (add `workflow` if you push workflow files).
2. **Paste it** in GitDock → **Settings → GitHub token → Save** → the header shows `GitHub: <login>`.
3. **Either clone or connect:**
   - fresh start: **+ New project → clone** → `https://github.com/you/repo.git`
   - existing project: **GitHub tab → connect** → same URL
4. **Set the identity you want on GitHub** — GitHub tab → *Commit identity*, or click *use `<login>`@users.noreply.github.com*.
5. **Pull** once to make sure the remote is readable, then edit/upload something, then **Push**.
6. Check github.com: the commit is there, authored as you, with the message GitDock generated (`Upload 142 file(s) (8.3 MB)`).

---

## 9. GitHub-specific troubleshooting

| Symptom | Meaning | Fix |
|---|---|---|
| `set a GitHub token in Settings first` | panel restarted; token was memory-only | paste it again |
| Push says the remote asked for credentials | token missing/expired, or wrong scope | new token with `Contents: write` |
| `Repository not found` on a private repo | token not sent, or no access to that repo | check the token's repo selection |
| `non-fast-forward` on push | remote has commits you don't | **Pull** first — GitDock never force-pushes |
| Pull: "no shared history" | local project was created empty, remote already has history | clone the repo instead, or push your local work first |
| Merge conflict | both sides changed the same lines | conflict is aborted; resolve in the Files tab, then push |
| Commits show as "GitDock" on GitHub | no identity set anywhere | GitHub tab → *Commit identity*, or connect a token |
| PR list says "needs a github.com remote" | remote is GitLab/self-hosted | expected: PR features are GitHub-only |
| Clone/composer fails after a long pause | outbound network blocked or the box was asleep | retry; check the job log in Setup |

---

## 10. Evidence from the running system

- Cloned `octocat/Spoon-Knife` from real GitHub with no token, detected `static` (high confidence), started it on its own port, and served `<title>Spoon-Knife</title>`.
- Verified `.git/config` contains **no** credentials and the remote URL has no embedded token.
- Verified the self-test's 35/35 checks, including four GitHub-specific ones: clone → edit → commit works; a custom identity lands in both git config and the author line; a malformed email is rejected; credentials never touch repo config.

```
node /home/user/gitdock/scripts/selftest.js     →  ALL GREEN 35/35
```
