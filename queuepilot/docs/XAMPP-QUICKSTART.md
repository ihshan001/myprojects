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
 WHERE table_schema = DATABASE();   -- must be 52
SELECT COUNT(*) FROM plans;              -- must be 4
SELECT COUNT(*) FROM schema_migrations;  -- must be 31
```

If `schema_migrations` is 0 or missing, the import was partial — drop the database,
recreate it, and import again.

---

## 4. Create `.env`

Copy `.env.example` to `.env` in the app folder and edit it. On Windows:

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

---

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

`host-drill.php` will report failures locally, and that is **correct** — it checks
production hardening (`APP_DEBUG=false`, non-world-readable sessions). Read the
failure text; do not try to make it green on a development machine.

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

---

## What this guide cannot promise

Being straight with you, because it matters more than a green checklist:

- **`install.sql` has never been executed against a real MySQL server.** There is no
  MySQL in the environment it was built in. It is validated statically — every
  statement parses as MySQL, every `ALTER` targets an existing table, every foreign
  key points at a real table and column, both SQL dialects describe the same 52
  tables — but only a real server proves the server accepts every construct.
  **Your import in step 3 is that proof.** If it fails, the error message from
  phpMyAdmin is the thing to send back.
- **The `.htaccess` rewrite has not been executed under Apache** either, for the same
  reason. Step 6's four checks are the test.
- **Bank details are blank** on invoices by design. Until you set `COMPANY_BANK`,
  `COMPANY_BANK_ACCOUNT_NO` and friends in `.env`, invoices and their emails say
  "our bank details are not on this installation yet" rather than inventing them.

If step 3's import or step 6's checks fail, paste the exact error and it can be
fixed against the real message rather than a guess.
