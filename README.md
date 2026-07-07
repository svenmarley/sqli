# sqli

A fast, keyboard-driven **terminal MySQL client** — browse tables, run queries,
and edit data in a full-screen TUI, with type-aware inline editing, a data
dictionary viewer, tab completion, and a JSON inspector.

Its defining feature is a **custom differential ANSI renderer**: every frame is
drawn into a back buffer and only the cells that changed are written to the
terminal. That keeps it responsive even in slower consoles, not just modern
ones.

## Highlights

- **Result grid** with keyboard navigation, sorting, column reordering/resizing,
  and hidden columns — layout is remembered per table.
- **Type-aware cell editing.** Edits are validated against the live column type
  before they run (numbers, `tinyint(1)` booleans, dates/datetimes, string
  lengths, JSON validity, enum/set members); primary keys are protected and
  foreign keys are flagged.
- **Data Dictionary viewer** — load a CSV of table/column descriptions and see
  it alongside the live schema, with cross-field search.
- **Tab completion** for tables and columns, a movable cell inspector, and a
  JSON tree view for JSON columns.
- **Read-only mode**, configurable theme, and an idle screensaver.

## Requirements

- **PHP 8.1+** with the `pdo`, `pdo_mysql`, and `mbstring` extensions.
- A **modern terminal** with ANSI/alt-screen support — Windows Terminal, WSL,
  macOS Terminal/iTerm, or any Linux terminal. (Legacy `cmd.exe` works but is
  slower.)
- **No third-party PHP packages** — there is nothing to `composer install`.

## Quick start

```sh
git clone <your-repo-url> sqli
cd sqli
php sqli.php --host localhost --user myuser --password --database mydb
```

`sqli.php` runs straight from source via a built-in autoloader — no build step.
(If you prefer, `composer install` generates an optimized autoloader, but it is
optional.)

### Connecting

Options can be passed as flags, or stored in a JSON config file so you don't put
credentials on the command line.

| Flag | Short | Description | Default |
|------|-------|-------------|---------|
| `--host` | `-H` | MySQL server hostname or IP | `localhost` |
| `--user` | `-u` | Database username | |
| `--password` | `-p` | Password (`-pVALUE` or prompt) | |
| `--port` | `-P` | TCP port | `3306` |
| `--database` | `-D` | Initial database/schema | |
| `--socket` | `-S` | Unix socket path | |
| `--theme` | | Color theme name | `default` |
| `--dictionary` | | Data Dictionary CSV path | |
| `--read-only` | | Block data-modifying queries | off |
| `--config` | `-c` | Load options from a JSON file (or `$SQLI_CONFIG`) | |

Precedence (lowest to highest): **defaults → `--config` (or `$SQLI_CONFIG`) →
`--user-config` (or `$SQLI_USER_CONFIG`) → command-line flags.**

### Config file

Copy the example and edit it:

```sh
cp sqli.config.example.json sqli.config.json
php sqli.php -c sqli.config.json
```

`sqli.config.json` is git-ignored so your credentials stay out of version
control. Run `php sqli.php --help` for the full option list.

## Notes on runtime files

The app writes a few per-user files next to `sqli.php` (`sqli.json` for saved
history and layout, `debug.log`, `php_errors.log`). These are git-ignored; don't
commit them.

## License

[MIT](LICENSE).
