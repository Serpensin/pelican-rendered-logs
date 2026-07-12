<?php

namespace Serpensin\RenderedLogs\Support;

class TerminalHtmlDocument
{
    /**
     * Produces a self-contained, static HTML download. Rendering happens on the
     * panel server, so no third-party viewer, CDN, or browser-side log fetch is used.
     */
    public static function render(string $logs, string $title): string
    {
        $terminal = new TerminalScreen();
        $terminal->write($logs);

        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return "<!doctype html>\n"
            . '<html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $safeTitle . '</title>'
            . '<style>body{margin:0;background:#111;color:#ddd;padding:1rem}pre{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font:14px/1.35 ui-monospace,SFMono-Regular,Menlo,Consolas,"Liberation Mono",monospace;tab-size:4}.dim{opacity:.65}.bold{font-weight:700}.italic{font-style:italic}.underline{text-decoration:underline}.strike{text-decoration:line-through}</style>'
            . '</head><body><pre>' . $terminal->toHtml() . '</pre></body></html>';
    }
}

/**
 * Small terminal buffer for console logs. It intentionally implements the
 * terminal controls commonly emitted by game servers: SGR colours (including
 * 256/RGB), carriage return/backspace/tab, cursor movement, erase line/screen,
 * save/restore cursor, and OSC title sequences. Unknown escape sequences are
 * consumed rather than leaked into the rendered log.
 */
class TerminalScreen
{
    private array $rows = [[]];
    private int $row = 0;
    private int $column = 0;
    private int $savedRow = 0;
    private int $savedColumn = 0;
    private array $style = [];

    public function write(string $input): void
    {
        $input = str_replace(["\r\n", "\r\n"], ["\n", "\n"], $input);
        $offset = 0;
        $length = strlen($input);

        while ($offset < $length) {
            if ($input[$offset] === "\x1b") {
                $consumed = $this->consumeEscape($input, $offset);
                if ($consumed > 0) {
                    $offset += $consumed;
                    continue;
                }

                $offset++;
                continue;
            }

            $nextEscape = strpos($input, "\x1b", $offset);
            $chunk = substr($input, $offset, $nextEscape === false ? null : $nextEscape - $offset);
            $this->writeText($chunk);
            $offset += strlen($chunk);
        }
    }

    public function toHtml(): string
    {
        $html = [];
        foreach ($this->rows as $cells) {
            $html[] = $this->renderRow($cells);
        }

        return implode("\n", $html);
    }

    private function consumeEscape(string $input, int $offset): int
    {
        $remaining = substr($input, $offset);

        if (preg_match('/^\x1b\[([0-?]*)([ -\/]*)([@-~])/', $remaining, $matches) === 1) {
            $this->applyCsi($matches[1], $matches[3]);

            return strlen($matches[0]);
        }

        // OSC: title/hyperlink metadata; it does not belong in visual log text.
        if (str_starts_with($remaining, "\x1b]")) {
            if (preg_match('/^\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)/', $remaining, $matches) === 1) {
                return strlen($matches[0]);
            }

            return strlen($remaining);
        }

        if (str_starts_with($remaining, "\x1b7")) {
            $this->savedRow = $this->row;
            $this->savedColumn = $this->column;

            return 2;
        }

        if (str_starts_with($remaining, "\x1b8")) {
            $this->row = $this->savedRow;
            $this->column = $this->savedColumn;
            $this->ensureRow($this->row);

            return 2;
        }

        return 1;
    }

    private function writeText(string $text): void
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            $characters = str_split($text);
        }

        foreach ($characters as $character) {
            match ($character) {
                "\n" => $this->lineFeed(),
                "\r" => $this->column = 0,
                "\b" => $this->column = max(0, $this->column - 1),
                "\t" => $this->column = (int) (floor($this->column / 8) + 1) * 8,
                default => $this->put($character),
            };
        }
    }

    private function applyCsi(string $parameterString, string $command): void
    {
        $parameters = $parameterString === '' ? [] : array_map(
            static fn (string $value): int => $value === '' ? 0 : (int) $value,
            explode(';', ltrim($parameterString, '?')),
        );
        $amount = max(1, $parameters[0] ?? 1);

        switch ($command) {
            case 'm':
                $this->applySgr($parameters === [] ? [0] : $parameters);
                break;
            case 'A': $this->row = max(0, $this->row - $amount); break;
            case 'B': $this->row += $amount; $this->ensureRow($this->row); break;
            case 'C': $this->column += $amount; break;
            case 'D': $this->column = max(0, $this->column - $amount); break;
            case 'E': $this->row += $amount; $this->column = 0; $this->ensureRow($this->row); break;
            case 'F': $this->row = max(0, $this->row - $amount); $this->column = 0; break;
            case 'G': $this->column = max(0, $amount - 1); break;
            case 'd': $this->row = max(0, $amount - 1); $this->ensureRow($this->row); break;
            case 'H':
            case 'f':
                $this->row = max(0, ($parameters[0] ?? 1) - 1);
                $this->column = max(0, ($parameters[1] ?? 1) - 1);
                $this->ensureRow($this->row);
                break;
            case 'J': $this->eraseScreen($parameters[0] ?? 0); break;
            case 'K': $this->eraseLine($parameters[0] ?? 0); break;
            case 's': $this->savedRow = $this->row; $this->savedColumn = $this->column; break;
            case 'u': $this->row = $this->savedRow; $this->column = $this->savedColumn; $this->ensureRow($this->row); break;
        }
    }

    private function applySgr(array $parameters): void
    {
        for ($index = 0, $count = count($parameters); $index < $count; $index++) {
            $code = $parameters[$index];
            if ($code === 0) { $this->style = []; continue; }
            if ($code === 1) { $this->style['bold'] = true; continue; }
            if ($code === 2) { $this->style['dim'] = true; continue; }
            if ($code === 3) { $this->style['italic'] = true; continue; }
            if ($code === 4) { $this->style['underline'] = true; continue; }
            if ($code === 5 || $code === 6) { $this->style['blink'] = true; continue; }
            if ($code === 7) { $this->style['inverse'] = true; continue; }
            if ($code === 8) { $this->style['hidden'] = true; continue; }
            if ($code === 9) { $this->style['strike'] = true; continue; }
            if ($code === 22) { unset($this->style['bold'], $this->style['dim']); continue; }
            if ($code === 23) { unset($this->style['italic']); continue; }
            if ($code === 24) { unset($this->style['underline']); continue; }
            if ($code === 25) { unset($this->style['blink']); continue; }
            if ($code === 27) { unset($this->style['inverse']); continue; }
            if ($code === 28) { unset($this->style['hidden']); continue; }
            if ($code === 29) { unset($this->style['strike']); continue; }
            if ($code === 39) { unset($this->style['fg']); continue; }
            if ($code === 49) { unset($this->style['bg']); continue; }
            if ($code >= 30 && $code <= 37) { $this->style['fg'] = $this->ansiColor($code - 30, false); continue; }
            if ($code >= 40 && $code <= 47) { $this->style['bg'] = $this->ansiColor($code - 40, false); continue; }
            if ($code >= 90 && $code <= 97) { $this->style['fg'] = $this->ansiColor($code - 90, true); continue; }
            if ($code >= 100 && $code <= 107) { $this->style['bg'] = $this->ansiColor($code - 100, true); continue; }

            if (($code === 38 || $code === 48) && isset($parameters[$index + 1])) {
                $target = $code === 38 ? 'fg' : 'bg';
                if ($parameters[$index + 1] === 5 && isset($parameters[$index + 2])) {
                    $this->style[$target] = $this->color256($parameters[$index + 2]);
                    $index += 2;
                } elseif ($parameters[$index + 1] === 2 && isset($parameters[$index + 4])) {
                    $this->style[$target] = sprintf('rgb(%d,%d,%d)', ...array_map(static fn (int $v): int => max(0, min(255, $v)), array_slice($parameters, $index + 2, 3)));
                    $index += 4;
                }
            }
        }
    }

    private function put(string $character): void
    {
        $this->ensureRow($this->row);
        while (count($this->rows[$this->row]) < $this->column) {
            $this->rows[$this->row][] = [' ', []];
        }
        $this->rows[$this->row][$this->column] = [$character, $this->style];
        $this->column++;
    }

    private function lineFeed(): void
    {
        // Wings returns an array of log records, while Pelican's xterm frontend
        // renders each record with terminal.writeln(). Match that display model:
        // a record newline advances to a fresh column rather than preserving the
        // cursor column as a raw LF control would do.
        $this->row++;
        $this->column = 0;
        $this->ensureRow($this->row);
    }

    private function ensureRow(int $row): void
    {
        while (count($this->rows) <= $row) {
            $this->rows[] = [];
        }
    }

    private function eraseLine(int $mode): void
    {
        $this->ensureRow($this->row);
        if ($mode === 2) { $this->rows[$this->row] = []; return; }
        if ($mode === 1) {
            for ($i = 0; $i <= $this->column; $i++) { $this->rows[$this->row][$i] = [' ', []]; }
            return;
        }
        $this->rows[$this->row] = array_slice($this->rows[$this->row], 0, $this->column);
    }

    private function eraseScreen(int $mode): void
    {
        if ($mode === 2 || $mode === 3) { $this->rows = [[]]; $this->row = 0; $this->column = 0; return; }
        if ($mode === 1) {
            for ($row = 0; $row < $this->row; $row++) { $this->rows[$row] = []; }
            $this->eraseLine(1);
            return;
        }
        $this->eraseLine(0);
        for ($row = $this->row + 1, $count = count($this->rows); $row < $count; $row++) { $this->rows[$row] = []; }
    }

    private function renderRow(array $cells): string
    {
        $html = '';
        $currentStyle = null;
        $buffer = '';
        foreach ($cells as [$character, $style]) {
            $styleKey = serialize($style);
            if ($currentStyle !== null && $styleKey !== $currentStyle) {
                $html .= $this->span($buffer, unserialize($currentStyle));
                $buffer = '';
            }
            $currentStyle = $styleKey;
            $buffer .= $character;
        }
        return $currentStyle === null ? '' : $html . $this->span($buffer, unserialize($currentStyle));
    }

    private function span(string $text, array $style): string
    {
        $classes = [];
        foreach (['dim', 'bold', 'italic', 'underline', 'strike'] as $class) {
            if (isset($style[$class])) { $classes[] = $class; }
        }
        $css = [];
        $foreground = $style['fg'] ?? null;
        $background = $style['bg'] ?? null;
        if (isset($style['inverse'])) { [$foreground, $background] = [$background ?? '#111', $foreground ?? '#ddd']; }
        if ($foreground !== null) { $css[] = 'color:' . $foreground; }
        if ($background !== null) { $css[] = 'background-color:' . $background; }
        if (isset($style['hidden'])) { $css[] = 'color:transparent'; }
        if (isset($style['blink'])) { $css[] = 'text-decoration:blink'; }
        $attributes = ($classes === [] ? '' : ' class="' . implode(' ', $classes) . '"')
            . ($css === [] ? '' : ' style="' . implode(';', $css) . '"');

        return '<span' . $attributes . '>' . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }

    private function ansiColor(int $color, bool $bright): string
    {
        $normal = ['#000000', '#cd3131', '#0dbc79', '#e5e510', '#2472c8', '#bc3fbc', '#11a8cd', '#e5e5e5'];
        $brightPalette = ['#666666', '#f14c4c', '#23d18b', '#f5f543', '#3b8eea', '#d670d6', '#29b8db', '#ffffff'];
        return ($bright ? $brightPalette : $normal)[$color];
    }

    private function color256(int $color): string
    {
        $color = max(0, min(255, $color));
        if ($color < 8) { return $this->ansiColor($color, false); }
        if ($color < 16) { return $this->ansiColor($color - 8, true); }
        if ($color >= 232) { $grey = 8 + (($color - 232) * 10); return sprintf('rgb(%d,%d,%d)', $grey, $grey, $grey); }
        $color -= 16;
        $levels = [0, 95, 135, 175, 215, 255];
        return sprintf('rgb(%d,%d,%d)', $levels[intdiv($color, 36)], $levels[intdiv($color % 36, 6)], $levels[$color % 6]);
    }
}
