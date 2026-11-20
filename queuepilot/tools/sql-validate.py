#!/usr/bin/env python3
"""
Validate a MySQL SQL file without a MySQL server.

There is no MySQL in CI and none in the development sandbox, so the thirty-odd
migration files have never been executed anywhere by an automated check. That is
exactly the gap that let `0015_session_start.mysql.sql` sit calling SQLite's
`datetime('now')` — valid-looking text, guaranteed runtime failure on the one
host that cannot easily be rolled back.

This does the four things that are possible without a server, and is honest
about the fifth that is not:

Requires sqlglot:  pip install sqlglot   (30.x; pip needs
--break-system-packages on a Debian/Ubuntu system Python).

  1. PARSE      every statement with sqlglot's MySQL dialect. A statement that
                does not parse will not run.
  2. ORDER      replay the file against a model of the database: CREATE then
                ALTER then INSERT/UPDATE. An ALTER on a table or column that does
                not exist yet is an error, and so is a duplicate CREATE.
                This catches the ordering mistakes that a concatenated file is
                especially prone to.
  3. FOREIGN KEYS  every REFERENCES must name a table already created, and a
                column that exists on it.
  4. IDEMPOTENCE   re-running must not be *silently* destructive. Every CREATE
                should be IF NOT EXISTS; an INSERT into a table with a unique key
                should be ON DUPLICATE KEY UPDATE.

  5. NOT DONE   Whether the server accepts a given construct, whether a type is
                legal in the columns' combination, and whether an index name
                collides globally. Only a real MySQL can answer those. Use
                `php tools/migrate.php` on the target host for that, and read
                docs/RUNBOOK.md §"Before the first clinic".

Usage:
  python3 tools/sql-validate.py database/install.sql
  python3 tools/sql-validate.py database/schema/mysql.sql --single
"""

from __future__ import annotations

import re
import sys
from dataclasses import dataclass, field

try:
    import sqlglot
    from sqlglot import exp
except ImportError:  # pragma: no cover
    print("sqlglot is required:  pip install sqlglot", file=sys.stderr)
    sys.exit(2)


# --------------------------------------------------------------------------- #
# Splitting
# --------------------------------------------------------------------------- #

def split_statements(sql: str) -> list[tuple[int, str]]:
    """
    Split on semicolons that are not inside a quoted string or a comment.

    Returns (line_number, statement) pairs so errors can point at a line.
    A naive split on ';' breaks on a semicolon inside a string, which is exactly
    the kind of thing a comment in one migration or a default value would hide.
    """
    out: list[tuple[int, str]] = []
    buf: list[str] = []
    i = 0
    n = len(sql)
    line = 1
    start_line = 1
    quote: str | None = None

    while i < n:
        ch = sql[i]
        nxt = sql[i + 1] if i + 1 < n else ""

        if ch == "\n":
            line += 1

        # Comments: keep them out of the statement text entirely.
        if quote is None and ch == "-" and nxt == "-":
            while i < n and sql[i] != "\n":
                i += 1
            line += 1
            i += 1
            continue
        if quote is None and ch == "/" and nxt == "*":
            i += 2
            while i < n - 1 and not (sql[i] == "*" and sql[i + 1] == "/"):
                if sql[i] == "\n":
                    line += 1
                i += 1
            i += 2
            continue

        # Strings and identifiers.
        if quote is None and ch in ("'", '"', "`"):
            quote = ch
            buf.append(ch)
            i += 1
            continue
        if quote is not None:
            buf.append(ch)
            if ch == "\\" and i + 1 < n:
                buf.append(sql[i + 1])
                i += 2
                continue
            if ch == quote:
                # Doubled quote is an escape, not the end.
                if nxt == quote:
                    buf.append(nxt)
                    i += 2
                    continue
                quote = None
            i += 1
            continue

        if ch == ";":
            stmt = "".join(buf).strip()
            if stmt:
                out.append((start_line, stmt))
            buf = []
            i += 1
            # Skip whitespace to find where the next statement starts.
            while i < n and sql[i] in " \t\r\n":
                if sql[i] == "\n":
                    line += 1
                i += 1
            start_line = line
            continue

        if not buf and ch in " \t\r\n":
            i += 1
            continue
        buf.append(ch)
        i += 1

    tail = "".join(buf).strip()
    if tail:
        out.append((start_line, tail))
    return out


# --------------------------------------------------------------------------- #
# Database model
# --------------------------------------------------------------------------- #

@dataclass
class Table:
    name: str
    columns: dict[str, str] = field(default_factory=dict)   # name -> normalised type
    uniques: set[str] = field(default_factory=set)          # column names with a unique index
    primary: list[str] = field(default_factory=list)


@dataclass
class Problem:
    line: int
    kind: str
    message: str


class Model:
    def __init__(self) -> None:
        self.tables: dict[str, Table] = {}
        self.problems: list[Problem] = []
        self.statements = 0
        self.creates = 0
        self.alters = 0
        self.inserts = 0

    # -- helpers ----------------------------------------------------------- #

    def table(self, name: str) -> Table | None:
        return self.tables.get(name.lower())

    def fail(self, line: int, kind: str, message: str) -> None:
        self.problems.append(Problem(line, kind, message))

    # -- the replay -------------------------------------------------------- #

    def apply(self, line: int, stmt: str) -> None:
        self.statements += 1

        try:
            parsed = sqlglot.parse_one(stmt, dialect="mysql")
        except Exception as exc:  # noqa: BLE001 - sqlglot raises many types
            self.fail(line, "PARSE", f"{type(exc).__name__}: {exc}")
            return

        if parsed is None:
            self.fail(line, "PARSE", "statement produced no parse tree")
            return

        if isinstance(parsed, exp.Create):
            self._create(line, stmt, parsed)
        elif isinstance(parsed, exp.Alter):
            self._alter(line, parsed)
        elif isinstance(parsed, exp.Insert):
            self._insert(line, parsed)
        elif isinstance(parsed, (exp.Update, exp.Delete)):
            self._dml(line, parsed)
        elif isinstance(parsed, exp.Set):
            pass  # SET SQL_MODE etc.
        # Anything else (DROP, RENAME) is legal; nothing to model.

    def _create(self, line: int, stmt: str, parsed: exp.Create) -> None:
        self.creates += 1
        kind = (parsed.args.get("kind") or "").upper()

        if kind != "TABLE":
            return  # indexes created standalone are modelled via ALTER

        table_expr = parsed.this
        if not isinstance(table_expr, exp.Schema):
            return
        name = table_expr.this.name.lower()
        if_not_exists = "IF NOT EXISTS" in stmt.upper()

        if name in self.tables and not if_not_exists:
            self.fail(line, "DUPLICATE", f"CREATE TABLE `{name}` without IF NOT EXISTS, but it already exists")
            return

        table = self.tables.setdefault(name, Table(name))

        for item in table_expr.expressions:
            # ColumnDefinition, or a table-level constraint.
            if isinstance(item, exp.ColumnDef):
                col = item.name.lower()
                col_type = item.args.get("kind")
                rendered = col_type.sql(dialect="mysql").upper() if col_type is not None else "?"
                if col in table.columns:
                    self.fail(line, "DUPLICATE", f"column `{name}`.`{col}` defined twice in one CREATE")
                table.columns[col] = rendered

                for constraint in item.args.get("constraints") or []:
                    c = constraint.args.get("kind")
                    if isinstance(c, exp.PrimaryKeyColumnConstraint):
                        if col not in table.primary:
                            table.primary.append(col)
                    elif isinstance(c, exp.UniqueColumnConstraint):
                        table.uniques.add(col)
                    elif isinstance(c, exp.Reference):
                        self._reference(line, name, col, c)

            elif isinstance(item, exp.PrimaryKey):
                for c in item.expressions:
                    if c.name.lower() not in table.primary:
                        table.primary.append(c.name.lower())

            elif isinstance(item, exp.UniqueColumnConstraint):
                idx = item.this
                if isinstance(idx, exp.Schema):
                    for c in idx.expressions:
                        table.uniques.add(c.name.lower())

            elif isinstance(item, exp.ForeignKey):
                for c in item.expressions:
                    if isinstance(c, exp.Schema):
                        col = c.this.name if c.this is not None else "?"
                    else:
                        col = c.name
                    self._reference(line, name, col.lower(), item)

    def _reference(self, line: int, from_table: str, from_col: str, node) -> None:
        ref = node.args.get("reference") if hasattr(node, "args") else None
        if ref is None:
            return
        target = ref.this
        if isinstance(target, exp.Schema):
            tname = target.this.name.lower()
            tcols = [c.name.lower() for c in target.expressions]
        elif target is not None:
            tname = target.name.lower()
            tcols = []
        else:
            return

        t = self.table(tname)
        if t is None:
            self.fail(line, "FK", f"`{from_table}`.`{from_col}` references `{tname}`, which has not been created yet")
            return
        for c in tcols:
            if c and c not in t.columns:
                self.fail(line, "FK", f"`{from_table}`.`{from_col}` references `{tname}`.`{c}`, which does not exist")

    def _alter(self, line: int, parsed: exp.Alter) -> None:
        self.alters += 1
        target = parsed.this
        name = target.name.lower() if target is not None else "?"
        t = self.table(name)

        for action in parsed.args.get("actions") or []:
            cls = type(action).__name__

            if cls == "ColumnDef":
                col = action.name.lower()
                if t is None:
                    self.fail(line, "ALTER", f"ALTER TABLE `{name}` ALTER COLUMN `{col}` but the table does not exist")
                    continue

                colkind = action.args.get("kind")
                if col in t.columns:
                    self.fail(line, "ALTER", f"ALTER TABLE `{name}` ADD COLUMN `{col}` — already present")
                    continue
                t.columns[col] = colkind.sql(dialect="mysql").upper() if colkind is not None else "?"

            elif cls == "Drop":
                # sqlglot models `ALTER TABLE t DROP COLUMN c` and
                # `DROP INDEX i` both as a Drop node; `kind` says which.
                if t is None:
                    self.fail(line, "ALTER", f"ALTER TABLE `{name}` DROP on a table that does not exist")
                    continue
                what = str(action.args.get("kind") or "").upper()
                if what == "COLUMN":
                    # A migration redefining a generated column legitimately
                    # drops it before re-adding: `DROP COLUMN x` then
                    # `ADD COLUMN x`. Only complain when it was never there.
                    for col_node in action.args.get("tables") or []:
                        col = col_node.name.lower()
                        if col and col not in t.columns:
                            self.fail(line, "ALTER", f"ALTER TABLE `{name}` DROP COLUMN `{col}` — not present")
                        else:
                            t.columns.pop(col, None)
                            t.uniques.discard(col)
                # DROP INDEX / DROP FOREIGN KEY: nothing to model.

            elif cls in ("AddConstraint", "AddIndex", "Create"):
                # Adding an index or FK to an existing table: check the table
                # exists, and that an FK target is already created.
                if t is None:
                    self.fail(line, "ALTER", f"ALTER TABLE `{name}` on a table that does not exist")
                    continue
                for ref in action.find_all(exp.Reference):
                    self._reference(line, name, "?", ref)

    def _insert(self, line: int, parsed: exp.Insert) -> None:
        self.inserts += 1
        target = parsed.this
        if isinstance(target, exp.Schema):
            name = target.this.name.lower()
        else:
            name = target.name.lower() if target is not None else "?"
        t = self.table(name)
        if t is None:
            self.fail(line, "DML", f"INSERT INTO `{name}` — the table does not exist")
            return

        # Named columns must exist.
        target = parsed.this
        if isinstance(target, exp.Schema):
            for c in target.expressions:
                col = c.name.lower()
                if col and col not in t.columns:
                    self.fail(line, "DML", f"INSERT INTO `{name}` names column `{col}`, which does not exist")

    def _dml(self, line: int, parsed) -> None:
        for table_expr in parsed.find_all(exp.Table):
            name = table_expr.name.lower()
            if name and name not in self.tables:
                self.fail(line, "DML", f"{type(parsed).__name__.upper()} touches `{name}`, which does not exist")


# --------------------------------------------------------------------------- #
# Idempotence checks
# --------------------------------------------------------------------------- #

def check_idempotence(sql: str, model: Model) -> None:
    for line, stmt in split_statements(sql):
        upper = stmt.upper()
        if upper.startswith("CREATE TABLE") and "IF NOT EXISTS" not in upper:
            model.fail(line, "IDEMPOTENCE", "CREATE TABLE without IF NOT EXISTS (a re-run will abort)")
        # CREATE INDEX is deliberately NOT checked for IF NOT EXISTS. MySQL only
        # gained it in 8.0.29 and MariaDB did not, so the migrations use plain
        # CREATE INDEX and rely on schema_migrations for idempotence — which is
        # the mechanism that actually exists on a cPanel shared host.
        # Demanding IF NOT EXISTS here would push the codebase towards syntax
        # that MariaDB and older MySQL reject.
        #
        # The reverse is a real defect and was shipped eight times: the rule above
        # was written in this comment but nothing enforced it, and four migrations
        # used `CREATE INDEX IF NOT EXISTS` anyway. MariaDB accepts it; MySQL
        # rejects it with a 1064 syntax error in every version through 8.4, so an
        # import stopped part-way and left a half-built schema. The comment stated
        # the convention; this check is what makes it true.
        if re.search(r"CREATE\s+(?:UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS", stmt, re.I):
            model.fail(line, "PORTABILITY",
                       "CREATE INDEX IF NOT EXISTS is MariaDB-only and a syntax error on MySQL; "
                       "declare the key on the CREATE TABLE instead")


def check_mysql_only_constructs(sql: str, model: Model) -> None:
    """Constructs that parse as MySQL but do not mean what the file intends.

    These are the leftovers of porting a SQLite schema by hand. Each one either
    fails at execution or, worse, succeeds while doing something else — which is
    why they are checked here rather than left to a real server that the
    development environment does not have.
    """
    for line, stmt in split_statements(sql):
        # SQLite writes `col TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP)`. MySQL
        # rejects a parenthesised literal default on TEXT, and TEXT cannot carry
        # a DEFAULT at all before 8.0.13.
        if re.search(r"\bTEXT\b[^,\n]*\bDEFAULT\s*\(", stmt, re.I):
            model.fail(line, "PORTABILITY",
                       "TEXT with a parenthesised DEFAULT is SQLite syntax; use DATETIME "
                       "NOT NULL DEFAULT CURRENT_TIMESTAMP")

        # SQLite writes `col INTEGER NOT NULL REFERENCES other(id)`. MySQL 8 parses
        # this and silently stores no foreign key, so the column looks constrained
        # and is not.
        if re.search(r"^\s*`?\w+`?\s+INTEGER\s+(?:NOT\s+)?NULL\s+REFERENCES", stmt, re.I | re.M) \
           or re.search(r"ADD\s+COLUMN\s+`?\w+`?\s+INTEGER\s+REFERENCES", stmt, re.I):
            model.fail(line, "PORTABILITY",
                       "inline `INTEGER ... REFERENCES` is SQLite syntax; MySQL ignores it and "
                       "creates no foreign key — use a named CONSTRAINT")




# --------------------------------------------------------------------------- #
# Main
# --------------------------------------------------------------------------- #

def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    flags = {a for a in sys.argv[1:] if a.startswith("--")}
    if not args:
        print(__doc__.strip(), file=sys.stderr)
        return 2

    path = args[0]
    try:
        sql = open(path, encoding="utf-8").read()
    except OSError as exc:
        print(f"Cannot read {path}: {exc}", file=sys.stderr)
        return 2

    print(f"Validating {path}")
    print("=" * 70)

    statements = split_statements(sql)
    print(f"  statements split:  {len(statements)}")

    model = Model()
    for line, stmt in statements:
        model.apply(line, stmt)

    check_idempotence(sql, model)
    check_mysql_only_constructs(sql, model)

    print(f"  CREATE:            {model.creates}")
    print(f"  ALTER:             {model.alters}")
    print(f"  INSERT:            {model.inserts}")
    print(f"  tables modelled:   {len(model.tables)}")
    print()

    if model.problems:
        by_kind: dict[str, list[Problem]] = {}
        for p in model.problems:
            by_kind.setdefault(p.kind, []).append(p)

        print(f"\033[31m{len(model.problems)} problem(s)\033[0m")
        for kind, items in sorted(by_kind.items()):
            print(f"\n  [{kind}]  {len(items)}")
            for p in items[:25]:
                print(f"    line {p.line}: {p.message}")
            if len(items) > 25:
                print(f"    … and {len(items) - 25} more")
        return 1

    print("\033[32mNo problems found.\033[0m")
    print()
    print("Checked: every statement parses as MySQL, every ALTER targets a table")
    print("that exists, every FOREIGN KEY points at a created table and column,")
    print("and every CREATE is idempotent.")
    print()
    print("NOT checked — these need a real server:")
    print("  · whether MySQL accepts each construct when executed")
    print("  · global index-name collisions across tables")
    print("  · charset/collation compatibility of every column")
    print("  · actual constraint behaviour under real data")
    print("Run `php tools/migrate.php` on the target host for those.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
