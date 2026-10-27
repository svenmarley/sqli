# sqli

A fast, keyboard-driven **terminal MySQL client** for people who work in the terminal but want more than a `mysql>`
prompt. Schema-aware tab completion helps you write the statement, a full-screen result grid you can sort, rearrange and
edit in place shows you the answer, and macros, environments and themes make the whole thing yours — all configured from
a `.sqli/` folder that follows the directory you're in.

It runs on PHP 8.1+ straight from source, with **zero third-party dependencies**
and no build step. The UI is drawn by a custom differential ANSI renderer that repaints only the cells that actually
changed, which is why it stays responsive over SSH and in slower consoles rather than only in modern ones.

## Highlights

You already know SQL. These are the parts of `mysql>` you work around.

- **Field/Table/Macro name completion assistance that reads the statement**, not a list of names. Aliases resolve, a
  join offers `u.id` and `o.id` separately, and every command that names a table gets it — not just
  `SELECT`. → [Completion](#completion)  Supports `SELECT from {table} {columns}` where {table} and {columns} are
  auto-complete capable.
- **A grid instead of a wall of pipes.** Sort, move, resize and hide columns; it remembers them and exports as
  arranged. Find a field by name, copy a block of cells as CSV. → [The result grid](#the-result-grid)
- **Edit a cell in place.** Checked against the live column type, and handed to you as one key-scoped `UPDATE` to run or
  cancel. JSON view/editing. → [Editing data](#editing-data)
- **Nine result views on one connection.** `\3` runs CLI command into view 3, so a schema lookup doesn't cost you the
  result you were reading. Alt-{1-9} change view. → [Data views](#data-views)
- **Macros.**`?name` for the query you retype ten times a day — with arguments, prompts, and optionally a different
  macro/id on prod than on dev. → [Macros](#macros)
- **Hierarchical configs** - Set a .sqli config in the project/repo root, then `cd` anywhere into the repo directory.
  Run `sqli -E prod`. Config looks up the directory tree; uses the -E {environment} you chose. Supports a password file
  that you never commit. → [Configuration](#configuration)
- **`Esc` stops a running query** and leaves the session intact — unlike a timeout, which drops the
  connection. → [Connection options](#connection-options)
- **Read-only mode**, plus a confirmation on any `DELETE`/`UPDATE` that isn't scoped to a
  key. → [Editing data](#editing-data)
- **Your team's data dictionary beside the live schema.** Including sync'ing to the database, notes about
  columns. → [The Data Dictionary](#the-data-dictionary)
- **Base16 themes** — drop in any scheme from the open-source Base16 project for the colors, then switch or recolour it
  live from inside the app. → [Themes](#themes)
- **PHP 8.1+, zero dependencies, no build step.** Clone it and run it.

## Requirements

- **PHP 8.1+** with the `pdo`, `pdo_mysql`, and `mbstring` extensions.
- **`mysqli` (optional)** — enables cancelling a running query with `Esc`. It is the only feature that needs it, because
  `pdo_mysql` cannot run a statement without blocking the process. Without it, everything else works unchanged, a query
  simply cannot be interrupted, and sqli does not offer a key, a clock or a spinner that would do nothing. On Windows
  the DLL usually ships already:
  uncomment `;extension=mysqli` in your `php.ini`. `mysqli` and `pdo_mysql` are independent extensions and are designed
  to be loaded together — enabling one changes nothing about the other.
- **`ext-ffi` (optional, recommended)** — ships enabled with PHP on Windows and in most Linux packages. sqli uses it to
  ask the operating system for the window size directly, which makes resizing instant and keeps several sqli in several
  terminals from getting in each other's way. Without it sqli measures the window by asking the terminal instead:
  correct, but slower to settle.
- A **modern terminal** with ANSI/alt-screen support — Windows Terminal, WSL, macOS Terminal/iTerm, or any Linux
  terminal. (Legacy `cmd.exe` works but is slower.)
- **No third-party PHP packages.** sqli has zero external dependencies, no
  `composer install`, and no build step.

## Quick start

```sh
git clone https://github.com/svenmarley/sqli.git
cd sqli
php sqli.php --host localhost --user myuser --database mydb
```

`sqli.php` runs straight from source via a built-in autoloader.

To quit, type `exit` or `quit` on the SQL line, or press `Ctrl+Alt+X` from anywhere.

If an optional extension is missing, sqli says so in one line as it starts, naming the `php.ini` to edit —
`sqli: note: Esc can't cancel a running query: enable extension=mysqli in C:\php\php.ini`. `php sqli.php --lint` lists
the same notes.

sqli asks for the password on the plain terminal before the full-screen UI opens, and only when it doesn't already have
one — so a configured environment (`sqli -E prod`) starts straight up, and a mistyped password fails somewhere you can
still read it.

### Connecting

Options can be passed as flags, or stored in a config file so you don't put credentials on the command line.

| Flag                       | Short | Description                                                                                                               | Default     |
|----------------------------|-------|---------------------------------------------------------------------------------------------------------------------------|-------------|
| `--host`                   | `-H`  | MySQL server hostname or IP                                                                                               | `localhost` |
| `--user`                   | `-u`  | Database username                                                                                                         |             |
| `--password`               | `-p`  | Password. `-pVALUE` sets one; a bare `-p` asks for it. With no password anywhere, sqli asks before connecting             |             |
| `--password-prompt`        |       | Always ask at startup — belongs on an environment whose password is never written down                                    | off         |
| `--no-password`            |       | Connect with an empty password, and never ask                                                                             | off         |
| `--port`                   | `-P`  | TCP port                                                                                                                  | `3306`      |
| `--database`               | `-D`  | Initial database/schema                                                                                                   |             |
| `--socket`                 | `-S`  | Unix socket path                                                                                                          |             |
| `--ssl-mode`               |       | TLS: `disabled`, `preferred`, `required`, `verify-ca`, `verify-identity`                                                  | `preferred` |
| `--ssl-ca`                 |       | CA certificate file used to verify the server                                                                             |             |
| `--ssl-capath`             |       | Directory of CA certificates, instead of `--ssl-ca`                                                                       |             |
| `--ssl-cert` / `--ssl-key` |       | Client certificate pair, for `REQUIRE X509` accounts                                                                      |             |
| `--ssl-cipher`             |       | Permitted cipher list (TLS 1.2 and below)                                                                                 |             |
| `--server-public-key`      |       | RSA public key file for sha2 auth on an unencrypted link                                                                  |             |
| `--env`                    | `-E`  | Named environment from the config `envs` section (or `$SQLI_ENV`, or the config's default `env`)                          |             |
| `--no-env`                 |       | Ignore `$SQLI_ENV` and the config's default `env`, and run with no environment. An explicit `-E` still wins               | off         |
| `--comment`                |       | Short note pinned to the header  e.g. `PROD R/O`                                                                          |             |
| `--theme`                  |       | Base16 scheme name                                                                                                        | `default`   |
| `--truecolor`              |       | 24-bit output: `auto`, `on`, `off`                                                                                        | `auto`      |
| `--dictionary`             |       | Data Dictionary CSV path                                                                                                  |             |
| `--read-only`              |       | Block data-modifying queries                                                                                              | off         |
| `--log-writes`             |       | Record every data-modifying statement that ran in `sqli_db_writes.log`. Off: `--no-log-writes` or `--log-writes=false`    | on          |
| `--local-infile`           |       | Allow `LOAD DATA LOCAL INFILE` — the server can then ask this client for any file it can read. Refused with `--read-only` | off         |
| `--project`                |       | Find the project `.sqli/` from this directory instead of the current one                                                  | cwd         |
| `--config`                 | `-c`  | Load options from a JSON file (or `$SQLI_CONFIG`)                                                                         |             |
| `--user-config`            |       | Personal overlay on `--config` (or `$SQLI_USER_CONFIG`)                                                                   |             |
| `--lint`                   |       | Validate every config file sqli would read, then exit                                                                     |             |
| `--debug`                  | `-d`  | Verbose diagnostics to `debug.log` beside `sqli.php`                                                                      | off         |
| `--help`                   | `-h`  | Print the full option list and exit                                                                                       |             |
| `--version`                | `-V`  | Print the version and exit                                                                                                |             |

Run `php sqli.php --help` for the full list.

**`-d` answers "why won't it connect?"** It writes the DSN it built, the TLS and driver decisions it made, the
connection options in force, every automatic reconnect and every query error code to `debug.log` beside `sqli.php`.
Turn it on for a run, not in an environment: `debug.log` is never rotated or trimmed.

## Getting around

`Ctrl+D` swaps focus between the SQL line and the result grid; `F1` is the complete list of {keys} for whichever view 
you're in, and the system menu is `/`. System menu and in Data View Configuration optinos are scrollable and using Enterhas a quick key
executes that item.  Each option also have drawn in front of it (`1)` … `9)`, then `a)` … `z)`): press it and the item 
runs, exactly as `Enter` on it would. In Data View Configuration the keys are letters from the option itself:
`m)` Move column, `h)` Hide column, `a)` / `d)` Sort ASC / DESC, with every reset and clear kept apart at `v)` … `z)`.

The header row says which server you are on, which database and table the grid is showing, and — if you set one — **your
own short comment beside the `CLI`/`DATA`
tag**: `[PROD - careful]`, in its own colour. Set it for one run with
`--comment "PROD - careful"`, permanently by putting `"comment": "PROD"` on an environment in `sqli_config.json`, or
live from **system menu → Header Comment**
(an empty line clears it).

The status line at the bottom names some of what's available in the current focus as plain words —
`(detail, expand cell, json, copy cell/row, csv, columns, layout, menu)` — rather than spelling out every chord, and
drops the trailing ones when the terminal is too narrow to hold them (a `…` tells you the list was shortened). So the
bottom line tells you *what* you can do; `F1` tells you how.

## Running queries

Type a statement on the CLI and press `Enter`. How you end the line decides where the answer goes:

| Ends with             | What happens                                          |
|-----------------------|-------------------------------------------------------|
| `;`                   | Runs and shows the result in the current data view    |
| `]G`                  | Runs and shows it **vertically**, one column per line |
| `]1` … `]9`           | Runs it in **that** data view and takes you there     |
| a complete macro call | Runs — no `;` needed (see [Macros](#macros))          |
| {Enter}               | Adds another line to the query                        |

`[` works the same as `]`, and so does `\` (`\G` is the `mysql` habit). A trailing `;` is welcome on any of them, so
`]G` `[G` `\G` `]G;` and `]3` `[3` `\3` `]3;` all do what they look like. None of the three means anything in SQL outside
quotes, so `SELECT * FROM t]3` works with or without a space.

Several statements can be submitted at once. They run in order, and one that fails stops the rest rather than running on
past it — the statements after it were written expecting the one that failed to have worked.

**An unqualified `SELECT` is capped at 1000 rows.** The `LIMIT` is added to the SQL that is actually sent, and only when 
the statement carries no `LIMIT` of its own — including one in a subquery. The cap is mentioned
only when it can have bitten: a result that comes back holding the whole 1000 reads
`1000 row(s), 12 col(s), 0.114s · auto-limited to 1000`, and a result of 42 rows says nothing about it, because there
the cap changed nothing and the count is the whole answer.

**Data-modifying statements ask first.** `INSERT`, `REPLACE`, `CREATE`, `ALTER`,
`DROP`, `TRUNCATE`, `RENAME`, `GRANT`, `REVOKE`, `LOAD` and `CALL` always confirm; `DELETE` and `UPDATE` confirm when
they are **broad** — no `WHERE`, or a `WHERE` that doesn't reference a primary key — and run straight away when they are
scoped to specific rows by key. The prompt names the statement, the table, and where it can be worked out, how many rows
are about to change. The statement is read with its comments stripped, so a commented-out `WHERE` cannot make a
whole-table `DELETE` look scoped.

**A running statement is yours to take back.** It shows its elapsed time and a spinner at the end of the CLI line, and
`Esc` stops it — sqli sends `KILL QUERY`
on a second connection, so the one you're using stays open and the session is intact, unlike a timeout, which drops the
connection and leaves the server still working. (Needs the `mysqli` extension; see [Requirements](#requirements) and
[Connection options](#connection-options).)

**CLI History** `Up`/`Down` browse what you typed; `Alt+Up`/`Alt+Down` browse what actually ran — the normalized,
expanded, macro expansion, capped SQL that went to the server. Both are kept across sessions. Commands that modify the 
database are prepended with `{Safety}`, forcing the user to remove that before sending in the same command again.

**`F5` re-runs the query behind the view you're looking at**, from the grid or the SQL line — exactly as you typed it
(the macro call, no automatic `LIMIT`), so it is a refresh of what's on screen. Focus stays where it is. Handy after
switching between data views, when Up-arrow would give you whichever query ran last. A statement that writes, or a
whole batch, isn't fired: it comes back on the SQL line without its `;` for you to end yourself.

**A leading `!` sends the statement exactly as typed.** `!SELECT * FROM big_table;` goes out with no automatic `LIMIT`,
no macro expansion or `?{name}` prompts, and none of sqli's rewrites — only the `!` comes off. The endings in the table
above still work, and the safety checks still run: read-only still blocks a write, and a broad `DELETE` still asks.
History keeps the `!`, so Up-arrow and `F5` repeat it raw.

**A dropped connection is handled quietly.** If the server closes an idle session, the header shows a small red `!db`,
the statement is re-sent once, and the answer arrives with `· reconnected` behind its row count; the grid, the sort and
your column layout stand untouched throughout. The one thing never re-sent by itself is a write that may already have
run. If the socket is dead, it stops and says so, in words, and waits for you.

## Scripting

`-e` runs SQL with no full-screen UI, as it does in `mysql`, and so does SQL piped on stdin:

```sh
sqli -E prod -e "SELECT id, email FROM users WHERE active = 1" > active.csv
sqli -E prod -N -e "SELECT COUNT(*) FROM orders"
sqli -E prod --out report.csv < report.sql
```

**The output is CSV, and only CSV.** Each result set gets a header row (`-N` leaves it out), values come as the server
sent them rather than in the grid's display formats, a NULL is a bare `NULL` while a string that reads `NULL` is
`"NULL"`, and several result sets are separated by one blank line. `--out FILE` writes to a file instead of stdout; a
run that fails leaves no partial file behind. **There is no 1000-row cap**: that cap exists for the grid, and a script
that asks for a million rows gets them. stdout carries the CSV and nothing else; `-v` adds row counts and timings on
stderr.

**Anything sqli would ask you to confirm is refused.** Your SQL goes through the same macros and the same safety checks
as it does in the full-screen UI, but nobody is there to answer a confirmation. So every `INSERT`, `REPLACE`, `CREATE`,
`ALTER`, `DROP`, `TRUNCATE`, `RENAME`, `GRANT`, `REVOKE`, `LOAD`, `CALL`, every broad `DELETE`/`UPDATE`, and any
`DELETE`/`UPDATE` among several statements stops with

```
sqli: refused: DELETE on `users`: no WHERE clause (all rows) — affects ~12,408 row(s). Nothing was run: this needs a confirmation, and only the TUI can give one.
```

and exit code **2**. Every statement is checked before any of them runs, so a refusal means **nothing** was sent, not
"the first two ran". No flag overrides this. To run one of these, use the full-screen UI, where you can confirm it.

**Scripting mode is not read-only.** A single `DELETE` or `UPDATE` scoped by primary key asks nothing in the
full-screen UI, so it runs here too. If a script must never write, add `--read-only`.

| Exit code | Meaning                                                            |
|-----------|--------------------------------------------------------------------|
| `0`       | Everything ran                                                     |
| `1`       | An error: the connection, the server, a macro, or writing the output |
| `2`       | Refused by the confirmation rule or by `--read-only`; nothing ran  |

A `?{name}` prompt in a macro is an error here, since there is nobody to answer it; pass the value as a macro argument
instead. A leading `!` works here too: it skips macros, prompts and rewrites, but the refusals above still apply.
With SQL on stdin a bare `-p` is refused, because the password prompt would read the SQL. Keep the password in
`sqli_credentials.json`, or use `-e`. Piped SQL is detected under Git Bash
(mintty) too, but pipe into `php.exe`, not `php`: Git Bash aliases `php` to `winpty php.exe`,
and winpty refuses piped stdin with "stdin is not a tty".

## Completion

`Tab` completes tables and columns in **every command that names one** —
`EXPLAIN`, `DESCRIBE`, `ALTER`, `DROP`, `TRUNCATE`, `USE`, `CALL` and the rest, not only `SELECT`.

`SELECT FROM {table} {columns} ...` supported so completion of columns works.

It works from the statement you are actually writing:

- **Find fields**  In `SELECT FROM users na{tab}`  will bring up any fields in users that has na in it, or put it on the
  line if there is only 1.
- **Aliases resolve.** In `SELECT … FROM users u JOIN orders o …`, `u.` offers `users` columns and `o.` offers `orders`
  columns — not two indistinguishable `id`s.
- **A subquery completes against its own `FROM`**, not the outer one.
- **Where a proposal would be an error, there is none.** A qualifier that matches no table, an `ADD COLUMN` name, a
  `VALUES` list — sqli offers nothing instead of guessing.

`?[a-z]{tab}` completes a macro name (or lists all matching) (see [Macros](#macros)), and
`word?` looks a name up in the [Data Dictionary](#the-data-dictionary).

## The result grid

`Ctrl+D` moves focus to the grid. Arrows move the selection; `i` opens a detail view of the whole row, `v` expands one
cell in a movable inspector, `j` opens a JSON cell in a tree viewer, `t` opens a separated-value cell as a table
(see [Editing data](#editing-data)).

**Find a field — `Ctrl+Alt+F`.** Type part of a field name and the grid narrows to the fields whose name contains it;
`Backspace` widens it again. `Esc` or `Ctrl+Alt+F` brings every field back with the cursor still on the field you were
on. Hidden fields stay hidden and aren't searched. Nothing is saved.

**`Ctrl+Alt+S` shows the columns A–Z** — the ones you haven't placed yourself; a column you moved keeps its place.
Press it again for the result's own order. It is never saved, and it turns off when the view shows another table.

**Rearrange it — `Ctrl+L`.** That opens **Data View Configuration**, the one menu for everything about how the grid
looks. The top half acts on the column you're on and the title names it: sort, move, resize, fit, copy, hide, and a
display-format toggle. The bottom half acts on the whole table: reset the widths, the order or all of it, and put back
any column you've hidden or format you've applied, one by one or in one go.

Four ways in, all the same menu: `Ctrl+L`, `Enter` on the header row, a click on a column header (which scopes it to the
column you clicked), or system menu → Data View Configuration. While any customization is live, a vertical **Layout
Config** label sits on the grid's border, so the arrangement you're reading is never a surprise.

**It remembers.** Everything is saved in `.sqli/sqli_formatting.json`. Widths, sort and formats are kept per table and
come back on any query of it. Column order and hidden columns are kept per **field list**: an arrangement made over
`SELECT id, name, total …` comes back for that same list of columns, and `SELECT *` keeps its own. Spacing, backticks
and the `WHERE` clause don't matter, but `id, name` and `name, id` are two different lists. A join is its own entry:
what you arrange over `templates, template_items` is kept apart from `templates` alone. The key is the whole
connection — `host:port/db` — so `devel` on two servers keeps two arrangements.

**A display format changes the display, never the data.** Put the `epoch` format on a column and the integer reads as
local date/time; the stored value is untouched, and editing the cell still writes back what the column wants.

**`Ctrl+Alt+O` exports the view as arranged** — your column order, your hidden columns left out, formatted columns
written the way they are shown — to a CSV in the current directory. A NULL is written as a bare `NULL` and a string
that reads `NULL` as `"NULL"`, so you can tell them apart. `Ctrl+C` copies a cell, `Ctrl+Alt+C` a row.

**Copy a block of cells as CSV.** `Shift`+arrows highlight a rectangle of cells, in its own colour, and `Ctrl+C` copies it
as CSV, one line per row. Take it up past row 1 and the field names come along as the first line. Once a block is
started, `Home`/`End`, `PgUp`/`PgDn` and `Ctrl`+arrows stretch it with or without `Shift`, because Windows Terminal keeps
`Ctrl+Shift`+those keys for its own scrollback. A plain arrow, any other key or a click drops the block.

### Data views

Nine grids share one connection. `Alt+1`…`Alt+9` switch between them,
`Ctrl+PgUp`/`Ctrl+PgDn` step through them, and ending a statement with `\3` runs it in view 3 and takes you there — so a
`SHOW CREATE TABLE` doesn't cost you the result you were reading. Each view keeps its own rows, selection, sort and
arrangement; the CLI, the history and the connection are shared.

## Editing data

`Enter` on a cell edits it. The value is validated against the **live column type** before anything runs — numbers,
`tinyint(1)` booleans, dates and datetimes, string lengths, JSON validity, `enum` and `set` members. Primary keys are
protected from editing; a foreign key is flagged.

What comes out is **one `UPDATE`, scoped to the primary key, put on the CLI** for you to run or cancel. Nothing piles up
unapplied, one edit is in flight at a time, and nothing is written behind your back.

JSON columns open in a **tree view** (`j`) you can read and edit as a document instead of as one long line; pressing `j`
again opens a blob nested inside it. `Enter` on a value edits it as plain text — no quotes, no escaping — with its type
(`string`, `number`, `bool`, `null`) shown underneath; `Tab` changes the type, so `"hello"` can become `true`. Text that
does not fit the chosen type is refused with the reason.

**`Ctrl+N` sets a cell to a real SQL `NULL`** — the same one-cell `UPDATE` on the CLI as a typed edit.

**Cells that pack several records into one text value** — rows separated by newlines, fields by `@`, `|` or anything
else — open as a **table** with `t`. The first time, you describe the layout once for that table and column:

```
rows = "\n"
Inspection Group   "@"
Inspection Type    "@!"
Record Identifier
```

One line per field: its header, then the separator that follows it (several characters are fine, and each field can
have its own). The last field has none. `\` in the data escapes a separator. The description is saved in
`sqli_formatting.json`, and `c` in the table edits it. In the table, `Enter` edits a field, `Del` blanks it, `a`/`A` add
a row, `d` deletes one, and `Esc` closes. If anything changed, the rebuilt text becomes one `UPDATE` on the CLI, like
any cell edit. Rows you didn't touch are written back exactly as they were read.

**Deleting.** `Del` on a row builds the `DELETE`, scoped to the primary key, counts what it will affect and asks before
anything happens — then puts the statement on the CLI so you read it before you run it. `Ctrl+Del` opens a checkbox
column for deleting several at once. **No primary key, no delete:** sqli won't write a statement it can't scope to
exactly the rows you picked.

**The header says which table you're on.** On a joined result the header lists every
table it drew from, numbered — `prod.templates(1), template_items(2)` — and the one the
selected column came from is highlighted, so `1.id` never has to be decoded by counting.
Columns whose names didn't clash light up their table too.

**Joined results are read-only.** A generated `UPDATE` or `DELETE` names one table, and on a result built from two
there is no safe way to know which one the column you're editing belongs to — so sqli refuses and tells you which
tables the result joined. Query the one table you mean to change.

**Two columns with the same name stay two columns.** `SELECT t.id, ti.* FROM templates t, template_items ti …` returns
two columns called `id`; sqli numbers the tables and shows them as `1.id` and `2.id`, with `prod.templates(1),
template_items(2)` on the header so the numbers mean something. Names that don't clash are left alone. **Exports spell
them out in full** — `templates.id`, `template_items.id` — since a CSV has no header to explain a number, and a file
has no width to save.

**Hiding the address.** `--host-label acme-warehouse`, or `"host_label"` on an environment, puts that name in the header where
`admin@db.internal:3306/prod` would be — handy when you're sharing a screen or a screenshot. It's cosmetic only: the
driver, `[RO]` and the TLS marker still sit beside it, because those say what the *session* can do and a nickname must
not be able to hide them. `--lint` refuses two environments using the same label.

**Read-only mode** — `--read-only`, or `"read-only": true` on an environment — blocks every data-modifying statement,
including one a macro expanded into. It is what makes a production connection safe to browse. Without it, broad
statements still confirm first; see [Running queries](#running-queries).

**The write log.** Every data-modifying statement that ran — typed, a cell edit, a grid delete, a macro, or `-e` — is
appended to `sqli_db_writes.log`: date and time, your OS login, the database user, server, database, table and the statement, tab-separated, one line
each. It goes in `~/.sqli/` when that directory exists, otherwise in a `.sqli/` under the directory you started sqli
from. Passwords in a statement (`IDENTIFIED BY '…'`) are written as `'***'`. It is on by default; turn it off with
`"log-writes": false` in a config or environment, or `--no-log-writes` on the command line. It is a record for you, not
an audit trail — anyone who can run sqli can switch it off.

## The Data Dictionary

Your team's CSV of table and column descriptions, read beside the live schema — and kept in step with it. Point sqli at
the file with `--dictionary path/to/dd.csv`, or `"dictionary"` in any config layer.

- **`word?`** on the CLI shows that field's description without disturbing what you're typing.
- **System menu (`/`) → Show Data Dictionary** opens the full-screen viewer: tables on the left, fields on the right,
  and one search box that matches table names, field names and comments at once. `Ctrl+I` opens a detail view of the
  selected row. **Assign Data Dictionary** points at a different file.

### It shows you where it has drifted

sqli compares each documented table against the live `SHOW COLUMNS` and badges the difference, so you can see what the
CSV has quietly stopped being true about:

| Badge              | Means                                                                    |
|--------------------|--------------------------------------------------------------------------|
| `Δ`                | documented, but the live type has changed                                |
| `✗`               | documented, but the column or table is gone from the database            |
| `⊕ N new table(s)` | live tables the dictionary doesn't document at all — `Ctrl+N` lists them |

### And it writes back

The dictionary is **editable, not read-only**:

- **`Enter` on a field** edits its comment in place and saves the CSV immediately. (The foreign-keys summary row is the
  one exception — it opens a viewer instead.)
- **`Ctrl+U`** syncs the selected table from the live schema: type, nullable, key and default are rewritten from
  `SHOW COLUMNS`, **your comments and formats are preserved**, live columns the DD doesn't document yet are appended
  with an empty comment to fill in, and fields the database has dropped are removed.
- **`Ctrl+N`** opens the undocumented-tables list — `Space` ticks one, `Ctrl+A` ticks all, `Enter` adds them, one row
  per live column.

Because it is a **shared** file, every write treats it as one. sqli refuses to edit while the CSV is open in another
program — the title bar turns into a warning and stays that way until it's closed — re-reads and merges a file that
changed underneath you so a colleague's edits survive your save, and replaces the file atomically, so nobody ever reads
a half-written CSV. Create an `Archives/` folder beside it and sqli snapshots the file before the first edit of each
session; without that folder it doesn't, which is how you opt in.

It is still **your document, not an sqli file.** It lives wherever your team keeps it — shared drive, project repo,
anywhere — and sqli never moves it or decides where it goes. That is why it is the one thing here that doesn't live in
`.sqli/`.

## Configuration

Everything sqli reads or writes for a directory lives in a **`.sqli/`** folder:

| File                    | What it holds                                              | Commit it? |
|-------------------------|------------------------------------------------------------|------------|
| `sqli_config.json`      | Settings and named environments — hand-authored            | yes        |
| `sqli_macros.ini`       | Named SQL snippets (see [Macros](#macros)) — hand-authored | yes        |
| `sqli_credentials.json` | Passwords, keyed by environment                            | **no**     |
| `sqli_state.json`       | Saved history, layout, selected theme — app-written        | no         |
| `sqli_formatting.json`  | Per-column widths, order, formats, hidden columns          | no         |
| `sqli_db_writes.log`    | Every data-modifying statement that ran — app-written      | no         |
| `themes/`               | Base16 scheme files                                        | up to you  |

`.sqli/` holds only what sqli itself owns. A Data Dictionary is **not** one of those things — it's a document your
organization maintains, so it stays wherever your team keeps it and `dictionary` just points at the path.

Copy the examples in this repo's `.sqli/` to get started:

```sh
cp .sqli/sqli_config.example.json .sqli/sqli_config.json
cp .sqli/sqli_credentials.example.json .sqli/sqli_credentials.json
cp .sqli/sqli_macros.example.ini     .sqli/sqli_macros.ini
php sqli.php --lint          # check what sqli will read, and from where
```

### Cascade

sqli walks from the current directory up to the filesystem root, and **every
`.sqli/` it finds is a layer**. Settings merge per key, with the layer closest to your cwd winning — so a repo can carry
shared defaults while a subdirectory overrides one of them. Your home directory's `.sqli/` is always the global base
layer, never a project.

To use a project's `.sqli/` from somewhere else, name it: `sqli --project ~/work/app -E prod`. The walk then starts at
that directory (or its `.sqli` folder) instead of your cwd. A directory that doesn't exist is an error.

**This is an ancestor walk, not a search.** sqli only ever looks at the directories between you and the root — your
current directory, its parent, and so on — never *into* anything. It's a handful of `is_dir()` checks, done once at
startup, and it does not care how large the tree below you is.

It's done by looking rather than by remembering because a project's `.sqli/` is meant to be **committed with the repo**.
Clone a project onto a new machine,
`cd` in, and its environments have to work the very first time sqli sees that directory — which rules out relying on a
list of places sqli has been before. (One is kept anyway, at `~/.sqli/sqli_registry.json`, purely as a cache of known
projects for tooling to read. It's machine-owned and safe to delete.)

Lowest to highest: **built-in defaults → global → project layers (farthest to closest) → `--config` → `--user-config` →
credentials → command-line flags.**

### "Can a project config override my global one?"

Yes. That is what "closest wins" means, and it is deliberate. sqli is a convenience for picking a server, not an access
control system. A project's `.sqli/` can set `read-only: false` over a global `true`, or name a different host, and it
wins, the same way a command-line flag wins over both.

That isn't a hole, for two reasons. First, anyone who can edit a `.sqli/` folder can also edit your global config, or
just type `--read-only=false`. The database account's grants are what actually limit a session, so use a read-only login
for anything that must stay read-only. Second, your global config lives in your home directory, so it never travels with
a repo. Clone a project on another machine and there is no global layer there to override.

What sqli does do is make sure it is never a surprise. Config files can disagree about **which server** an environment
means: a project's `prod` might set a different `host`, `port`, `socket`, `user` or `database` than the global `prod`.
When that happens, sqli prints a warning at startup naming both values and both files, and repeats it on the status line.
`--lint` lists every environment where this happens. The closest file still wins.

Set `$SQLI_HOME` to put the global layer somewhere other than your home directory — useful if you want Windows and WSL
to share one configuration.

### Environments

Name your servers once and select one with `-E` (or `--env`). It's a capital E because in `mysql`, `-e` runs a
statement; sqli refuses `-e` with a message instead of guessing what you meant.

```json
{
    "envs": {
        "base": {
            "user": "admin",
            "port": 3306
        },
        "local": {
            "extends": "base",
            "host": "localhost",
            "database": "devel"
        },
        "prod": {
            "extends": "base",
            "host": "db1.example.com",
            "database": "prod",
            "read-only": true
        }
    }
}
```

```sh
php sqli.php -E prod
```

An environment may `extends` another (chains are resolved across every config layer, and cycles are rejected). Passwords
belong in `sqli_credentials.json`, keyed the same way — keep them out of the committed config.

### A default environment

The environment you use ninety per cent of the time shouldn't need typing. Name it in `defaults{}`, and a bare `sqli`
means it:

```json
{
    "defaults": {
        "env": "local"
    },
    "envs": {
        "local": {
            "host": "localhost",
            "database": "devel"
        }
    }
}
```

```sh
php sqli.php              # = php sqli.php -E local
php sqli.php -E prod      # an explicit -E always wins
php sqli.php --no-env     # no environment at all, for once
```

`env` is an ordinary cascaded key, so the `.sqli/` closest to your working directory decides: a global default of `prod`
is overridden by a project whose `defaults{}` says `"env": "dev"`, and a project that says `"env": ""` has no default at
all rather than inheriting the global one. Order of selection is `-E` → `$SQLI_ENV` → the config, and `--no-env` refuses
the last two — the two nobody typed on the command line.

An env chosen this way is never silent. The header names it before `[RO]` — `env local (default)` — and a read-only
refusal says which env turned read-only on (`editing is blocked (from env local, the config's default env)`), because the
env's settings sit under everything you typed: `-H`, `-u` and `--comment` replace the env's own, but anything you didn't
type (like `read-only`) still comes from it.

It is the one option that belongs only to a file's base: inside an `envs{}` block it would be an environment selecting
an environment, so it is ignored there (`extends` is the key that inherits one), and `--lint` says so.

Because an environment chosen this way leaves no trace on the command line, sqli prints one line naming it before the UI
opens, and `--lint` refuses a default `env` that names an environment no config file defines — the one typo that would
otherwise stop sqli from starting only for whoever `cd`s into that directory.

## Macros

The id, host, clause fragment or whole query you retype ten times a day, kept in a file and typed as `?name`. Macros
live in `.sqli/sqli_macros.ini`, in the same layers as everything else — INI rather than JSON, because a macro is one
line of SQL and JSON spends most of a line escaping it.

```ini
fl-agency-id = 22
recent        = "select * from inspection order by created desc limit 20;"
ganl          = "select * from agencies where name like '%?1%';"
```

```
select * from agencies where id = ?fl-agency-id;   →   … where id = 22;
?ganl vegas                                         →   … name like '%vegas%';
?recent                                             →   runs, no `;` needed
```

Three shapes, one sigil:

| Written       | Means                               | Filled inside quotes?                            |
|---------------|-------------------------------------|--------------------------------------------------|
| `?name`       | substitute this macro's value       | **No** — a `?name` inside `'…'` or `"…"` is text |
| `?1`, `?2`, … | an argument, inside a macro's value | Yes — `'%?1%'` is the ordinary shape             |
| `?{name}`     | ask when the statement runs         | Yes                                              |

`?{name}` works on any statement, macro or not: type
`SELECT * FROM inspection WHERE agency = ?{agency}`, press Enter, and the CLI asks `agency:` before it runs.

**Quoting is the escape, and the only one.** A reference is expanded only where the server would read SQL — never inside
a string literal, a quoted identifier or a comment — so `select 'ask me about ?agency'` is left alone, and the one `?`
real SQL contains (`prepare s from 'select … id = ?'`) survives untouched. The words after a reference become its
arguments and leave the line; quote one to put a space or a `;` inside it. It is a **text** substitution, not a bound
parameter, so a macro can hold a table name, a `WHERE` fragment or a whole statement, and one macro may name another.

Expansion happens **before** anything else sees the statement, so a macro that expands to a `DELETE` still meets
read-only mode and the same confirmation prompt you would get for typing it out.

Per-environment values use a section head:

```ini
agency-id = 22

[env:prod]
agency-id = 4471
```

`[env:prod]` loads only under `-E prod`, and an environment that `extends`
another inherits its macros exactly as it inherits its options — so one name can mean a different id per server, and
with no `-E` no such section loads at all.

- **`?` then `Tab`** completes a macro name and shows a slice of each value beside it.
- **System menu (`/`) → Macros** opens a read-only viewer: every name, its value, which file and which environment it
  came from. The menu item carries the count, so you can see whether any loaded without opening it.
- **`php sqli.php --lint`** validates every macros file it would read, names what each one defines, and reports a bad
  name, an empty value or a reference loop with the file and line.

## Encrypted connections

`--ssl-mode` uses the same vocabulary as the `mysql` client, so instructions from whoever runs your server transfer over
unchanged:

| Mode                    | What it does                                                   |
|-------------------------|----------------------------------------------------------------|
| `disabled`              | Never offer TLS.                                               |
| `preferred` *(default)* | TLS when the server supports it, plaintext when it doesn't.    |
| `required`              | TLS or fail — but the server's certificate isn't checked.      |
| `verify-ca`             | `required`, and the certificate must chain to your `--ssl-ca`. |
| `verify-identity`       | `verify-ca`, and the hostname must match the certificate.      |

### Which one do I need?

Ask the server. These two answers decide everything:

```sql
SHOW VARIABLES LIKE 'require_secure_transport'; -- ON => TLS is mandatory
SHOW CREATE USER CURRENT_USER(); -- REQUIRE SSL | X509 | NONE
```

- **`REQUIRE NONE`, transport OFF** — nothing to do. The default `preferred`
  encrypts anyway if the server offers it.
- **Transport ON, or `REQUIRE SSL`** — you need TLS but no files. Use
  `required`, or `verify-ca` if you can get the CA (better — see below).
- **`REQUIRE X509`** (or a named subject/issuer) — you also need a client certificate and key, issued to you by whoever
  runs the server.

### Setting it up

**1. TLS with no certificate files.** Enough for `require_secure_transport=ON`:

```sh
php sqli.php -H db1 -u me -p --ssl-mode=required
```

This encrypts, but does **not** authenticate the server — anything presenting a certificate is accepted. Fine inside a
trusted network; prefer `verify-ca` when you can.

**2. TLS that verifies the server.** Get the CA certificate (`ca.pem`,
`ca-cert.pem`, or your cloud provider's bundle — AWS RDS, Azure and GCP all publish one), put it somewhere stable, and
point at it:

```sh
mkdir -p ~/.sqli/certs
cp /path/to/ca.pem ~/.sqli/certs/
php sqli.php -H db1 -u me -p --ssl-mode=verify-ca --ssl-ca=~/.sqli/certs/ca.pem
```

Use `--ssl-capath=<dir>` instead if you have a directory of hashed CA certificates rather than one file. Step up to
`verify-identity` when the certificate's name actually matches the host you connect to — it adds the hostname check, and
it's the only mode that stops a valid certificate for a *different* server from being accepted.

**3. Client certificate**, for `REQUIRE X509` accounts. Always both files:

```sh
php sqli.php -H db1 -u me -p --ssl-mode=verify-ca \
  --ssl-ca=~/.sqli/certs/ca.pem \
  --ssl-cert=~/.sqli/certs/client-cert.pem \
  --ssl-key=~/.sqli/certs/client-key.pem
```

**4. Then stop typing it.** Every `ssl-*` option is an ordinary config key, so it belongs in the environment — paths
take `~` and `${SQLI_HOME}`:

```json
{
    "envs": {
        "prod": {
            "host": "db1.example.com",
            "user": "me",
            "database": "prod",
            "read-only": true,
            "ssl-mode": "verify-ca",
            "ssl-ca": "~/.sqli/certs/ca.pem"
        }
    }
}
```

After which the whole command is `sqli -E prod`. Check it before connecting with
`php sqli.php --lint`, which resolves the options and reports the mode it arrived at under *connection encryption*.

### Confirming it worked

sqli tells you what it actually got rather than what it asked for. On connect the status line reads:

```
Connected · me@db1.example.com:3306/prod · TLS TLS_AES_256_GCM_SHA384
```

That cipher comes from the session's own `Ssl_cipher`, read back after connecting. Under `required` and the `verify-*`
modes an unencrypted session is **refused** rather than reported — "I asked for TLS" and "this connection is encrypted"
are not the same claim, and only the second one is worth trusting.

### When it doesn't work

| What you see                                                 | What it means                                                                                                                                                   |
|--------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `[3159] Connections using insecure transport are prohibited` | The server requires TLS and the connection went out in plaintext. Set `--ssl-mode=required`.                                                                    |
| `SSL connection error` / handshake failure                   | TLS was attempted and rejected. Usually a CA that doesn't match the server's certificate — check `--ssl-ca`, or drop to `required` to confirm that's the cause. |
| `ssl-mode is required but the connection is not encrypted`   | sqli connected, then found the session unencrypted, and refused it. The server accepted plaintext; check that it offers TLS at all.                             |
| `Access denied` right after enabling TLS                     | A `REQUIRE X509` account with no client certificate. Add `--ssl-cert` and `--ssl-key`.                                                                          |

A connection over a Unix socket (`-S`) counts as secure transport to MySQL and is unaffected by
`require_secure_transport`.

### What's covered, and what isn't

sqli exposes every connection-security setting PHP's PDO MySQL driver supports:
`ssl-mode`, `ssl-ca`, `ssl-capath`, `ssl-cert`, `ssl-key`, `ssl-cipher`, and
`server-public-key` (the RSA key that lets `caching_sha2_password` authenticate over an *unencrypted* link — the one
option here that is not about TLS).

The driver itself has no interface for these, so sqli cannot offer them:
**minimum TLS version** (`--tls-version`), **TLS 1.3 cipher suites**
(`--tls-ciphersuites` — note `ssl-cipher` governs TLS 1.2 and below only), **certificate revocation** (`--ssl-crl`,
`--ssl-crlpath`), and **FIPS mode**. If your policy requires any of those, they have to be enforced server-side.

## Connection options

Seven more settings, none of them about security. They go anywhere config goes — a flag, `defaults{}`, or one
environment:

| Option             | Default | What it does                                                                                                                                                                                                                                                                                                       |
|--------------------|---------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `connect-timeout`  | `10`    | Seconds to establish the connection. `0` leaves it to the OS.                                                                                                                                                                                                                                                      |
| `read-timeout`     | `1800`  | Seconds of server **silence** before the client gives up. `0` keeps PHP's own setting.                                                                                                                                                                                                                             |
| `init-command`     | —       | A single `SET`, run on every connect.                                                                                                                                                                                                                                                                              |
| `compress`         | `false` | Compress the protocol — worth it for large result sets over a WAN.                                                                                                                                                                                                                                                 |
| `found-rows`       | `false` | An UPDATE reports rows *matched* instead of rows *changed*.                                                                                                                                                                                                                                                        |
| `multi-statements` | `false` | Whether the server accepts several statements in one packet.                                                                                                                                                                                                                                                       |
| `ignore-space`     | `false` | Allow a space before a function's `(`. Also makes function names reserved.                                                                                                                                                                                                                                         |
| `driver`           | `auto`  | `auto` \| `mysqli` \| `pdo`. `auto` uses `mysqli` where the build has it, which is what lets `Esc` cancel a query. `mysqli` *requires* it and fails at startup otherwise, rather than quietly connecting without the ability to cancel. The one in use is shown at the head of the connection label in the header. |

**`read-timeout` measures silence, not duration.** It bounds a single network read, so a query returning a million rows
streams continuously and never comes near it. What trips it is a server that says nothing at all — a long aggregate that
returns no rows until it finishes, a lock wait, a dead link. Before this option existed, PHP's shipped default of 86400
meant sqli would sit frozen for 24 hours waiting on one.

It also **drops the connection, not the query**: the server keeps executing, and reconnecting will not stop it. To
actually stop a query, press **`Esc`** while it runs — sqli sends `KILL QUERY` on a second connection, and the one you
are using stays open (needs the `mysqli` extension; see Requirements).

For a limit that applies without anyone watching, ask the server to enforce one:

```json
"init-command": "SET SESSION max_execution_time = 30000"
```

Thirty seconds, enforced server-side, on read-only statements. `init-command`
accepts a single `SET` and nothing else — it runs on every connect *and* every automatic reconnect, and a config file in
a parent directory can supply one.

**Everything here is fixed when the connection opens**, so there is no in-app screen for any of it — the driver has no
way to change these on a live connection, and a menu that quietly reconnected to look responsive would throw away your
temporary tables and session variables. `php sqli.php --lint` prints the set that will be used.

## Themes

sqli renders through a **Base16** palette: 16 colours, mapped onto every UI element, emitted as 24-bit truecolor where
the terminal supports it and gracefully downsampled to 256 colours where it doesn't (`--truecolor=auto`).

Themes are **your assets** — sqli ships one scheme rather than a bundled library. Drop any Base16 scheme file (`base00`–
`base0F`, YAML or JSON) into `.sqli/themes/`, in a project or globally. Hundreds are available at
[tinted-theming/schemes](https://github.com/tinted-theming/schemes), which is where the one in `.sqli/themes/` came
from, unmodified: the whole file name is the theme name, so `nord.yaml` is `nord.yaml` — nothing is assumed about the extension.

From the system menu (`/`):

- **Select Theme** — arrow through the list and the whole app recolours live, so you choose by looking at the app rather
  than at a name. `Enter` keeps it,
  `Esc` puts back the one you arrived with.
- **Edit Theme** — nudge any of the 16 slots by R/G/B or type a hex value, with the live UI updating as you go, then
  save it as a scheme file of your own.

**`<default>`** is the built-in palette (Base16 Default Dark). It lives in code, heads both lists, and can be loaded and
edited like any other theme — but not saved over, since there is no file behind it. Save your changes under a new name
instead. Setting `--theme "<default>"` always returns you to it.

`php sqli.php --lint` lists every theme it can find and validates each one.

## Notes on runtime files

Saved history, layout and theme choice go in the nearest `.sqli/`, and logs (`debug.log`, `php_errors.log`) sit beside
`sqli.php`. The example `.gitignore`
covers them; don't commit them.

The files you write yourself — `sqli_config.json`, `sqli_macros.ini`,
`sqli_credentials.json`, your themes — are never written by sqli. It reads them, tells you what it found with `--lint`,
and leaves them alone.

## License

[MIT](LICENSE).
