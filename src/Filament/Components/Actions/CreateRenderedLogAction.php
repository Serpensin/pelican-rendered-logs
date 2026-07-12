<?php

namespace Serpensin\RenderedLogs\Filament\Components\Actions;

use App\Models\Server;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Size;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Serpensin\RenderedLogs\Models\RenderedLog;
use Serpensin\RenderedLogs\Support\TerminalHtmlDocument;
use Serpensin\RenderedLogs\Services\WingsWebsocketLogFetcher;

class CreateRenderedLogAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'create_rendered_log';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(trans('serpensin-rendered-logs::strings.action.label'));
        $this->icon('tabler-terminal-2');
        $this->color('gray');
        $this->size(Size::ExtraLarge);
        $this->tooltip(trans('serpensin-rendered-logs::strings.action.tooltip'));

        $this->action(function (): void {
            /** @var Server $server */
            $server = Filament::getTenant();

            try {
                /** @var \App\Models\User $user */
                $user = user();
                $logs = app(WingsWebsocketLogFetcher::class)->fetch($server, $user);
                $logs = implode("\r\n", array_map(static fn (mixed $line): string => rtrim((string) $line, "\r\n"), $logs));

                if (trim($logs) === '') {
                    Notification::make()->title(trans('serpensin-rendered-logs::strings.create.no_logs_title'))->warning()->send();
                    return;
                }

                $maximum = max(10_000, min(5_000_000, (int) config('serpensin-rendered-logs.max_log_bytes', 500_000)));
                if (strlen($logs) > $maximum) {
                    $logs = trans('serpensin-rendered-logs::strings.create.truncated', ['limit' => number_format($maximum)]) . PHP_EOL . substr($logs, -$maximum);
                }

                $timestamp = now();
                $title = trans('serpensin-rendered-logs::strings.create.document_title', [
                    'server' => $server->name,
                    'timestamp' => $timestamp->format('Y-m-d H:i:s'),
                ]);
                $token = bin2hex(random_bytes(32));
                $downloadName = Str::slug($server->name, '-') . '-' . $timestamp->format('Ymd-His') . '.html';
                $diskPath = trim((string) config('serpensin-rendered-logs.storage_prefix', 'serpensin-rendered-logs'), '/') . '/' . $token . '.html';

                Storage::disk('local')->put($diskPath, TerminalHtmlDocument::render($logs, $title));
                RenderedLog::query()->create([
                    'token' => $token,
                    'disk_path' => $diskPath,
                    'download_name' => $downloadName,
                    'expires_at' => $timestamp->copy()->addMinutes(max(1, min(1440, (int) config('serpensin-rendered-logs.link_ttl_minutes', 60)))),
                ]);

                Notification::make()
                    ->title(trans('serpensin-rendered-logs::strings.create.success_title'))
                    ->body(route('serpensin-rendered-logs.download', ['token' => $token]))
                    ->persistent()
                    ->success()
                    ->send();
            } catch (Exception $exception) {
                report($exception);
                Notification::make()
                    ->title(trans('serpensin-rendered-logs::strings.create.failed_title'))
                    ->body($exception->getMessage())
                    ->danger()
                    ->send();
            }
        });
    }
}
