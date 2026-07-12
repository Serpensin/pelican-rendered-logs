<?php

namespace Serpensin\RenderedLogs\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

class RenderedLogsRoutesProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function (): void {
            // Deliberately outside the Panel auth group: the random, single-use
            // token is the capability. It must work for recipients without accounts.
            Route::withoutMiddleware(['auth'])
                ->group(plugin_path('serpensin-rendered-logs', 'routes/web.php'));
        });
    }
}
