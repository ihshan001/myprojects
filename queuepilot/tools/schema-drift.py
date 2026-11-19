#!/usr/bin/env python3
"""
Check that the two dialect schemas describe the same database.

Every migration ships as a pair — foo.sqlite.sql and foo.mysql.sql — because the
test suite runs SQLite and the clinic's host runs MySQL. Nothing executes the
MySQL half in CI, so if the two halves disagree about a column, the difference
is invisible until the code that needs it runs on a live host.

That is not hypothetical. 0003_operational_depth.sqlite.sql has carried
`queue_entries.approach_notified_at` since it was written; the MySQL half never
added it. NotificationService::notifyApproaching() selects and updates that
column, so on MySQL every queue-worker run for a clinic that had switched the
"your turn is approaching" option on threw — and the option is a checkbox on
the settings screen, so it was one click away.

This reports a column that exists in one dialect and not the other, and nothing
else: the two are allowed to differ in *how* a thing is expressed (partial
index vs generated column, TEXT vs VARCHAR, CHECK vs trigger), and they must
not differ in *whether* the thing exists.

    python3 tools/schema-drift.py
"""
import glob
import re
import sys

ROOT = __file__.rsplit('/tools/', 1)[0]

# A column mentioned in the SQL but not really a column: constraint clauses and
# the generated columns MySQL uses where SQLite uses a partial index.
CONSTRAINT_CLAUSE = re.compile(
    r'^\s*(PRIMARY\s+KEY|UNIQUE|CONSTRAINT|FOREIGN\s+KEY|INDEX|KEY|CHECK)\b', re.I)
# Words the body parser picks up out of REFERENCES/ON DELETE clauses.
NOISE = {'references', 'constraint', 'unique'}

# MySQL cannot express a partial unique index (`... WHERE status IN (...)`) the
# way SQLite can, so it uses a stored generated column that collapses to NULL
# once the row leaves the active window, plus a plain unique index over it.
# Those generated columns are a MySQL implementation detail with no SQLite
# counterpart and must not be reported as drift. They are detected by the
# GENERATED ALWAYS clause in the DDL rather than by name, so a new one is
# handled automatically.
GENERATED = re.compile(r'GENERATED\s+ALWAYS', re.I)


def strip_sql(sql: str) -> str:
    sql = re.sub(r'/\*.*?\*/', '', sql, flags=re.S)
    return re.sub(r'--[^\n]*', '', sql)


def columns_by_table(dialect: str, generated: set):
    sql = strip_sql(open(f'{ROOT}/database/schema/{dialect}.sql').read())
    for path in sorted(glob.glob(f'{ROOT}/database/migrations/*.{dialect}.sql')):
        sql += '\n' + strip_sql(open(path).read())

    tables = {}
    for m in re.finditer(
            r'CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\n\)',
            sql, re.S | re.I):
        name, body = m.group(1).lower(), m.group(2)
        tables.setdefault(name, set())
        for line in body.split('\n'):
            line = line.strip().rstrip(',')
            if not line or CONSTRAINT_CLAUSE.match(line):
                continue
            col = re.match(r'[`"]?(\w+)[`"]?\s+[A-Za-z]', line)
            if col:
                tables[name].add(col.group(1).lower())

    # SQLite cannot alter a CHECK constraint, so a migration rebuilds the table
    # as `<name>_new` and renames it over the original. The columns therefore
    # live under the staging name, which is not a real table: follow the rename
    # so the staging table's columns land on the table they become.
    for m in re.finditer(
            r'ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+RENAME\s+TO\s+[`"]?(\w+)[`"]?',
            sql, re.I):
        staging, real = m.group(1).lower(), m.group(2).lower()
        if staging in tables:
            tables.setdefault(real, set()).update(tables.pop(staging))

    for m in re.finditer(r'ALTER\s+TABLE\s+[`"]?(\w+)[`"]?\s+(.*?);', sql, re.S | re.I):
        name = m.group(1).lower()
        body = m.group(2)
        if re.match(r'\s*RENAME\s+TO\b', body, re.I):
            continue  # already handled above
        for col in re.finditer(r'ADD\s+(?:COLUMN\s+)?[`"]?(\w+)[`"]?\s+[A-Za-z]', m.group(2), re.I):
            word = col.group(1).lower()
            if word in ('column', 'key', 'index', 'constraint', 'unique', 'primary',
                        'foreign', 'check'):
                continue
            tables.setdefault(name, set()).add(word)
            # A generated column is dialect machinery, not missing schema.
            tail = m.group(2)[col.start():col.start() + 400]
            if GENERATED.search(tail.split(';')[0]):
                generated.add((name, word))
    return tables


def main() -> int:
    sqlite_generated: set = set()
    mysql_generated: set = set()
    sqlite = columns_by_table('sqlite', sqlite_generated)
    mysql = columns_by_table('mysql', mysql_generated)
    # A table that exists only as a rebuild staging name.
    sqlite.pop('queue_entries_new', None)
    mysql.pop('queue_entries_new', None)

    problems = []

    for name in sorted(set(sqlite) - set(mysql)):
        problems.append(f'{name}: table exists in SQLite but not MySQL')
    for name in sorted(set(mysql) - set(sqlite)):
        problems.append(f'{name}: table exists in MySQL but not SQLite')

    for name in sorted(set(sqlite) & set(mysql)):
        only_sqlite = sorted(sqlite[name] - mysql[name] - NOISE)
        only_mysql = sorted(
            c for c in mysql[name] - sqlite[name] - NOISE
            if (name, c) not in mysql_generated
        )
        for col in only_sqlite:
            problems.append(f'{name}.{col}: in SQLite, never added on MySQL')
        for col in only_mysql:
            problems.append(f'{name}.{col}: in MySQL, never added on SQLite')

    print(f'Schema drift — {len(sqlite)} tables, '
          f'{sum(len(c) for c in sqlite.values())} SQLite columns vs '
          f'{sum(len(c) for c in mysql.values())} MySQL columns')
    print('-' * 60)
    if problems:
        print(f'\033[31m{len(problems)} problem(s)\033[0m:')
        for p in problems:
            print(f'  · {p}')
        print('\nA column the app queries and one dialect does not have fails '
              'on the host, not in the suite.')
        return 1
    print('\033[32mOK\033[0m — both dialects describe the same tables and columns.')
    return 0


if __name__ == '__main__':
    sys.exit(main())
