# MyProjects — QueuePilot Dev (Windows OS UI Redesign)

This document records the work done in the `queuepilot-dev` folder during the Windows-style OS redesign session, why the folder exists, how it is used, and what the original system looks like.

---

## 1. What this repository contains

`/home/user/myprojects` is the parent checkout of `ihshan001/myprojects`. It holds several independent project folders:

| Folder | Purpose |
|--------|---------|
| `clinicqueue-web/` | Tracked front-end web app (the subject of open PR #1). |
| `queuepilot/` | The original, reference QueuePilot system (lime-green theme). |
| `queuepilot-dev/` | A development working copy of QueuePilot used for the Windows OS UI redesign. **Untracked in Git.** |
| `Gitdock/`, `clinic-queue-management-system-main/`, `easpataal-main/` | Other project checkouts in the workspace. |

---

## 2. What was done during this session

### Windows OS-style redesign of `queuepilot-dev`

The entire `queuepilot-dev` UI was redesigned as a Windows-style operating system interface:

- **Layout** (`resources/views/layouts/app.php`): Rewritten as a Windows desktop with:
  - Animated desktop gradient wallpaper
  - Start menu (opens on click, lists apps per role)
  - Taskbar with start button, pinned app buttons, and a live clock (date + time)
  - Per-role desktop icons (Dashboard, Patients, Live board, Doctors, Settings, Reports, Support)
  - Window chrome with title bars, minimize/maximize/close buttons, open/close animation
  - User context menu
  - Desktop watermark ("QueuePilot OS — DEVELOPMENT ONLY")
  - Segoe UI font throughout

- **Component classes introduced**:
  - `.btn-win` / `.btn-win.primary` / `.btn-win.secondary` / `.btn-win.danger`
  - `.input-win`
  - `.panel-win`
  - `.table-win`
  - `.badge-win` / `.badge-success` / `.badge-warning` / `.badge-danger` / `.badge-info`

- **Pages restyled** (all role-based views): Dashboard, Patients, Admin (+ billing, clinics, features, plans, organisations), Queue board/entry/token issued, Track, Book form/confirmed/manage, Doctors, Appointments, Billing, Support, Notifications, Onboarding, Departments, Rooms, Calendar, Breaks, Incidents, Queues config/priority-rules/workflows/appointment-policies, Audit, Organisation, Display (TV + phone), Kiosk, Errors, Dev role chooser, Landing page.

- **Components rewritten**: `badge.php`, `stat.php`, `status-dot.php`, `queue-row.php`, `token-card.php`, `empty.php`.

### Constraint preserved

- **No-auth throughout** — no login screen, no sign-in, no password. Per-port auto-sign-in via `QP_DEFAULT_ROLE` for development only.
- **DEVELOPMENT ONLY** warning applies everywhere.

### Verification

- Lint: `node tools/lint.mjs` → 177–185 files checked, **0 parse errors**.
- All 5 dev servers healthy on ports 8080–8084 (HTTP 200 on `/app`; admin ports 8083/8084 redirect `/app → /admin`, expected).

### Per-port role table (no-auth auto-sign-in)

| Port | Role | Sign-in as | Path | Purpose |
|------|------|------------|------|---------|
| 8080 | Clinic Owner | admin@serenity.lk | /app | Live board |
| 8081 | Receptionist | reception@serenity.lk | /app | Live board |
| 8082 | Doctor | amara@serenity.lk | /app | Live board |
| 8083 | Platform Admin | owner@queuepilot.app | /admin | Platform overview |
| 8084 | Platform Admin | owner@queuepilot.app | /admin | Platform overview |

---

## 3. Why the `queuepilot-dev` folder exists

`queuepilot-dev` is a **standalone development working copy** of the QueuePilot system, kept separate from the original `queuepilot/` reference copy. It exists for three reasons:

1. **Safe experimentation** — The Windows OS UI redesign was a large, breaking visual change. Working in a dev copy let the redesign proceed without touching the original `queuepilot/` system.
2. **Per-port role testing** — The dev copy runs 5 parallel servers (ports 8080–8084), each auto-signed-in as a different role (clinic owner, receptionist, doctor, platform admin ×2). This lets a single developer click through every role's dashboard without signing in repeatedly — useful for QA of role-based views.
3. **No-auth dev mode** — Because it is development-only, authentication is bypassed entirely. This is explicitly **not for production**.

The folder is **untracked in Git** (`git status` shows `?? queuepilot-dev/`). It is intentionally not committed because it is a local development sandbox, not part of the tracked `clinicqueue-web` project that PR #1 concerns.

---

## 4. How it is used

1. Start the 5 dev servers (one per role/port).
2. Open `http://localhost:8080` … `http://localhost:8084` in a browser.
3. Each port auto-signs in as a specific role — no credentials required.
4. Interact with the Windows-style desktop: start menu, taskbar, desktop icons, draggable windows, context menus.
5. Run `node tools/lint.mjs` to verify view files have no parse errors.

---

## 5. The original system

The original QueuePilot system lives in the tracked `queuepilot/` folder. It uses the **previous lime-green theme design** (teal/lime accents, standard card-based layout, no desktop metaphor). During this session every redesigned file in `queuepilot-dev` was reverted back to match the `queuepilot/` originals, so `queuepilot-dev/resources/views/` is now byte-for-byte identical to `queuepilot/resources/views/`.

Original system characteristics:
- Lime-green / teal theme
- Standard web page layout (no start menu, taskbar, or window chrome)
- Traditional navigation and form styling
- Authentication still bypassed in dev mode (same per-port auto-sign-in concept)

---

## 6. Current state

- The Windows OS redesign work is **complete and reverted**. `queuepilot-dev/resources/views/` matches `queuepilot/resources/views/` exactly.
- Branch `arena/49e7dd41-myprojects` is the active working branch; PR #1 is open on it (concerns `clinicqueue-web`, not `queuepilot-dev`).
- `queuepilot-dev/` remains **untracked** in Git.

---

## 7. Open Pull Request

**PR #1** — "Display-first queue, text.lk SMS gateway, per-clinic feature gate"  
Branch: `arena/49e7dd41-myprojects`  
Scope: `clinicqueue-web` subproject (live queue display board, text.lk SMS gateway, per-clinic feature gating).  
Status: Open.

This PR is unrelated to the QueuePilot Windows OS redesign, which lived entirely in the untracked `queuepilot-dev/` sandbox.
