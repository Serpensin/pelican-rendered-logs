<?php

use Illuminate\Support\Facades\Route;
use Serpensin\RenderedLogs\Http\Controllers\RenderedLogDownloadController;

Route::get('/rendered-logs/{token}', RenderedLogDownloadController::class)
    ->where('token', '[a-f0-9]{64}')
    ->name('serpensin-rendered-logs.download');
