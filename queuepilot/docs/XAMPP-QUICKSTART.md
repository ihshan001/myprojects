# QueuePilot on XAMPP — the whole thing, start to finish

Everything below is the sequence that has been tested in this repository. Follow it
in order. If you only read one warning, read this one:

> **`GET /clinicqueue/.env` must return 404.** If it downloads, your Apache is
> ignoring `.htaccess` (`AllowOverride None`) and your database password and
> `APP_KEY` are readable by anyone on your network. Fix that before anything else.

---

## 0. What you need

| Thing | Version | Notes |
|---|---|---|
| XAMPP | with **PHP 8.1+** (8.2/8.3 ideal) | 7.4 will not parse the code |
| PHP extensions | `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl` | All on by default in XAMPP |
| MySQL / MariaDB | whichever XAMPP bundles | MariaDB 10.3+ or MySQL 5.7+ |
| A browser | any | phpMyAdmin is served by XAMPP |

**You do not need:** Composer, Node.js, npm, or an internet connection.
The CSS is pre-compiled and committed; there are zero PHP dependencies.

Check PHP first:

```cmd
C:\xampp\php\php.exe -v
```

You must see `PHP 8.1.x` or higher. If it says 7.4, install a newer XAMPP.

---

## 1. Put the files in htdocs

Unzip this folder into `htdocs` and name it whatever you like. The examples use
`clinicqueue`:

```
C:\xampp\htdocs\clinicqueue\
    index.php        ← front door for this layout (already in the repo)
    .htaccess        ← sends routes to index.php (already in the repo)
    public\          ← index.php, .htaccess, assets\
    app\  config\  database\  resources\  routes\  storage\  tools\
    .env             ← you create this in step 3
```

Nothing needs to be copied, moved or created by hand. That is the entire point of
the root `index.php` and `.htaccess` — they only exist so this layout works.

### If you see a directory listing instead of the site

That means Apache is not reading `.htaccess`. Open
`C:\xampp\apache\conf\httpd.conf`, find the block for your htdocs directory and set:

```apache
<Directory "C:/xampp/htdocs">
    AllowOverride All
    Require all granted
</Directory>
```

Restart Apache. Also confirm `mod_rewrite` is enabled (uncomment
`LoadModule rewrite_module modules/mod_rewrite.so` if needed).

---

## 2. Start Apache and MySQL

XAMPP Control Panel → **Start** Apache → **Start** MySQL. Both must show green.

If MySQL will not start, port 3306 is taken by another MySQL service. Either stop
that service or change XAMPP's port and update `DB_PORT` in step 3.

---

## 3. Create the database

### In phpMyAdmin (http://localhost/phpmyadmin)

1. **Databases** tab.
2. Name: `queuepilot` — locally use exactly this, no prefix.
3. Collation: `utf8mb4_general_ci` (the script sets table-level charset anyway).
4. **Create**.

Do **not** create a user. XAMPP's `root` with an empty password is correct locally.

### Import the schema — one file, one time

1. Click the `queuepilot` database in the left sidebar.
2. **Import** tab → **Choose File** → `C:\xampp\htdocs\clinicqueue\database\install.sql`
3. Scroll down → **Go**. Wait for "Import has been successfully finished".

That file runs 148 statements and creates **52 tables**, seeds the four subscription
plans, and records the 31 applied migrations so the CLI will never re-run them.

> **Do not import `database/schema/mysql.sql` on its own.** It is only 16 tables.
> The other 36 come from the migrations. `install.sql` already contains both, in
> the right order. An app on the bare schema throws on the first request.

### Verify the import

phpMyAdmin → `queuepilot` → **SQL** tab:

```sql
SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema = DATABASE();        -- must be 52
SELECT COUNT(*) FROM plans;              -- must be 4
SELECT COUNT(*) FROM schema_migrations;  -- must be 31
```

If `schema_migrations` is 0 or missing, the import was partial — drop the database,
recreate it, and import again.

There is a second way to confirm it, and it is the one to use if you prefer the
command line:

```cmd
C:\xampp\php\php.exe tools\healthcheck.php
```

It prints the driver, the plan count and the number of checks it ran, and says
`All good.` or names the missing table. Eighteen checks, no writes, safe to run
any time.

---

## 4. Create `.env`

**The fast path.** This repository ships a profile already set up for this exact
layout — Apache on port 80, MySQL on `127.0.0.1:3306`, `root` with a blank
password, the app in `htdocs\clinicqueue`:

```cmd
cd C:\xampp\htdocs\clinicqueue
copy .env.xampp.example .env
```

Then set **one** value, `APP_KEY`, as described below. That is the whole step. The
rest of this section explains what is in that file and what to change if your
folder is not called `clinicqueue`.

### Or build it by hand

Copy `.env.example` to `.env` and edit it:

```cmd
cd C:\xampp\htdocs\clinicqueue
copy .env.example .env
```

Now set these values. Everything else has a safe default.

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/clinicqueue
APP_NAME=QueuePilot
APP_TIMEZONE=Asia/Colombo

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=queuepilot
DB_USERNAME=root
DB_PASSWORD=

SESSION_PATH=storage/sessions

APP_KEY=
MAIL_TRANSPORT=log
SMS_GATEWAY=log
WHATSAPP_TRANSPORT=log
SECURITY_REQUIRE_2FA_SUPER=true
```

### The two lines people get wrong

**`APP_URL` must include the folder name.** `http://localhost/clinicqueue` — not
`http://localhost`, and no trailing slash. That path is how the app knows it is
mounted in a subfolder; it is used to strip the prefix from incoming requests and
to prefix every link, form action, redirect and asset URL. Get it wrong and every
page 404s or renders unstyled.

**`APP_KEY` is required even locally.** Generate it:

```cmd
C:\xampp\php\php.exe -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;"
```

Paste the whole output as its own line in `.env`. It encrypts two-factor secrets.
Changing it later invalidates every 2FA enrolment.

### The 2FA line, and why you might turn it off on your own laptop

`SECURITY_REQUIRE_2FA_SUPER=true` is the default and it is enforced in
`public/index.php`, not per route — a platform admin who has not enrolled
two-factor is redirected to `/settings/security?2fa=required` and cannot reach
`/admin` until they do. `/settings/security`, `/logout`, `/locale` and
`/api/health` stay reachable so the enrolment page can be used at all.

If you are setting this up on your own laptop purely to look around, enrolling
your phone every time you recreate the database gets old. Setting it to `false`
lifts the redirect. Do not carry that line to a real host: the platform admin can
read every clinic's data.

### Why `MAIL_TRANSPORT=log`

Every email is written to `storage\logs\mail.log` instead of being sent. That lets
you read the welcome link, the invoice and the receipt without configuring SMTP.
This is the fastest way to see exactly what the app sends — open that file in a text
editor after any action that should email somebody.

---

## 5. Create the plans and your admin account

Open a Command Prompt in the app folder:

```cmd
cd C:\xampp\htdocs\clinicqueue

:: 1. The four subscription plans. No demo data.
C:\xampp\php\php.exe tools\seed.php --plans-only

:: 2. Your platform admin. Prints a one-time password — copy it now.
C:\xampp\php\php.exe tools\make-admin.php --email=you@example.com --name="Your Name"
```

The plans already came in with `install.sql`; running `--plans-only` is harmless and
confirms it.

> **Run these after the import, not before.** `seed.php` on a database with no
> schema stops with *"the database has no schema yet — the `plans` table does not
> exist"* and tells you to run the import. If you see that, go back to step 3.

> **Note:** `tools\migrate.php` is the equivalent of the phpMyAdmin import. If you
> prefer the CLI end-to-end, skip step 3's import and instead run:
> `C:\xampp\php\php.exe tools\migrate.php`
>
> Running it *after* an `install.sql` import is also safe — `install.sql` records
> all 31 migrations in `schema_migrations`, so the CLI reports
> "Nothing to migrate" and does nothing. That is why the table is seeded.

### Optional: demo data to click around in

Local only, never on a real host:

```cmd
C:\xampp\php\php.exe tools\seed.php
```

That creates three demo clinics and prints these logins:

| Role | Email | Password |
|---|---|---|
| Platform owner | `owner@queuepilot.app` | `QueuePilot#2026` |
| Clinic admin | `admin@serenity.lk` | `Serenity#2026` |
| Receptionist | `reception@serenity.lk` | `Reception#2026` |
| Doctor | `amara@serenity.lk` | `Doctor#2026` |
| Second clinic | `admin@lakeview.lk` | `Lakeview#2026` |
| Suspended clinic | `admin@coastal.lk` | `Coastal#2026` |

The suspended one is deliberate: signing in lands on `/billing?suspended=1` and the
queue screens are refused. It exists so you can see what a lapsed subscription does
to a clinic without having to expire one yourself.

---

### Locked out of a demo account?

Changing `reception@serenity.lk`'s password to see the change-password screen is
easy to do and easy to forget.

**If it is the platform admin**, do not go near the database — there is a command:

```cmd
C:\xampp\php\php.exe tools\make-admin.php --email=you@example.com --reset
```

It prints a fresh one-time password, clears `must_change_password`'s cause and
clears two-factor, so the next sign-in starts the whole first-run flow again.
`tools\make-admin.php --list` shows every platform admin and whether it has
enrolled 2FA yet.

**For any other account**, there is no such command. Print the hash and paste it:

**Step 1** — print a fresh hash for the password you want:

```cmd
C:\xampp\php\php.exe tools\hash-password.php --password "Reception#2026"
```

It prints one line starting `$2y$10$`. Note the **space** between `--password` and
the value: the flag reader looks at the next argument, so `--password=value` prints
nothing at all. Copy the whole line including the `$` characters.

**Step 2** — paste it into phpMyAdmin against the right account:

```sql
UPDATE users
   SET password_hash = '$2y$10$paste-the-whole-line-here',
       must_change_password = 0
 WHERE email = 'reception@serenity.lk';
```

`must_change_password = 0` matters for accounts created by `make-admin.php`: leave
it at 1 and you are sent straight back to the change-password screen. Swap the
address to repair any other account — `amara@serenity.lk`, `admin@lakeview.lk`, and
so on.

### If a login suddenly stops working

Three limits will refuse you, and all three are working security, not breakage:

| Limit | Default | Where it is counted |
|---|---|---|
| Failed sign-ins | 6 per address per 10 minutes | `login_attempts` |
| Verify-email resend | a small per-IP and per-account cap | `rate_limits` |
| Support tickets | 10 per clinic per hour | `rate_limits` |

If you are certain the password is right and sign-in still fails, look at
`storage\logs\app.log` for `auth.rate_limited`. Clear the counter and try again:

```sql
DELETE FROM login_attempts WHERE successful = 0;
DELETE FROM rate_limits;
```

Both are safe to run on a development database. Neither is safe on a real one —
clearing `rate_limits` there removes a brute-force guard.

## 6. Verify it works

Open these four URLs **in this order**:

| URL | Expected |
|---|---|
| `http://localhost/clinicqueue/` | The public landing page with pricing |
| `http://localhost/clinicqueue/login` | The staff sign-in form |
| `http://localhost/clinicqueue/assets/build/app.css` | CSS text, not a 404 page |
| `http://localhost/clinicqueue/.env` | **404** — must not download |

If `/.env` downloads, stop and fix `AllowOverride` (step 1).

Then sign in at `/login` as your admin. You will be asked to change the password,
then to enrol two-factor authentication, and you land on `/admin`.

### Command-line health checks

```cmd
cd C:\xampp\htdocs\clinicqueue
C:\xampp\php\php.exe tools\healthcheck.php
C:\xampp\php\php.exe tools\host-drill.php
```

`healthcheck.php` is the one to watch: 18 checks, and it names any table that is
missing rather than failing quietly.

`host-drill.php` will report failures locally, and that is **correct** — it checks
production hardening (`APP_DEBUG=false`, non-world-readable sessions). Read the
failure text; do not try to make it green on a development machine.

### Proving the whole thing actually writes to the database

Two scripts are for developers rather than installers, and they need the bundled
Node dev server instead of Apache. If you have Node 18+ and want more than "the
pages load":

```bash
node tools/serve.mjs             # terminal 1 — same code, no Apache needed
node tools/sql-coverage.mjs      # terminal 2 — 204 routes, reports failed SQL
node tools/write-coverage.mjs    # submits every create form, reads the row back
```

`sql-coverage.mjs` sweeps every route and reports any statement that threw, which
catches the failures controllers deliberately swallow. `write-coverage.mjs` goes
further: it submits each create form with valid data and then reads the row out of
the database, because a redirect back to the form page looks identical whether the
insert happened or validation refused it. It changes the receptionist's password to
test that path and puts it back afterwards, and it fails if it cannot.

Neither is part of a normal XAMPP install — you do not need Node to run QueuePilot.
They exist to answer "is this genuinely wired up?" with evidence.

**Neither works against your MySQL database as written.** Both assume the SQLite
file the dev server uses: `write-coverage.mjs` reads rows with Python's `sqlite3`,
and `sql-coverage.mjs` reads `storage/logs/app.log`, which the app writes whatever
the driver. So on XAMPP you get the log-based half for free and would have to
retarget the row reads at MySQL — `mysql -u root queuepilot -e "..."` in place of
the `sqlite3` calls — to get the other. That is the honest state of it: against
SQLite the write paths are proven, against MySQL the `healthcheck` and your own
click-through are the evidence.

---

## 7. Your first ten minutes in the app

1. Sign in as your admin. Change the password. Enrol 2FA.
2. `/admin` → the console should say **"0 things waiting on you"**.
3. Sign out, go to `/register`, and apply for a clinic with a second email address.
4. Sign back in as your admin → `/admin` now says **"1 thing waiting on you"**.
5. Open `/admin/clinics`. The pending application shows owner, phone, city, email and
   how long it has waited. Choose **Approve**, **Grant trial** (14 days), or **Decline**.
6. Open `storage\logs\mail.log`. The welcome email should contain the patient board
   URL, the public page URL, and the staff sign-in link.
7. Open the patient board URL in a new tab. It should show the clinic's live queue.
   That is the address you put on the waiting-room TV.

---

## Running the queue for a real day

This is the daily loop, in order, because the system enforces it:

1. **Start the session** — Doctors & rooms → the doctor → **Start session**. Until
   you do this, calls are refused with *"That session has not been started yet."*
   That is deliberate: nobody should be called to a room before the doctor is in.
2. **Issue tokens** — New patient. You get a receipt with a token number, a QR code
   and a tracking URL. Print it or show it to the patient.
3. **Call the next patient** — the board shows Call / Skip / No-show / Cancel per row.
   Two people pressing Call at once is safe: exactly one wins, the other gets
   *"That entry changed on another device"* and the board refreshes.
4. **Doctor starts and completes** the consultation. Completing does **not**
   auto-call the next patient — that is a separate deliberate press.
5. **Break** — starting a break pauses the queue. While paused, issuing new tokens
   and calling patients are both refused with an explanation. Finishing a
   consultation already in progress is still allowed.
6. **End of day** — close the session. If patients are still waiting, you are told
   how many and must confirm. They are then recorded as not seen — not blamed as
   cancellations.

### Undoing mistakes

- **Cancelled the wrong token?** The entry keeps a `requeue` action. It goes back to
  the waiting list with the original reason still recorded in the event history.
- **Patient stepped out?** Use No-show, then Requeue when they return. Same result.

---

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| **Directory listing instead of the site** | `AllowOverride None` in httpd.conf. Set `All`. |
| **Every page 404s** | `APP_URL` is missing the `/clinicqueue` path, or `mod_rewrite` is off. |
| **Site loads but is unstyled** | Same cause — `APP_URL` path. Asset URLs follow it. |
| **500 on every page** | `APP_KEY` empty, or `storage\` not writable. Read `storage\logs\app.log`. |
| **Blank page, no error** | `APP_DEBUG=false`. Set `true` temporarily. |
| **"Table not found"** | Import was partial. Drop the DB, recreate, re-import `install.sql`. |
| **Login loop back to /login** | `storage\sessions` not writable, or `APP_URL` does not match the browser URL exactly. |
| **`/.env` downloads** | Document root exposed. Fix `AllowOverride` immediately. |
| **No emails arrived** | `MAIL_TRANSPORT=log` means nothing is sent. Read `storage\logs\mail.log`. |
| **MySQL 8 "caching_sha2_password"** | You are using `root` locally, so this should not appear. If it does, use a `mysql_native_password` user. |
| **Port 80 taken** | Skype/IIS/VPN. Change Apache's port and update `APP_URL`. |
| **Cannot log in as platform admin** | `php tools\make-admin.php --list`; `--reset` if 2FA is lost. |
| **Correct password refused, repeatedly** | Rate limit. `auth.rate_limited` in `app.log`; see the counter table above. |
| **Signed in but sent straight back to change-password** | `must_change_password` is still 1. The guide's repair SQL sets it to 0. |
| **`/admin` bounces to `/settings/security?2fa=required`** | Working as designed. Enrol 2FA, or set `SECURITY_REQUIRE_2FA_SUPER=false` locally. |
| **`hash-password.php` prints nothing** | You used `--password=value`. Use `--password value` with a space. |
| **Calls refused: "session has not been started"** | Press **Start session** on that doctor first. Not a bug — see the daily loop below. |
| **"That entry changed on another device"** | Two people pressed Call at once. Exactly one wins by design. Reload the board. |
| **Support form saved but no ticket appeared** | Ten tickets per clinic per hour. Check `rate_limits` for a `support:` row. |

---

## What this guide cannot promise

Being straight with you, because it matters more than a green checklist:

- **`install.sql` has never been executed against a real MySQL server.** There is no
  MySQL in the environment it was built in. What has been proven is that it is
  *complete*: the 52 table names it creates are byte-for-byte the same set the
  migration runner builds, and it is generated from the same source of truth those
  migrations are, so it cannot drift from them. It is also validated statically —
  every statement parses as MySQL, every `ALTER` targets a real table, every
  foreign key points at a real table and column, and 148 statements produce the
  52 tables, 4 plans and 31 migration rows step 3 checks for. What static checking
  cannot prove is that MariaDB accepts every construct on the day you run it.
  **Your import in step 3 is that proof.** If it fails, the phpMyAdmin error
  message is the thing to send back.
- **No route has ever run against MySQL here either.** Every route and every write
  path was exercised against SQLite — 204 routes with no failed statement, and 15
  create paths verified by reading the row back. The one class of bug that hides
  from that is a query that is valid in SQLite and invalid in MySQL; six of those
  were found and fixed, and `tools/dialect-check.php` now fails the build if
  another appears. It is a spelling check, not a behaviour check.
- **The `.htaccess` rewrite has not been executed under Apache** either, for the same
  reason. Step 6's four checks are the test.
- **Bank details are blank** on invoices by design. Until you set `COMPANY_BANK`,
  `COMPANY_BANK_ACCOUNT_NO` and friends in `.env`, invoices and their emails say
  "our bank details are not on this installation yet" rather than inventing them.

If step 3's import or step 6's checks fail, paste the exact error and it can be
fixed against the real message rather than a guess.
