<?php

namespace Serpensin\RenderedLogs\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Serpensin\RenderedLogs\Models\RenderedLog;

class PurgeRenderedLogsCommand extends Command
{
    protected $signature = 'serpensin-rendered-logs:purge';
    public function __construct()
    {
        parent::__construct();
        $this->description = trans('serpensin-rendered-logs::strings.command.description');
    }

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $expired = RenderedLog::query()->where('expires_at', '<=', now())->get();
        foreach ($expired as $entry) {
            $disk->delete($entry->disk_path);
            $entry->delete();
        }

        $prefix = trim((string) config('serpensin-rendered-logs.storage_prefix', 'serpensin-rendered-logs'), '/');
        $cutoff = now()->subDay()->getTimestamp();
        foreach ($disk->files($prefix) as $path) {
            if ($disk->lastModified($path) < $cutoff) {
                $disk->delete($path);
            }
        }

        $this->info(trans('serpensin-rendered-logs::strings.command.purged', ['count' => $expired->count()]));

        return self::SUCCESS;
    }
}
