<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

class RunpodStartServices extends Command
{
    protected $signature = 'runpod:start
        {--install : Install Python dependencies and clone repo (first-time setup)}
        {--no-env-update : Skip auto-updating .env file with service URLs}';

    protected $description = 'SSH into a RunPod pod and start GPU services (Demucs + WhisperX + OpenVoice)';

    private string $sshHost;
    private string $sshKey;

    public function handle(): int
    {
        $this->sshHost = env('RUNPOD_SSH_HOST', '');
        $this->sshKey = env('RUNPOD_SSH_KEY', '');

        if (empty($this->sshHost) || empty($this->sshKey)) {
            $this->error('RUNPOD_SSH_HOST and RUNPOD_SSH_KEY must be set in .env');
            $this->line('  RUNPOD_SSH_HOST=root@<pod-ip>');
            $this->line('  RUNPOD_SSH_KEY=/path/to/runpod_ssh_key');
            return self::FAILURE;
        }

        if (! file_exists($this->sshKey)) {
            $this->error("SSH key not found: {$this->sshKey}");
            return self::FAILURE;
        }

        // Test SSH connectivity
        $this->info('Connecting to RunPod...');
        $result = $this->ssh('echo ok');
        if (! $result->successful() || trim($result->output()) !== 'ok') {
            $this->error('SSH connection failed: ' . $result->errorOutput());
            return self::FAILURE;
        }
        $this->line('  Connected to ' . $this->sshHost);

        // Check if services are already running
        $demucsHealthy = $this->checkHealth(8000);
        $whisperxHealthy = $this->checkHealth(8002);
        $openvoiceHealthy = $this->checkHealth(8005);

        if ($demucsHealthy && $whisperxHealthy && $openvoiceHealthy) {
            $this->info('All services are already running and healthy.');
        } else {
            if ($this->option('install')) {
                $this->installDependencies();
            }

            $this->startServices($demucsHealthy, $whisperxHealthy, $openvoiceHealthy);

            if (! $this->waitForHealth()) {
                $this->error('Services failed to become healthy within timeout.');
                $this->line('Check logs on the pod:');
                $this->line('  tail -f /tmp/demucs.log');
                $this->line('  tail -f /tmp/whisperx.log');
                $this->line('  tail -f /tmp/openvoice.log');
                return self::FAILURE;
            }
        }

        // Update .env
        if (! $this->option('no-env-update')) {
            $this->updateEnv();
        }

        // Print summary
        $this->printSummary();

        return self::SUCCESS;
    }

    private function ssh(string $command, int $timeout = 120): \Illuminate\Contracts\Process\ProcessResult
    {
        return Process::timeout($timeout)->run([
            'ssh',
            '-i', $this->sshKey,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'LogLevel=ERROR',
            $this->sshHost,
            $command,
        ]);
    }

    private function checkHealth(int $port): bool
    {
        $result = $this->ssh("curl -sf http://localhost:{$port}/health 2>/dev/null");
        return $result->successful();
    }

    private function installDependencies(): void
    {
        $this->info('Installing Python dependencies...');

        $commands = implode(' && ', [
            'pip install torch torchaudio --index-url https://download.pytorch.org/whl/cu126',
            'pip install --ignore-installed blinker',
            'pip install whisperx speechbrain fastapi uvicorn python-multipart',
            'pip install --no-deps demucs',
        ]);

        $result = $this->ssh($commands, 600);
        if (! $result->successful()) {
            $this->warn('pip install had issues: ' . $result->errorOutput());
        }

        $this->info('Cloning repository...');

        $cloneCommands = implode(' && ', [
            'cd /workspace',
            'rm -rf dubber',
            'git clone https://github.com/AzimjonNajmiddinov/dubber.git',
        ]);

        $result = $this->ssh($cloneCommands, 120);
        if (! $result->successful()) {
            $this->error('Failed to clone repository: ' . $result->errorOutput());
        }
    }

    private function startServices(bool $demucsRunning, bool $whisperxRunning, bool $openvoiceRunning): void
    {
        $hfToken = env('HF_TOKEN', '');

        // Resolve cuDNN library path for LD_LIBRARY_PATH
        $ldExport = 'export LD_LIBRARY_PATH=$(python -c "import nvidia.cudnn; print(nvidia.cudnn.__path__[0] + \'/lib\')" 2>/dev/null):${LD_LIBRARY_PATH}';

        if (! $demucsRunning) {
            $this->info('Starting Demucs on port 8000...');
            $cmd = "export HF_TOKEN='{$hfToken}' && {$ldExport} && "
                . 'cd /workspace/dubber/demucs-service && '
                . 'nohup python -m uvicorn app_runpod:app --host 0.0.0.0 --port 8000 > /tmp/demucs.log 2>&1 &';
            $this->ssh($cmd);
        } else {
            $this->line('  Demucs already running on port 8000');
        }

        if (! $whisperxRunning) {
            $this->info('Starting WhisperX on port 8002...');
            $cmd = "export HF_TOKEN='{$hfToken}' && {$ldExport} && "
                . 'cd /workspace/dubber/whisperx-service && '
                . 'nohup python -m uvicorn app:app --host 0.0.0.0 --port 8002 > /tmp/whisperx.log 2>&1 &';
            $this->ssh($cmd);
        } else {
            $this->line('  WhisperX already running on port 8002');
        }

        if (! $openvoiceRunning) {
            $this->info('Starting OpenVoice on port 8005...');
            $cmd = "export HF_TOKEN='{$hfToken}' && {$ldExport} && "
                . 'cd /workspace/dubber/openvoice-service && '
                . 'if [ ! -d venv ]; then python -m venv venv && venv/bin/pip install -q -r requirements.txt; fi && '
                . 'nohup venv/bin/python -m uvicorn app:app --host 0.0.0.0 --port 8005 > /tmp/openvoice.log 2>&1 &';
            $this->ssh($cmd);
        } else {
            $this->line('  OpenVoice already running on port 8005');
        }
    }

    private function waitForHealth(): bool
    {
        $this->info('Waiting for services to become healthy...');
        $timeout = 120;
        $interval = 5;
        $elapsed = 0;
        $demucsReady = false;
        $whisperxReady = false;
        $openvoiceReady = false;

        while ($elapsed < $timeout) {
            if (! $demucsReady) {
                $demucsReady = $this->checkHealth(8000);
                if ($demucsReady) {
                    $this->line('  Demucs is healthy');
                }
            }

            if (! $whisperxReady) {
                $whisperxReady = $this->checkHealth(8002);
                if ($whisperxReady) {
                    $this->line('  WhisperX is healthy');
                }
            }

            if (! $openvoiceReady) {
                $openvoiceReady = $this->checkHealth(8005);
                if ($openvoiceReady) {
                    $this->line('  OpenVoice is healthy');
                }
            }

            if ($demucsReady && $whisperxReady && $openvoiceReady) {
                $this->info('All services healthy.');
                return true;
            }

            $this->output->write('.');
            sleep($interval);
            $elapsed += $interval;
        }

        $this->newLine();
        return false;
    }

    private function extractPodId(): ?string
    {
        // RUNPOD_SSH_HOST is typically root@<pod-ip> or root@<pod-id>.runpod.io
        // The pod ID is needed for proxy URLs: https://<pod-id>-<port>.proxy.runpod.net
        // Try to get it from the pod itself
        $result = $this->ssh('echo $RUNPOD_POD_ID');
        $podId = trim($result->output());

        if (! empty($podId)) {
            return $podId;
        }

        // Fallback: try to extract from hostname
        $result = $this->ssh('hostname');
        $hostname = trim($result->output());

        if (! empty($hostname)) {
            return $hostname;
        }

        return null;
    }

    private function updateEnv(): void
    {
        $podId = $this->extractPodId();
        if (! $podId) {
            $this->warn('Could not determine pod ID. Skipping .env update.');
            return;
        }

        $demucsUrl = "https://{$podId}-8000.proxy.runpod.net";
        $whisperxUrl = "https://{$podId}-8002.proxy.runpod.net";
        $openvoiceUrl = "https://{$podId}-8005.proxy.runpod.net";

        $envPath = base_path('.env');
        $content = file_get_contents($envPath);

        $content = $this->setEnvValue($content, 'DEMUCS_SERVICE_URL', $demucsUrl);
        $content = $this->setEnvValue($content, 'WHISPERX_SERVICE_URL', $whisperxUrl);
        $content = $this->setEnvValue($content, 'OPENVOICE_SERVICE_URL', $openvoiceUrl);

        file_put_contents($envPath, $content);

        $this->info('Updated .env:');
        $this->line("  DEMUCS_SERVICE_URL={$demucsUrl}");
        $this->line("  WHISPERX_SERVICE_URL={$whisperxUrl}");
        $this->line("  OPENVOICE_SERVICE_URL={$openvoiceUrl}");
    }

    private function setEnvValue(string $content, string $key, string $value): string
    {
        $pattern = "/^{$key}=.*/m";

        if (preg_match($pattern, $content)) {
            return preg_replace($pattern, "{$key}={$value}", $content);
        }

        // Append if not found
        return rtrim($content) . "\n{$key}={$value}\n";
    }

    private function printSummary(): void
    {
        $podId = $this->extractPodId();

        $this->newLine();
        $this->info('=== RunPod GPU Services Ready ===');
        $this->newLine();

        if ($podId) {
            $this->line("  Demucs:    https://{$podId}-8000.proxy.runpod.net");
            $this->line("  WhisperX:  https://{$podId}-8002.proxy.runpod.net");
            $this->line("  OpenVoice: https://{$podId}-8005.proxy.runpod.net");
        }

        // GPU info
        $gpuResult = $this->ssh('nvidia-smi --query-gpu=name,memory.used,memory.total --format=csv,noheader 2>/dev/null');
        if ($gpuResult->successful() && ! empty(trim($gpuResult->output()))) {
            $this->newLine();
            $this->line('  GPU: ' . trim($gpuResult->output()));
        }

        $this->newLine();
        $this->line('Logs: ssh into pod and run:');
        $this->line('  tail -f /tmp/demucs.log');
        $this->line('  tail -f /tmp/whisperx.log');
        $this->line('  tail -f /tmp/openvoice.log');
    }
}
