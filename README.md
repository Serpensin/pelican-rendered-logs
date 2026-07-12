# Rendered Logs for Pelican

Creates a **Download Logs** action on **Server → Console**, immediately before Pelican's power controls.

Unlike a log uploader, this plugin never sends a console log to an external service:

1. Pelican fetches the current log from Wings over its existing daemon connection.
2. The Panel renders it into a self-contained HTML attachment with terminal semantics.
3. It stores the attachment privately on the Panel and creates a cryptographically random 256-bit link.
4. Anyone who possesses that link can open it **once**, with or without a Pelican account. The response forces a download, invalidates the link atomically, and deletes the stored attachment after it is sent.

## Rendering

HTML is deliberately used instead of PDF: it is a portable download format and can preserve selectable monospace text and terminal colours without an external renderer. The included renderer handles the controls commonly emitted by game-server logs:

- ANSI SGR styles: normal/bright colours, 256-colour palette, RGB colours, bold, dim, italic, underline, inverse, hidden, strikethrough
- carriage return, newline, backspace and tab
- cursor movement and save/restore cursor
- erase line/screen controls
- OSC metadata sequences (safely consumed, never printed)

Unknown terminal escape sequences are consumed rather than rendered as boxes. The generated document is static; the recipient's browser does not contact Wings or any third-party service.

## Security and retention

- The actual HTML files are stored on the local, non-public Laravel disk under `storage/app/serpensin-rendered-logs/`.
- A 64-hex-character (`random_bytes(32)`) token is an unguessable bearer capability. Do not share it in public channels.
- Link consumption is locked in a database transaction before the attachment response starts, so concurrent/replayed requests fail closed.
- Unused links expire after the configured period (default: 60 minutes). The `serpensin-rendered-logs:purge` command removes expired links and stale files; schedule it every few minutes through the Panel's normal scheduler.
- The HTTP response has `Content-Disposition: attachment`, `Cache-Control: no-store`, and deletes the file after send. A browser cache/download manager outside the Panel cannot be revoked after it has received the attachment.

## Install

1. Copy this directory to `plugins/serpensin-rendered-logs` in the Pelican Panel.
2. In the Panel directory run:

   ```bash
   php artisan p:plugin:install serpensin-rendered-logs
   ```

3. Open **Admin → Plugins → Rendered Logs → Settings** and set:
   - unused-link lifetime (1–1440 minutes)
   - maximum renderable log size (10,000–5,000,000 bytes)
4. Schedule cleanup, for example with Laravel's scheduler:

   ```bash
   php artisan serpensin-rendered-logs:purge
   ```

5. Open a server's **Console**, click **Download Logs**, then copy the notification URL to the intended recipient. Opening the URL starts the download directly; refreshing it returns 404.

## Development checks

```bash
find . -type f -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/TerminalHtmlDocumentTest.php
python3 -m json.tool plugin.json >/dev/null
```

## Compatibility

Uses Pelican's public Console extension hook and the same Wings endpoint as the upstream `mclogs-uploader` pattern:

```php
Console::registerCustomHeaderActions(HeaderActionPosition::Before, CreateRenderedLogAction::make());
Http::daemon($server->node)->get("/api/servers/{$server->uuid}/logs");
```
