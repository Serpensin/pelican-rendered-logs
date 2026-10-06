# Development Notes

This document is for maintainers. Keep `README.md` limited to the functionality, security model, configuration, installation, and normal operation visible to Panel users.

## Distribution model

PelicanHub consumes the repository source directly. Do not create GitLab Releases, Generic Package uploads, committed ZIP files, or other generated release artifacts.

A published version is an immutable Git tag:

1. Bump `plugin.json.version`.
2. Append a matching entry under `update.json.releases`.
3. Make the top-level `update.json["*"]` entry identical to the current release entry.
4. Merge or push the change to the default branch.
5. `create-release-tag` verifies the active metadata and creates the matching `vX.Y.Z` tag.

`plugin.json.update_url` must keep pointing at the default branch's `update.json`, allowing installed copies to discover future versions. Each `download_url` must point to GitLab's source archive for the matching immutable tag:

```text
https://gitlab.com/Serpensin/pelican-rendered-logs/-/archive/vX.Y.Z/pelican-rendered-logs-vX.Y.Z.zip
```

## Update history

`update.json.releases` is append-only. Never remove or rewrite prior published versions: existing installations and historical download links rely on them. `*` is the active descriptor consumed by Pelican and must refer to the same version and archive URL as the current `plugin.json` version.

## CI

The default-branch pipeline runs PHP syntax checks, renderer tests, JSON validation, secret detection, and the narrow `create-release-tag` job. The tag job considers only the active manifest version; historical release entries must never cause retroactive tag creation.

## Development checks

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/TerminalHtmlDocumentTest.php
python3 -m json.tool plugin.json >/dev/null
python3 -m json.tool update.json >/dev/null
python3 -m py_compile scripts/ci_create_release_tag.py
python3 scripts/ci_create_release_tag.py --dry-run
```

## Compatibility implementation

The Console action uses Pelican's public Console extension hook and the same Wings log endpoint as the upstream `mclogs-uploader` pattern:

```php
Console::registerCustomHeaderActions(HeaderActionPosition::Before, CreateRenderedLogAction::make());
Http::daemon($server->node)->get("/api/servers/{$server->uuid}/logs");
```
