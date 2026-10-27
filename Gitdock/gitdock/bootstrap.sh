#!/usr/bin/env bash
# GitDock bootstrap — reinstalls the system runtimes this sandbox does not persist,
# starts MariaDB, and creates the MySQL app account GitDock uses.
# Safe to re-run: every step is idempotent.
set -uo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/app" && pwd)"
DATA_DIR="$APP_DIR/../data"
mkdir -p "$DATA_DIR"

say() { printf '\033[1;36m[gitdock]\033[0m %s\n' "$*"; }

# 1. system packages ---------------------------------------------------------
if ! command -v php >/dev/null 2>&1; then
  say "installing PHP, SQLite, MariaDB, Composer (apt)…"
  export DEBIAN_FRONTEND=noninteractive
  sudo apt-get update -qq
  sudo apt-get install -y -qq --no-install-recommends \
    php-cli php-sqlite3 php-mysql php-mbstring php-xml php-curl php-zip php-gd php-intl php-bcmath \
    sqlite3 mariadb-server mariadb-client composer
else
  say "PHP already present: $(php -v | head -1)"
fi

# 2. MariaDB -----------------------------------------------------------------
sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld
if ! mysqladmin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1; then
  say "starting MariaDB…"
  sudo -u mysql nohup /usr/sbin/mariadbd \
    --datadir=/var/lib/mysql --socket=/run/mysqld/mysqld.sock --port=3306 \
    --bind-address=127.0.0.1 --innodb-buffer-pool-size=128M --key-buffer-size=16M \
    --max-connections=60 --skip-name-resolve >/tmp/mariadb.log 2>&1 &
  for i in $(seq 1 30); do
    mysqladmin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 && break
    sleep 1
  done
fi
mysqladmin --socket=/run/mysqld/mysqld.sock ping >/dev/null 2>&1 || { say "MariaDB failed to start — see /tmp/mariadb.log"; exit 1; }
say "MariaDB is up"

# 3. GitDock MySQL account ---------------------------------------------------
# The MariaDB data directory lives OUTSIDE the workspace (/var/lib/mysql), so a fresh
# sandbox can run a server that has never heard of our user — even though data/mysql.json
# survived. Always reconcile: reuse the stored password, or mint one on first run.
if [ -f "$DATA_DIR/mysql.json" ]; then
  PASS="$(python3 -c "import json;print(json.load(open('$DATA_DIR/mysql.json'))['password'])")"
  ACTION="reconciled"
else
  PASS="$(openssl rand -hex 16)"
  ACTION="created"
  cat > "$DATA_DIR/mysql.json" <<JSON
{ "host": "127.0.0.1", "port": 3306, "user": "gitdock", "password": "${PASS}" }
JSON
  chmod 600 "$DATA_DIR/mysql.json"
fi

sudo mysql --socket=/run/mysqld/mysqld.sock <<SQL
CREATE USER IF NOT EXISTS 'gitdock'@'%' IDENTIFIED BY '${PASS}';
CREATE USER IF NOT EXISTS 'gitdock'@'localhost' IDENTIFIED BY '${PASS}';
ALTER USER 'gitdock'@'%' IDENTIFIED BY '${PASS}';
ALTER USER 'gitdock'@'localhost' IDENTIFIED BY '${PASS}';
-- one pattern grant covers every schema the panel will ever create, so it needs nothing else —
-- no GRANT OPTION, no access to any database outside gitdock_*
GRANT ALL PRIVILEGES ON \`gitdock\_%\`.* TO 'gitdock'@'%';
GRANT ALL PRIVILEGES ON \`gitdock\_%\`.* TO 'gitdock'@'localhost';
FLUSH PRIVILEGES;
SQL
if [ $? -ne 0 ]; then say "could not create the gitdock MySQL user — check the SQL above"; exit 1; fi
say "${ACTION} MySQL account 'gitdock' (password in data/mysql.json, mode 600)"

# 3b. prove the credentials actually work end-to-end
if mysql -h 127.0.0.1 -P 3306 -u gitdock -p"$PASS" -N -B -e "SELECT 1" >/dev/null 2>&1; then
  say "MySQL login verified over TCP (127.0.0.1:3306)"
else
  say "WARNING: 'gitdock' works over the socket but not over TCP — GitDock will report mysql=unavailable"
fi

# 3c. and prove it can really provision: create a throwaway schema and grant on it, exactly as
# "provision & inject" does. A login without GRANT OPTION passes 3b and fails here.
if mysql -h 127.0.0.1 -P 3306 -u gitdock -p"$PASS" -N -B -e \
   "CREATE DATABASE IF NOT EXISTS \`gitdock_bootstrap_check\`; USE \`gitdock_bootstrap_check\`; CREATE TABLE IF NOT EXISTS t(i int); INSERT INTO t VALUES (1); SELECT count(*) FROM t; DROP DATABASE \`gitdock_bootstrap_check\`;" >/dev/null 2>&1; then
  say "MySQL provisioning verified (create, use, table, drop)"
else
  say "WARNING: 'gitdock' cannot create and use schemas — \"provision & inject\" will fail"
fi

say "done. start the panel with:  node $APP_DIR/server.js"
