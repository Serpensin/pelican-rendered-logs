<?php

namespace Serpensin\RenderedLogs\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Serpensin\RenderedLogs\Models\RenderedLog;

class RenderedLogDownloadController
{
    public function __invoke(string $token): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $renderedLog = DB::transaction(function () use ($token): ?RenderedLog {
            $entry = RenderedLog::query()
                ->where('token', $token)
                ->where('expires_at', '>', now())
                ->whereNull('consumed_at')
                ->lockForUpdate()
                ->first();

            if ($entry === null) {
                return null;
            }

            // Marking the link before the response is sent makes parallel/replayed
            // requests fail closed; the file itself is removed after streaming.
            $entry->update(['consumed_at' => now()]);

            return $entry;
        });

        abort_if($renderedLog === null, 404);

        $absolutePath = Storage::disk('local')->path($renderedLog->disk_path);
        if (!is_file($absolutePath)) {
            $renderedLog->delete();
            abort(404);
        }

        // The database record cannot be reused after this point. Symfony removes
        // the actual file once the automatic attachment response has been sent.
        $renderedLog->delete();

        return response()
            ->download($absolutePath, $renderedLog->download_name, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store, private',
            ])
            ->deleteFileAfterSend(true);
    }
}
