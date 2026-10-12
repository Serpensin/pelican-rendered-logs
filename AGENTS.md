# AGENTS.md — Rendered Logs for Pelican

This is a Pelican Panel plugin that captures the current Wings console log, renders it as a self-contained terminal-style HTML file, and exposes it through a one-time download URL. It is source-distributed by PelicanHub; this repository has no standalone Composer or Node project.

## Layout

- `src/` — plugin classes under `Serpensin\RenderedLogs`; the Console action, Wings WebSocket client, HTML renderer, download controller, cleanup command, and providers live here.
- `routes/web.php` — the unauthenticated, capability-token download route.
- `database/migrations/` — `rendered_logs` schema.
- `config/` and `lang/{en,de}/` — plugin settings and translations.
- `tests/` — standalone PHP assertion scripts (no PHPUnit configuration).
- `plugin.json` and `update.json` — Pelican metadata and append-only update history.

## Development environment

Develop and install this repository as `plugins/serpensin-rendered-logs` inside a Pelican Panel checkout. Production code uses Panel/Laravel/Filament classes from that host application; do not add a local dependency manifest merely to run the standalone checks.

## Build and test

Run these commands from the repository root. They are the documented maintainer checks:

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/TerminalHtmlDocumentTest.php
python3 -m json.tool plugin.json >/dev/null
python3 -m json.tool update.json >/dev/null
python3 -m py_compile scripts/ci_create_release_tag.py
python3 scripts/ci_create_release_tag.py --dry-run
```

GitLab CI uses PHP 8.3 and runs:

```bash
find src tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/TerminalHtmlDocumentTest.php
php -r "json_decode(file_get_contents('plugin.json'), true, 512, JSON_THROW_ON_ERROR); json_decode(file_get_contents('update.json'), true, 512, JSON_THROW_ON_ERROR);"
```

## Conventions

- Use `Serpensin\RenderedLogs` namespaces matching the `src/` directory. Classes use PascalCase; methods and variables use camelCase.
- Files start with `<?php`, use strict type declarations on parameters/returns where applicable, trailing commas in multiline calls, and 4-space indentation.
- Standalone tests require the source file directly, define local assertion helpers, and throw `RuntimeException` on failure.
- UI copy and notifications go through `trans('serpensin-rendered-logs::strings.…')`; update both `lang/en/strings.php` and `lang/de/strings.php` for new keys.
- Keep security-sensitive failures fail-closed: validate untrusted protocol data, use `random_bytes`, and preserve the controller's locked, consume-before-send flow for download tokens.

## Metadata and releases

- Never commit ZIPs, `dist/`, `vendor/`, or `node_modules/`; they are ignored. Do not create GitLab Releases or Generic Package uploads.
- A release is an immutable `vX.Y.Z` Git tag. When publishing, bump `plugin.json.version`, then set `update.json["*"].version` and its `download_url` to the same tag archive. Keep prior release entries in `update.json`.
- Keep `plugin.json.update_url` on `main`. The `create-release-tag` CI job runs only on the default branch when `plugin.json` or `update.json` changes; it needs `RELEASE_TOKEN` or an allowed `CI_JOB_TOKEN` to create a tag.

## Pitfalls

- The one-time route intentionally bypasses Panel `auth`; the 64-hex-character random token is the bearer capability. Do not add authentication middleware or weaken the route constraint.
- `RenderedLogsPlugin::register()` deliberately uses the Filament Console extension hook rather than the generic Laravel provider path, which is not reliably invoked while the server panel is constructed.
- Cleanup is not automatic: the Panel scheduler must run `php artisan serpensin-rendered-logs:purge` regularly.
