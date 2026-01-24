<?php

namespace Grav\Plugin\Bridgyfed;

use Grav\Common\Grav;
use Grav\Common\Filesystem\Folder;

/**
 * Simple file-based rate limiter
 */
class RateLimiter
{
    /** @var array */
    protected $config;

    /** @var string */
    protected $storagePath;

    /** @var bool */
    protected $enabled;

    /** @var int */
    protected $maxRequests;

    /** @var int */
    protected $windowSeconds;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->enabled = $config['security']['rate_limit']['enabled'] ?? true;
        $this->maxRequests = $config['security']['rate_limit']['max_requests'] ?? 10;
        $this->windowSeconds = $config['security']['rate_limit']['window_seconds'] ?? 60;

        $basePath = $config['storage']['path'] ?? 'user/data/bridgyfed';
        $this->storagePath = Grav::instance()['locator']->findResource('user://') . '/../' . $basePath . '/rate_limits';

        if ($this->enabled && !is_dir($this->storagePath)) {
            Folder::create($this->storagePath);
        }
    }

    /**
     * Check if request is allowed for IP
     */
    public function check(string $ip): bool
    {
        if (!$this->enabled) {
            return true;
        }

        $filePath = $this->getFilePath($ip);
        $now = time();
        $windowStart = $now - $this->windowSeconds;

        // Load existing requests
        $requests = $this->loadRequests($filePath);

        // Filter to only requests within window
        $requests = array_filter($requests, function ($timestamp) use ($windowStart) {
            return $timestamp >= $windowStart;
        });

        // Check if limit exceeded
        if (count($requests) >= $this->maxRequests) {
            return false;
        }

        // Add current request
        $requests[] = $now;

        // Save
        $this->saveRequests($filePath, $requests);

        return true;
    }

    /**
     * Get remaining requests for IP
     */
    public function remaining(string $ip): int
    {
        if (!$this->enabled) {
            return PHP_INT_MAX;
        }

        $filePath = $this->getFilePath($ip);
        $windowStart = time() - $this->windowSeconds;

        $requests = $this->loadRequests($filePath);
        $requests = array_filter($requests, function ($timestamp) use ($windowStart) {
            return $timestamp >= $windowStart;
        });

        return max(0, $this->maxRequests - count($requests));
    }

    /**
     * Reset rate limit for IP
     */
    public function reset(string $ip): void
    {
        $filePath = $this->getFilePath($ip);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Clean up old rate limit files
     */
    public function cleanup(): void
    {
        if (!$this->enabled || !is_dir($this->storagePath)) {
            return;
        }

        $cutoff = time() - ($this->windowSeconds * 2);

        foreach (glob($this->storagePath . '/*.json') as $file) {
            if (filemtime($file) < $cutoff) {
                unlink($file);
            }
        }
    }

    /**
     * Get file path for IP
     */
    protected function getFilePath(string $ip): string
    {
        // Hash IP for privacy
        $hash = md5($ip);
        return $this->storagePath . '/' . $hash . '.json';
    }

    /**
     * Load request timestamps from file
     */
    protected function loadRequests(string $filePath): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        $content = file_get_contents($filePath);
        if (!$content) {
            return [];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Save request timestamps to file
     */
    protected function saveRequests(string $filePath, array $requests): void
    {
        // Keep only last N requests to prevent file growth
        $requests = array_slice($requests, -$this->maxRequests * 2);

        file_put_contents($filePath, json_encode($requests), LOCK_EX);
    }
}
