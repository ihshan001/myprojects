# Installing QueuePilot

Two environments, one codebase. **Part A** is a real cPanel host (shared hosting,
Apache, MySQL, no root). **Part B** is XAMPP on your own PC with phpMyAdmin.

Read the part that applies to you. Both end at the same place: `/login` answers,
and you have a platform admin account.

> **The one thing that breaks this install.** The web root must be `public/`, never
> the folder containing `.env`. If the document root points at the repository root,
> `GET /.env` returns `APP_KEY`, your database password and your SMS token as plain
> text. Both parts below handle it. Do not skip those steps.

---

## What you need

| | Requirement | Why |
|---|---|---|
| PHP | **8.1 or newer** (8.3 recommended) | The code uses `readonly` properties and `match`. It will not parse on 7.4. |
| Extensions | `pdo_mysql`, `mbstring`, `json`, `openssl`, `curl` | Database, UTF-8, 2FA secrets, HTTP gateways |
| Database | MySQL 5.7+/8.0 or MariaDB 10.3+ | 52 tables, mostly InnoDB with foreign keys |
| Web server | Apache (cPanel) or OpenLiteSpeed (CyberPanel) | Both are supported; see the rewrite notes |
| Composer | **Not needed** | Zero dependencies. `app/bootstrap.php` has its own autoloader. |
| Node.js | **Not needed in production** | Tailwind is a build step; the compiled CSS is committed. |

Check the PHP version before anything else:

```bash
php -v
```

If it says 7.x, stop and change the PHP version for the domain in cPanel
(**MultiPHP Manager**) or XAMPP. Nothing below will work.

---

# Part A — cPanel

Assumes a shared cPanel account with `public_html`, phpMyAdmin, and cron. You do
**not** need root. You do **not** run any background daemon.

## A1. Upload the files, outside the web root

On cPanel the document root is `public_html` and usually cannot be changed. So the
application lives *beside* it, and `public_html` holds only the front door.

Target layout:

```
/home/USER/queuepilot/          ← the application (NOT web-reachable)
  app/  database/  resources/  storage/  tools/  config/
  .env                          ← secrets live here
  public/                       ← the only thing that may be web-reachable

/home/USER/public_html/         ← the site root
  index.php                     ← loads ../queuepilot/public/index.php
  .htaccess
  assets/                       ← symlink to ../queuepilot/public/assets
```

**Upload with File Manager or SFTP.** If you have SSH, the simplest version is:

```bash
cd ~
# Upload the repository as ~/queuepilot-src, then:
mv ~/queuepilot-src/queuepilot ~/queuepilot
```

Do not put `~/queuepilot` inside `public_html`.

### Build the front door

Create `~/public_html/index.php` with exactly this:

```php
<?php
require __DIR__ . '/../queuepilot/public/index.php';
```

Now tell the app where its static files are. On cPanel, symlinks usually work from
File Manager (right-click → Link) or SSH:

```bash
ln -s ~/queuepilot/public/assets ~/public_html/assets
```

If your host refuses to serve symlinked directories (some disable `FollowSymLinks`),
copy `public/assets` into `public_html/assets` instead. You must re-copy it after
`npm run css`.

Copy `public/.htaccess` into `~/public_html/.htaccess` unchanged. It is already a
front controller:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^(?:assets|uploads)/ - [L]
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [L]
</IfModule>
```

Add one more block at the top of that file, because on cPanel the app lives *above*
the web root and a stray request must not climb out:

```apache
# Refuse anything that tries to reach above public_html.
RewriteCond %{REQUEST_URI} (^|/)\.\.(/|$)
RewriteRule ^ - [F]
```

**Verify before continuing.** In a browser:

| URL | Expected |
|---|---|
| `https://yourdomain.lk/` | The landing page |
| `https://yourdomain.lk/.env` | **404** |
| `https://yourdomain.lk/../queuepilot/.env` | **403 or 404** |

If `/.env` shows anything at all, stop and fix the document root. That is the whole
security model of this install.

## A2. Create the database

1. cPanel → **MySQL® Databases**.
2. Create a database. cPanel prefixes it: `myaccount_queuepilot`.
3. Create a user with a long generated password. cPanel prefixes it too:
   `myaccount_qp`.
4. **Add User To Database** → grant **ALL PRIVILEGES**.
5. Write all three down exactly as cPanel shows them, including the prefix.

`DB_HOST` stays `localhost` on virtually every cPanel host. Do not change it unless
your host's documentation says otherwise.

## A3. Create `.env`

Copy the example and edit it:

```bash
cd ~/queuepilot
cp .env.example .env
chmod 600 .env
```

Only these values must be right to get a working install. Everything else has a safe
default.

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.lk
APP_NAME=QueuePilot
APP_TIMEZONE=Asia/Colombo

DB_DRIVER=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=myaccount_queuepilot
DB_USERNAME=myaccount_qp
DB_PASSWORD=the-password-you-just-set

SESSION_PATH=storage/sessions

APP_KEY=
MAIL_TRANSPORT=none
SECURITY_REQUIRE_2FA_SUPER=true
MONITOR_TOKEN=
```

### `APP_KEY` — mandatory

Generate it on the server so it never passes through your laptop's clipboard history:

```bash
php -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;" >> .env
```

**The app refuses to boot in production without this.** It ships with a fallback key
that is committed to the repository, which means it is public — every 2FA secret
encrypted with it would be readable by anyone who has seen the source. That is a
hard 500, by design.

Changing `APP_KEY` later invalidates every two-factor enrolment. Users re-enrol at
`/settings/security`.

<details>
<summary>Values you should set within the first week</summary>

```ini
# Mail. Without this, no email leaves: no welcome link, no invoice, no receipt.
MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=youraccount@gmail.com
MAIL_PASSWORD=          # a Google App Password, not your login
MAIL_FROM=youraccount@gmail.com

# Where "a clinic just applied" alerts go. Defaults to COMPANY_EMAIL.
COMPANY_ALERTS_EMAIL=you@yourdomain.lk

# Invoices are legal documents. Bank details appear on every unpaid invoice.
COMPANY_LEGAL_NAME=Your Company (Pvt) Ltd
COMPANY_ADDRESS=
COMPANY_VAT_NUMBER=
COMPANY_EMAIL=
COMPANY_PHONE=
COMPANY_BANK=
COMPANY_BANK_BRANCH=
COMPANY_BANK_ACCOUNT_NAME=
COMPANY_BANK_ACCOUNT_NO=
COMPANY_BANK_SWIFT=

# Health monitoring. Without it, /api/health answers 404 to every monitor.
MONITOR_TOKEN=
```

**Gmail specifics.** The `From` address must be the Gmail address itself. Sending as
`you@yourdomain.lk` through Gmail fails SPF and lands in spam. You need 2-Step
Verification on, then an **App Password** (Google removed "less secure apps"). Free
tier is ~500 recipients/day and ~100 per message — fine to start, not to grow on.

</details>

## A4. Permissions

The PHP user (usually your cPanel user, sometimes `nobody`) must be able to write to
`storage/`. It must **not** be able to serve it.

```bash
cd ~/queuepilot
chmod -R 755 app database resources tools config
chmod -R 775 storage
chmod 600 .env
```

`storage/sessions` is created automatically if missing. If sessions misbehave, check
`storage/sessions` is writable and **not** group/world readable — a readable session
directory is a shared login.

## A5. Run the migrations

cPanel → **Terminal**, or SSH. There is no Node in production; use PHP directly.

```bash
cd ~/queuepilot
php tools/migrate.php
```

Expected:

```
→ applying 1_0005_structure.mysql
→ applying 1_0006_feature_gate.mysql
...
Applied 31 migration(s).
```

This applies `database/schema/mysql.sql` **and then every migration**. Do not import
only the schema file — it creates 16 tables and the rest come from migrations
(52 tables in total). An app on the bare schema will throw on the first request.

### If you have no SSH (phpMyAdmin only)

Use the consolidated file. **One import, not thirty-one.**

1. cPanel → **phpMyAdmin** → select your database.
2. **Import** tab → **Choose File** → `database/install.sql` → **Go**.
3. Wait for "Import has been successfully finished". It runs 149 statements
   (155 counting the session settings at the top and bottom).

`database/install.sql` is generated to do exactly what `php tools/migrate.php`
does, in the same order:

| Step | Statements | Tables created |
|---|---|---|
| Base schema | 29 | 16 |
| All 30 migrations (tables, indexes, `ALTER`s) | 117 | 35 |
| The four subscription plans (one multi-row insert) | 1 | — |
| `schema_migrations` table + its 31 rows | 2 | 1 |

It creates **52 tables**, seeds the four plans, and creates **no clinic, no
patient and no user** — an install with demo data in it is not a production
install.

**Verify, in phpMyAdmin → SQL:**

```sql
SELECT COUNT(*) FROM information_schema.tables
 WHERE table_schema = DATABASE();   -- expect 52
SELECT COUNT(*) FROM plans;              -- expect 4
SELECT COUNT(*) FROM schema_migrations;  -- expect 31
```

The `schema_migrations` rows matter: without them `tools/migrate.php` re-applies
every migration on your next deploy and dies on the first duplicate column.

> **Re-running it is safe but pointless.** Every `CREATE TABLE` is
> `IF NOT EXISTS` and the plan insert is `INSERT … ON DUPLICATE KEY UPDATE`, but
> the `ALTER TABLE` statements will abort on a database that already has the
> columns — which is the correct outcome, because it stops rather than
> half-applying.

### Keeping install.sql honest

It is generated, never hand-edited. After adding a migration:

```bash
php tools/build-install-sql.php      # regenerate
python3 tools/sql-validate.py database/install.sql
```

`php tools/build-install-sql.php --check` exits non-zero when the file is stale,
and the test suite fails if it is older than the newest migration — so a
migration added without rebuilding cannot ship.

### What has been verified, and what has not

`tools/sql-validate.py` parses every statement with a real MySQL grammar and
replays the file against a model of the database: it checks that every `ALTER`
targets a table that exists, every `FOREIGN KEY` names a created table and
column, and that no `CREATE TABLE` is missing its guard. It currently reports
**no problems** across all 149 statements and 52 tables.

It cannot tell you whether a real MySQL will accept a construct. **No part of
this project has ever been executed against MySQL** — there is no MySQL server in
CI. Run `php tools/migrate.php` (or import this file) on the target host and
watch it finish; that is the only proof, and it takes a minute.

## A6. Create your platform admin

The four subscription plans are created for you: `php tools/migrate.php` seeds them
itself and prints `Seeded 4 subscription plan(s); 4 now available.` There is nothing
else to run for plans, on either install path — `database/install.sql` already
contains them.

If you ever need to re-seed them, or you imported the schema by hand:

```bash
cd ~/queuepilot
php tools/seed.php --plans-only
```

`--plans-only` is the production-safe seeder: the four plans and **no demo data**.
Do not run `php tools/seed.php` without that flag on a live install — the full
seeder creates five fake clinics, fake patients and a suspended billing case.

Then create the account you will actually sign in with:

```bash
php tools/make-admin.php --email=you@yourdomain.lk --name="Your Name"
```

It prints a one-time password once — copy it:

```
  ONE-TIME PASSWORD:   Copper-lantern-meadow-4813

  Sign in at:  https://yourdomain.lk/login
  Console:    https://yourdomain.lk/admin
```

That password is temporary. You are asked to change it on first sign-in, and as a
platform admin you must enrol an authenticator app before any admin screen opens.

**Do not use the full seeder to get a login on production.** `make-admin.php` exists
precisely so you don't have to.

## A7. Cron

Shared hosting kills long-running processes, so everything is a one-shot script.
cPanel → **Cron Jobs** → add these. Use the full PHP path from cPanel's "Common
Settings" dropdown:

```cron
*/2 * * * * cd /home/USER/queuepilot && /usr/local/bin/php tools/queue-worker.php >> storage/logs/worker.log 2>&1
*/30 * * * * cd /home/USER/queuepilot && /usr/local/bin/php tools/invoices.php --yes >> storage/logs/invoices.log 2>&1
30 3 * * * cd /home/USER/queuepilot && /usr/local/bin/php tools/trials.php --yes >> storage/logs/trials.log 2>&1
15 2 * * * cd /home/USER/queuepilot && /usr/local/bin/php tools/backup.php >> storage/logs/backup.log 2>&1
0 4 * * * cd /home/USER/queuepilot && /usr/local/bin/php tools/purge.php --dry-run >> storage/logs/purge.log 2>&1
```

| Job | What breaks without it |
|---|---|
| `queue-worker.php` | No SMS or WhatsApp ever leaves. The outbox fills up and the health page reports `critical`. |
| `invoices.php` | No invoice is ever raised, so no clinic is ever billed. |
| `trials.php` | A trial never ends. Expiry is a scheduled decision, not a request-time one. |
| `backup.php` | No backups. |
| `purge.php --dry-run` | Nothing — deliberately. It only *reports* what a human could approve for deletion. |

`purge.php` is `--dry-run` on purpose. Deleting a clinic is irreversible and
requires a person to approve it first (`tools/approve-purge.php`); the daily job
exists only to tell you who has been closed long enough to be a candidate.

## A8. Verify

```bash
php tools/healthcheck.php     # database, schema parity, plans, config
php tools/host-drill.php      # 24 checks: keys, sessions, storage, migrations, debug
php tools/mail-test.php you@yourdomain.lk   # send a real test email
```

Then in a browser:

| Check | Expected |
|---|---|
| `https://yourdomain.lk/` | Landing page |
| `https://yourdomain.lk/login` | Sign-in form |
| Sign in with the one-time password | Password change, then 2FA enrolment, then `/admin` |
| `https://yourdomain.lk/.env` | **404** |

## A9. cPanel-specific traps

- **LiteSpeed Cache / cPanel cache.** If caching is on, exclude `/app`, `/admin`,
  `/settings`, `/patients`, `/queue`, `/reports`, `/billing`, `/login` and every
  `/api/` path. A cached dashboard is one clinic's patient list served to another.
  Only `/assets/` and the landing page are safe to cache.
- **`DB_HOST` must be `localhost`.** cPanel grants database privileges per host; a
  remote host needs an allowlist entry added first.
- **Prefix everything.** cPanel will not let you create a database or user without
  its account prefix. Put the *prefixed* names in `.env`.
- **ModSecurity.** Some hosts' rule sets block CSV/PDF downloads and POST bodies.
  If exports fail with 403, ask support to disable it for the domain.
- **No `putenv()` across requests.** `.env` is read on every request, so editing it
  takes effect immediately. There is no restart.
- **File upload size.** phpMyAdmin importing 29 SQL files is easier than raising
  `upload_max_filesize`.

---

# Part B — XAMPP on your own PC

Windows, macOS or Linux. This section gets you a working local copy with MySQL and
phpMyAdmin, which is the closest thing to the real host.

## B1. PHP version

XAMPP ships whichever PHP it ships. **You need 8.1+.**

```
C:\xampp\php\php.exe -v
```

If it is older, install a newer XAMPP. The app will not parse on 7.4 — it uses
`readonly` properties and `match`, both 8.0+.

## B2. Put the app somewhere XAMPP can serve

The trap: XAMPP's document root is `C:\xampp\htdocs`, and everything under it is
web-reachable, including `.env`.

**The clean way** — mimic the cPanel layout so what you practise locally is what
you deploy:

```
C:\xampp\queuepilot\          ← the application (NOT inside htdocs)
  app/ database/ resources/ storage/ tools/ config/ public/ .env

C:\xampp\htdocs\queuepilot\   ← the site root
  index.php                   ← requires ../../queuepilot/public/index.php
  .htaccess
  assets/                     ← copy of ../../queuepilot/public/assets
```

`C:\xampp\htdocs\queuepilot\index.php`:

```php
<?php
require __DIR__ . '/../../queuepilot/public/index.php';
```

Copy `public/.htaccess` into `htdocs\queuepilot\.htaccess`, and copy
`public\assets` to `htdocs\queuepilot\assets`.

Then browse to **http://localhost/queuepilot/**.

> **Not sure whether `mod_rewrite` is on?** In XAMPP it normally is. If deep links
> 404 but the home page works, uncomment `LoadModule rewrite_module modules/mod_rewrite.so`
> in `C:\xampp\apache\conf\httpd.conf` and restart Apache.

**The quick way** (fine for a throwaway local test, not for anything else): point a
VirtualHost's `DocumentRoot` at `C:\xampp\queuepilot\public`. Change
`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

```apache
<VirtualHost *:80>
    ServerName queuepilot.local
    DocumentRoot "C:/xampp/queuepilot/public"

    <Directory "C:/xampp/queuepilot/public">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

With a VirtualHost the app sits at the domain root, so `APP_URL=http://queuepilot.local`
with no path — and `asset()` emits plain `/assets/...` as it does in production.
This is the layout the production host uses.

Then add to `C:\Windows\System32\drivers\etc\hosts` (as Administrator):

```
127.0.0.1   queuepilot.local
```

Set `APP_URL=http://queuepilot.local` and browse there. This is the closest local
match to production and avoids the subfolder path entirely.

## B3. Start Apache and MySQL

XAMPP Control Panel → **Start** Apache → **Start** MySQL.

If MySQL refuses to start, port 3306 is already taken (often by an installed MySQL
service). Either stop that service, or change XAMPP's port and update `DB_PORT`.

## B4. Create the database in phpMyAdmin

1. Open **http://localhost/phpmyadmin**.
2. **Databases** tab → name: `queuepilot` → **Create**.
   Locally there are no prefixes — the name is exactly `queuepilot`.
3. You do **not** need to create a user. XAMPP's MySQL ships with `root` and an
   **empty password**. `.env` will use that locally.

Now you have a choice. Pick one:

### B4a. Import with phpMyAdmin (no command line)

1. Select the `queuepilot` database.
2. **Import** tab → **Choose File** → `C:\xampp\queuepilot\database\install.sql`
   → **Go**. Wait for "Import has been successfully finished".
3. Skip to **B5**. That one file creates all 52 tables, seeds the four plans, and
   records the 31 applied migrations.

Do **not** import `database/schema/mysql.sql` on its own. It is 16 tables; the
other 36 come from the migrations, and an app on the bare schema throws on the
first request. `install.sql` already contains it.

### B4b. Import with the command line (easier, and what production does)

XAMPP has a real PHP CLI. **This is the better path** — it runs the same code the
cPanel host runs, so a mistake shows up here rather than there.

First create `.env` (copy `.env.example`), then:

```cmd
cd C:\xampp\queuepilot
C:\xampp\php\php.exe tools\migrate.php
```

On macOS/Linux:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/../queuepilot   # wherever you put it
/Applications/XAMPP/xamppfiles/bin/php tools/migrate.php
```

That single command applies the schema and all 29 migrations in the right order and
records them. Prefer this.

## B5. `.env` for XAMPP

```ini
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost/queuepilot
APP_TIMEZONE=Asia/Colombo
APP_NAME=QueuePilot

DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=queuepilot
DB_USERNAME=root
DB_PASSWORD=

SESSION_PATH=storage/sessions

APP_KEY=
MAIL_TRANSPORT=log
SECURITY_REQUIRE_2FA_SUPER=true
SMS_GATEWAY=log
WHATSAPP_TRANSPORT=log
```

Notes for local:

- **`APP_DEBUG=true` locally, `false` on the host.** Debug prints stack traces,
  queries and file paths — useful here, a leak there.
- **`MAIL_TRANSPORT=log`** writes every email to `storage/logs/mail.log` instead of
  sending it. You can read the welcome link, the invoice and the receipt without an
  SMTP account. This is the fastest way to inspect what the app actually sends.
- **`APP_KEY` is still needed** even though `APP_ENV=local` — 2FA enrolment encrypts
  with it. Generate one:

  ```cmd
  C:\xampp\php\php.exe -r "echo 'APP_KEY=' . bin2hex(random_bytes(32)) . PHP_EOL;"
  ```

  Paste the output into `.env`.
- **Never use `root` with no password on the real host.** This is a local-only
  convenience.

## B6. Create the plans and your admin

```cmd
cd C:\xampp\queuepilot

:: The four subscription plans, no demo data — same as production.
C:\xampp\php\php.exe tools\seed.php --plans-only

:: Your platform admin. Prints a one-time password.
C:\xampp\php\php.exe tools\make-admin.php --email=you@example.com --name="Your Name"
```

Or, if you want a full demo dataset to click around in — **local only**:

```cmd
C:\xampp\php\php.exe tools\seed.php
```

That creates three demo clinics with patients, queues and billing states, and prints:

```
 ROLE                   EMAIL                      PASSWORD
 Platform owner         owner@queuepilot.app       QueuePilot#2026
 Clinic admin           admin@serenity.lk          Serenity#2026
 Receptionist           reception@serenity.lk      Reception#2026
 Doctor                 amara@serenity.lk          Doctor#2026
 Second clinic          admin@lakeview.lk          Lakeview#2026
 Suspended clinic       admin@coastal.lk           Coastal#2026
```

You can also run these from a browser instead of a terminal — XAMPP's PHP works
fine over HTTP — but the CLI is less trouble.

## B7. Verify

Open **http://localhost/queuepilot/** (or `http://queuepilot.local/`).

| Check | Expected |
|---|---|
| `/` | Landing page with pricing |
| `/login` | Sign-in form |
| Sign in as your admin | Forced password change → 2FA enrolment → `/admin` |
| `/register` | Create a test clinic; then approve it in `/admin/clinics` |
| `/.env` | **404** — if it downloads, your document root is wrong |

Then from the command line:

```cmd
C:\xampp\php\php.exe tools\healthcheck.php
C:\xampp\php\php.exe tools\host-drill.php
```

`host-drill.php` will *fail* two checks locally, and that is correct: it expects
production hardening (`APP_DEBUG=false`, a non-world-readable session directory).
Read the failure text rather than trying to silence it.

## B8. XAMPP-specific traps

- **`APP_URL` with a subfolder.** If you serve from `http://localhost/queuepilot`,
  `APP_URL` must include `/queuepilot`, exactly and without a trailing slash:
  `APP_URL=http://localhost/queuepilot`. A bare `http://localhost` makes every
  generated link land on the XAMPP dashboard. Asset URLs follow `APP_URL`'s path
  automatically, so setting this one value fixes stylesheets as well as links —
  if a page renders unstyled, `APP_URL` is wrong.
- **MySQL 8 authentication.** If you create a *user* (rather than using `root`) and
  PHP cannot connect with `caching_sha2_password`, either use `root` locally or
  switch that user to `mysql_native_password`.
- **`storage/sessions` on Windows.** Created automatically. If you see "signed out
  immediately" loops, check the folder exists and Apache can write to it — on Windows
  the permissions usually come from the folder, not from `chmod`.
- **Port 80 in use.** Skype, IIS and some VPN clients grab it. Change Apache's port
  in `httpd.conf` and update `APP_URL`.
- **Do not commit `.env`.** It is git-ignored. Keep it that way.

---

## After installing — the first ten minutes

1. Sign in at `/login`. Change the password. Enrol 2FA.
2. `/admin` → confirm the console loads and reads "0 things waiting on you".
3. Apply for a clinic through `/register` with a second email address.
4. `/admin` should now show **"1 thing waiting on you"** — that is the signup alert.
5. Approve it or grant a 14-day trial. The welcome email (or `storage/logs/mail.log`)
   should contain **three** URLs: the patient board, the public page, and the staff
   sign-in link.
6. Open the patient board URL. It should show the clinic's queue.
7. Run `php tools/host-drill.php` once more.

If step 4 shows nothing, the alert failed — check `MAIL_TRANSPORT` and
`COMPANY_ALERTS_EMAIL`. The in-app banner should still appear regardless of mail.

---

## Troubleshooting

| Symptom | Cause |
|---|---|
| **500 on every page** | `APP_KEY` missing in production (deliberate), or `storage/` not writable. Read `storage/logs/app.log`. |
| **Blank page / no error** | `APP_DEBUG=false` hides it. Set `true` temporarily, reproduce, set it back. |
| **"Table not found"** | Migrations not run. `php tools/migrate.php`. The bare schema is 16 tables; you need 52. |
| **Redirected to `/login` in a loop** | Session directory not writable, or cookies rejected. Check `SESSION_PATH`, and that `APP_URL` matches the URL in the browser exactly (http vs https, trailing path). |
| **Deep links 404, home page works** | `mod_rewrite` off, or `.htaccess` not in the document root. |
| **`GET /.env` returns text** | Document root is wrong. Stop and fix it. |
| **No emails** | `MAIL_TRANSPORT=none`. Set `smtp` and run `php tools/mail-test.php you@example.com`. |
| **"No such file: storage/logs/…"** | `storage/logs` missing. It ships with a `.gitignore` inside; create the directory if your upload dropped empty folders. |
| **Health page says `critical`** | The queue worker has not run. Check the cron line, and that the path to PHP is the full one from cPanel. |
| **cannot log in as platform admin** | `php tools/make-admin.php --list`. If empty, create one. If 2FA is lost: `--reset`. |
| **Clinic owner never got the welcome link** | Mail not configured. Copy the link from `/admin/clinics` → the clinic row. |

## Upgrading an existing install

```bash
cd ~/queuepilot
php tools/backup.php          # take a backup FIRST
# upload the new code over the old (do not touch .env or storage/)
php tools/migrate.php         # applies only what is new
php tools/healthcheck.php
php tools/host-drill.php
```

Migrations are idempotent and recorded, so re-running is safe. Never edit a migration
that has already been applied — add a new one.

## What is deliberately not automated

- **Approving a clinic.** A person decides. That is the requirement, not an oversight.
- **Matching a bank transfer.** Invoices say exactly where to pay; a human checks the
  bank and verifies. No gateway.
- **Deleting a clinic's data.** 90 days closed → email, then phone → a human approves
  with `tools/approve-purge.php`. Nothing is deleted on a timer.
- **Sending SMS** unless you configure `SMS_GATEWAY` and a provider. The default
  (`log`) cannot message a real patient by accident.
