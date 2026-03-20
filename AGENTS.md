# AGENTS.md — Laravel Environment Importer

Agent instructions for working in this repository.

---

## Project Overview

A Laravel package that imports a production/staging environment (database + files) into a local
environment. Supports SSH tunnels, multiple DB engines, file sync via `rsync`, and a processor
plugin system for post-import data transformations.

- **Namespace root:** `VanOns\LaravelEnvironmentImporter`
- **PHP:** `^8.0` | **Laravel:** `>=10`
- **Source:** `src/` | **Config:** `config/`

---

## Commands

### Static Analysis

```bash
composer analyse          # PHPStan level 5 (paths: config/, src/)
```

### Code Formatting

```bash
composer format           # php-cs-fixer fix (PSR-12 + project rules)
```

### Tests

There is currently no test suite. `orchestra/testbench` is available in `require-dev` for future
tests. When tests are added:

```bash
vendor/bin/phpunit                                      # all tests
vendor/bin/phpunit tests/Unit/SomeTest.php             # single file
vendor/bin/phpunit --filter testMethodName             # single method
vendor/bin/phpunit --filter 'SomeTest::testMethodName' # class + method
```

---

## Code Style

### Tooling

- **php-cs-fixer** (`^3.64`) with `.php-cs-fixer.dist.php`
- **PHPStan** level 5 with `phpstan.neon.dist`
- Always run `composer format` before committing; run `composer analyse` to verify no new issues.

### Key Formatter Rules (`.php-cs-fixer.dist.php`)

| Rule | Value |
|---|---|
| Base standard | `@PSR12` |
| Array syntax | Short (`[]`) |
| String quotes | Single quotes preferred |
| Trailing commas | Required in multiline arrays/calls |
| Unused imports | Removed automatically |
| PHPDoc spans | Multi-line |
| Method chaining | Consistent indentation |

---

## File & Namespace Structure

```
src/
├── Commands/           # Artisan command(s)
├── Exceptions/         # Custom exception classes
├── Notifications/      # Mail/Slack notifications
├── Processors/
│   ├── Data/           # Post-import row-level processors (extend DataProcessor)
│   └── Database/       # Dump-level processors (extend DatabaseProcessor)
├── Support/            # Utilities (AsyncProcess, etc.)
└── LaravelEnvironmentImporterServiceProvider.php
config/
└── environment-importer.php
```

Sub-namespace mirrors directory path exactly (e.g. `Processors\Data\AnonymizeUsers`).

---

## Naming Conventions

| Element | Convention | Example |
|---|---|---|
| Classes / Interfaces | PascalCase | `ImportEnvironmentCommand` |
| Methods | camelCase | `importDatabase()`, `isDbTunnelReady()` |
| Properties | camelCase | `$backupPath`, `$dbTunnelProcess` |
| Variables | camelCase | `$dumpFile`, `$processorClass` |
| Config keys | snake_case strings | `'ssh_host'`, `'backup_path'` |
| Config file names | kebab-case | `environment-importer.php` |
| Artisan signatures | `namespace:verb` kebab-case | `environment:import` |

---

## Imports & Use Statements

- One `use` per line — no grouped braces.
- Order: external/framework classes first, then internal (`VanOns\...`) classes last.
- Function imports (`use function ...`) appear after all class imports.
- No unused imports (enforced by formatter).

```php
<?php

namespace VanOns\LaravelEnvironmentImporter\Commands;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Spatie\DbDumper\Databases\MySql;
use VanOns\LaravelEnvironmentImporter\Exceptions\ImportEnvironmentException;
use function Laravel\Prompts\select;
```

---

## Type Hints

Use explicit types everywhere — no implicit `mixed` or untyped signatures.

```php
// Return types on every method
public function handle(): int {}
public function importDatabase(): void {}
public function getEnvironments(): array {}
public function sshAuth(): string {}
public function dbUseSsh(): bool {}
public function getConfigValue(string $key, mixed $default = null): mixed {}

// Nullable types
protected ?Process $dbTunnelProcess = null;
public function getPasswordOverride(): ?string {}

// Union types (PHP 8.0+)
public function resolve(string|int $key): mixed {}

// Constructor property promotion for simple value objects
public function __construct(
    protected Carbon $startedAt,
    protected string $exception,
) {}
```

- Add `@throws ClassName` PHPDoc when a method can throw.
- Use `@var class-string<...>` or `@phpstan-ignore-next-line` only when PHPStan cannot infer.

---

## Error Handling

- **Custom exception:** `ImportEnvironmentException extends Exception` — use for all
  package-specific failures.
- **Descriptive messages:** Always include context in the message string.
  `"The \"{$this->target}\" environment does not exist"`
- **Throw early (guard clauses):** Check preconditions at the top of a method and throw/return
  immediately rather than nesting logic.
- **Single top-level catch:** Artisan `handle()` wraps the full workflow in one `try/catch
  (Exception $e)` — do not add nested try/catch blocks unless truly necessary.
- **Nullsafe operator** over manual null checks: `$this->process?->isRunning()`.

```php
// Good — guard clause
if (!array_key_exists($key, $this->config)) {
    throw new ImportEnvironmentException("Missing config key: \"{$key}\"");
}

// Good — top-level handler
try {
    $this->runImport();
} catch (Exception $e) {
    $this->error($e->getMessage());
    $this->sendFailureNotification($e);
    return static::FAILURE;
}
```

---

## Project-Specific Patterns

### Config Access

Never call `config()` directly inside methods. Use the dedicated accessors:

```php
$this->getConfigValue('ssh_host');
$this->getEnvironmentConfigValue('db_type', 'mysql');
```

Both use `Arr::get()` internally for dot-notation support.

### Processor Pattern

Processors are registered in config under `data_processors` / `database_processors`. Two syntaxes:

```php
// Without options
[AnonymizeUsers::class]

// With options (key => options array)
[AnonymizeUsers::class => ['model' => User::class, 'fields' => ['email']]]
```

Implement `applies(): bool` to guard whether the processor should run, and `process(): void` for
the logic.

### Match Expressions

Prefer `match (true)` over `if/elseif` chains for multi-branch routing:

```php
$client = match (true) {
    $this->dbUsesMariaDb() => new MariaDb(),
    $this->dbUsesMongo()   => new MongoDb(),
    default                => new MySql(),
};
```

### Console Output Prefix

Prefix all output lines with the subsystem in brackets:

```php
$this->info('[DB] Starting database import...');
$this->warn('[Files] Skipping rsync — no paths configured');
```

### Named Arguments

Use named arguments for clarity when a function has many parameters or boolean flags:

```php
File::makeDirectory($path, recursive: true);
Artisan::call('optimize:clear', outputBuffer: $this->output);
```

### Backward Compatibility

When renaming config keys, keep the old key working via a fallback and add a comment:

```php
// Backwards compatibility: 'sensitive_tables' was renamed to 'empty_tables'
$tables = $this->getConfigValue('empty_tables')
    ?? $this->getConfigValue('sensitive_tables', []);
```

---

## PHPStan

- Level 5 — no baseline; all new code must pass cleanly.
- Analysed paths: `config/`, `src/` only.
- Run `composer analyse` before opening a PR.
