<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\StorageHealth;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class SystemHealthController extends Controller
{
    public function index()
    {
        return view('admin.system-health.index', [
            'report'      => StorageHealth::report(),
            'lastCleanup' => StorageHealth::lastCleanup(),
        ]);
    }

    /** Clears the application cache, the config cache and the compiled views. Nothing else (no data, no uploads). */
    public function clearCache()
    {
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('view:clear');
        } catch (\Throwable $e) {
            Log::warning('System health: clearing the cache failed: ' . $e->getMessage());

            return back()->with('error', 'The cache could not be cleared completely. Please try again.');
        }

        return back()->with('success', 'Cache, config and compiled views were cleared.');
    }
}
