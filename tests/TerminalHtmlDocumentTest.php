<?php

require dirname(__DIR__) . '/src/Support/TerminalHtmlDocument.php';

use Serpensin\RenderedLogs\Support\TerminalHtmlDocument;

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message . "\nMissing: {$needle}");
    }
}

$document = TerminalHtmlDocument::render(
    "normal \x1b[31mred\x1b[0m \x1b[38;5;208morange\x1b[0m \x1b[38;2;1;2;3mrgb\x1b[0m\nabc\rZ\x1b[K\nA\bB",
    '<unsafe title>',
);

assertContains('<title>&lt;unsafe title&gt;</title>', $document, 'The title must be HTML escaped.');
assertContains('color:#cd3131', $document, 'Basic SGR foreground colour was not rendered.');
assertContains('color:rgb(255,135,0)', $document, '256-colour SGR was not rendered.');
assertContains('color:rgb(1,2,3)', $document, 'True-colour SGR was not rendered.');
assertContains('>Z</span>', $document, 'Carriage return / erase-line handling was not rendered.');
if (str_contains($document, '>Zbc</span>')) {
    throw new RuntimeException('Erase-line control did not clear the remainder of the line.');
}
assertContains('B', $document, 'Backspace handling was not rendered.');
if (str_contains($document, "\x1b[")) {
    throw new RuntimeException('ANSI escape sequence leaked into the rendered document.');
}

$cleared = TerminalHtmlDocument::render("secret\x1b[2Jfresh", 'clear test');
assertContains('fresh', $cleared, 'Erase-screen handling was not rendered.');
if (str_contains($cleared, 'secret')) {
    throw new RuntimeException('Erase-screen control leaked cleared text.');
}

fwrite(STDOUT, "renderer assertions passed\n");
