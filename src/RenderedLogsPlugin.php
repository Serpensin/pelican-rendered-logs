<?php

namespace Serpensin\RenderedLogs;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\HeaderActionPosition;
use App\Filament\Server\Pages\Console;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Section;

use Serpensin\RenderedLogs\Filament\Components\Actions\CreateRenderedLogAction;

class RenderedLogsPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    public function getId(): string
    {
        return 'serpensin-rendered-logs';
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() !== 'server') {
            return;
        }

        // Register through the Filament plugin lifecycle. The generic Laravel
        // provider path is not reliably invoked by this live Panel build during
        // server-panel construction, which leaves a Console action invisible.
        Console::registerCustomHeaderActions(
            HeaderActionPosition::Before,
            CreateRenderedLogAction::make(),
        );
    }

    public function boot(Panel $panel): void {}

    /** @return array<string, mixed> */
    public function getSettingsFormData(): array
    {
        return config('serpensin-rendered-logs');
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make(trans('serpensin-rendered-logs::strings.settings.section'))
                ->description(trans('serpensin-rendered-logs::strings.settings.description'))
                ->schema([
                    TextInput::make('link_ttl_minutes')
                        ->label(trans('serpensin-rendered-logs::strings.settings.link_ttl_minutes'))
                        ->helperText(trans('serpensin-rendered-logs::strings.settings.link_ttl_minutes_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(1440)
                        ->required(),
                    TextInput::make('max_log_bytes')
                        ->label(trans('serpensin-rendered-logs::strings.settings.max_log_bytes'))
                        ->helperText(trans('serpensin-rendered-logs::strings.settings.max_log_bytes_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(10_000)
                        ->maxValue(5_000_000)
                        ->required(),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'SERPENSIN_RENDERED_LOGS_LINK_TTL_MINUTES' => (string) ((int) $data['link_ttl_minutes']),
            'SERPENSIN_RENDERED_LOGS_MAX_LOG_BYTES' => (string) ((int) $data['max_log_bytes']),
        ]);

        Notification::make()
            ->title(trans('serpensin-rendered-logs::strings.settings.saved_title'))
            ->body(trans('serpensin-rendered-logs::strings.settings.saved_body'))
            ->success()
            ->send();
    }
}
