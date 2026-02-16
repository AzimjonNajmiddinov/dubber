<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class QueueMonitorController extends Controller
{
    public function index()
    {
        $pendingJobs = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();

        $pendingByQueue = DB::table('jobs')
            ->select('queue', DB::raw('COUNT(*) as count'))
            ->groupBy('queue')
            ->pluck('count', 'queue')
            ->toArray();

        $failedJobsList = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(50)
            ->get();

        $batches = collect();
        if ($this->tableExists('job_batches')) {
            $batches = DB::table('job_batches')
                ->orderByDesc('created_at')
                ->limit(20)
                ->get();
        }

        return view('admin.queue', compact(
            'pendingJobs',
            'failedJobs',
            'pendingByQueue',
            'failedJobsList',
            'batches',
        ));
    }

    public function retry(string $id)
    {
        Artisan::call('queue:retry', ['id' => [$id]]);

        return back()->with('status', "Retrying job #{$id}");
    }

    public function retryAll()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);

        return back()->with('status', 'Retrying all failed jobs');
    }

    public function delete(string $id)
    {
        Artisan::call('queue:forget', ['id' => $id]);

        return back()->with('status', "Deleted failed job #{$id}");
    }

    public function flush()
    {
        Artisan::call('queue:flush');

        return back()->with('status', 'Flushed all failed jobs');
    }

    private function tableExists(string $table): bool
    {
        try {
            return \Schema::hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
