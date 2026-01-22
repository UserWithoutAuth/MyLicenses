<?php

namespace App\Services;

/**
 * Rate Limiting Service
 *
 * Protects API endpoints from abuse
 *
 * @package LicenseServer\Services
 */
class RateLimitService
{
    private string $cacheDir;
    private int $maxAttempts;
    private int $decayMinutes;

    public function __construct()
    {
        $this->cacheDir = storage_path('cache/rate-limits');
        $this->maxAttempts = (int) env('RATE_LIMIT_MAX_ATTEMPTS', 60);
        $this->decayMinutes = (int) env('RATE_LIMIT_DECAY_MINUTES', 1);

        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Attempt to perform an action
     *
     * @param string $key
     * @param int|null $maxAttempts
     * @return bool
     */
    public function attempt(string $key, ?int $maxAttempts = null): bool
    {
        $maxAttempts = $maxAttempts ?? $this->maxAttempts;
        $attempts = $this->getAttempts($key);

        if ($attempts >= $maxAttempts) {
            return false;
        }

        $this->incrementAttempts($key);
        return true;
    }

    /**
     * Get number of attempts
     *
     * @param string $key
     * @return int
     */
    public function getAttempts(string $key): int
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data || $data['expires_at'] < time()) {
            $this->clearAttempts($key);
            return 0;
        }

        return $data['attempts'];
    }

    /**
     * Increment attempts
     *
     * @param string $key
     * @return void
     */
    private function incrementAttempts(string $key): void
    {
        $file = $this->getFilePath($key);
        $attempts = $this->getAttempts($key);

        $data = [
            'attempts' => $attempts + 1,
            'expires_at' => time() + ($this->decayMinutes * 60)
        ];

        file_put_contents($file, json_encode($data));
    }

    /**
     * Clear attempts
     *
     * @param string $key
     * @return void
     */
    public function clearAttempts(string $key): void
    {
        $file = $this->getFilePath($key);

        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Get available time in seconds
     *
     * @param string $key
     * @return int
     */
    public function availableIn(string $key): int
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return 0;
        }

        $data = json_decode(file_get_contents($file), true);

        if (!$data) {
            return 0;
        }

        $availableAt = $data['expires_at'] - time();
        return max(0, $availableAt);
    }

    /**
     * Get file path for key
     *
     * @param string $key
     * @return string
     */
    private function getFilePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.json';
    }

    /**
     * Clean up expired rate limit files
     *
     * @return void
     */
    public function cleanup(): void
    {
        $files = glob($this->cacheDir . '/*.json');

        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);

            if ($data && $data['expires_at'] < time()) {
                unlink($file);
            }
        }
    }
}
