<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (class_exists(\Opcodes\LogViewer\Facades\LogViewer::class)) {
            \Opcodes\LogViewer\Facades\LogViewer::auth(function ($request) {
                return $request->session()->get('admin_authenticated', false);
            });
        }

        config(['app.admin_password' => env('ADMIN_PASSWORD')]);
    }
}
