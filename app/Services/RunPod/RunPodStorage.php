<?php

namespace App\Services\RunPod;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class RunPodStorage
{
    private string $disk;

    public function __construct(string $disk = 's3-runpod')
    {
        $this->disk = $disk;
    }

    /**
     * Upload a local file to S3 for RunPod worker access.
     *
     * @return string Presigned URL for the uploaded file
     */
    public function upload(string $localPath, string $remotePath): string
    {
        if (!file_exists($localPath)) {
            throw new RuntimeException("File not found: {$localPath}");
        }

        $storage = Storage::disk($this->disk);
        $storage->put($remotePath, file_get_contents($localPath));

        return $this->getPresignedUrl($remotePath);
    }

    /**
     * Download a file from URL to local path.
     */
    public function download(string $url, string $localPath): void
    {
        $dir = dirname($localPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $content = file_get_contents($url);
        if ($content === false) {
            throw new RuntimeException("Failed to download from: {$url}");
        }

        file_put_contents($localPath, $content);
    }

    /**
     * Delete a file from S3.
     */
    public function delete(string $remotePath): void
    {
        Storage::disk($this->disk)->delete($remotePath);
    }

    /**
     * Get a presigned URL for a file in S3.
     */
    public function getPresignedUrl(string $remotePath, int $expiresMinutes = 60): string
    {
        return Storage::disk($this->disk)->temporaryUrl($remotePath, now()->addMinutes($expiresMinutes));
    }
}
