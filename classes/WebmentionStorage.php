<?php

namespace Grav\Plugin\Bridgyfed;

use Grav\Common\Grav;
use Grav\Common\Filesystem\Folder;

/**
 * JSON-based storage for webmentions
 */
class WebmentionStorage
{
    /** @var array */
    protected $config;

    /** @var string */
    protected $basePath;

    public function __construct(array $config)
    {
        $this->config = $config;

        $storagePath = $config['storage']['path'] ?? 'user/data/bridgyfed';
        $this->basePath = Grav::instance()['locator']->findResource('user://') . '/../' . $storagePath;

        // Ensure directory exists
        if (!is_dir($this->basePath)) {
            Folder::create($this->basePath);
        }
    }

    /**
     * Save a webmention
     */
    public function save(string $slug, array $webmention): void
    {
        $filePath = $this->getFilePath($slug);
        $mentions = $this->load($filePath);

        // Check for duplicates by source URL
        $exists = false;
        foreach ($mentions as $i => $existing) {
            if ($existing['source'] === $webmention['source']) {
                // Update existing
                $mentions[$i] = $webmention;
                $exists = true;
                break;
            }
        }

        if (!$exists) {
            $mentions[] = $webmention;
        }

        // Sort by received date (newest first)
        usort($mentions, function ($a, $b) {
            return strtotime($b['received'] ?? '0') - strtotime($a['received'] ?? '0');
        });

        $this->saveFile($filePath, $mentions);

        // Clear cache if enabled
        if ($this->config['cache']['enabled'] ?? true) {
            $this->clearCacheForSlug($slug);
        }
    }

    /**
     * Get all webmentions for a slug
     */
    public function getBySlug(string $slug, ?string $type = null): array
    {
        $filePath = $this->getFilePath($slug);
        $mentions = $this->load($filePath);

        if ($type !== null) {
            $mentions = array_filter($mentions, function ($m) use ($type) {
                return ($m['type'] ?? 'mention') === $type;
            });
        }

        // Sort by date based on config
        $order = $this->config['display']['replies_order'] ?? 'desc';
        usort($mentions, function ($a, $b) use ($order) {
            $dateA = strtotime($a['published'] ?? $a['received'] ?? '0');
            $dateB = strtotime($b['published'] ?? $b['received'] ?? '0');
            return $order === 'desc' ? ($dateB - $dateA) : ($dateA - $dateB);
        });

        return array_values($mentions);
    }

    /**
     * Get counts of interactions by type
     */
    public function getCounts(string $slug): array
    {
        $mentions = $this->getBySlug($slug);

        $counts = [
            'likes' => 0,
            'reposts' => 0,
            'replies' => 0,
            'bookmarks' => 0,
            'mentions' => 0,
            'total' => count($mentions),
        ];

        foreach ($mentions as $m) {
            $type = $m['type'] ?? 'mention';
            switch ($type) {
                case 'like':
                    $counts['likes']++;
                    break;
                case 'repost':
                    $counts['reposts']++;
                    break;
                case 'reply':
                    $counts['replies']++;
                    break;
                case 'bookmark':
                    $counts['bookmarks']++;
                    break;
                default:
                    $counts['mentions']++;
            }
        }

        return $counts;
    }

    /**
     * Delete a specific webmention
     */
    public function delete(string $slug, string $id): bool
    {
        $filePath = $this->getFilePath($slug);
        $mentions = $this->load($filePath);

        $filtered = array_filter($mentions, function ($m) use ($id) {
            return ($m['id'] ?? '') !== $id;
        });

        if (count($filtered) === count($mentions)) {
            return false; // Not found
        }

        $this->saveFile($filePath, array_values($filtered));
        return true;
    }

    /**
     * Delete all webmentions for a slug
     */
    public function deleteAll(string $slug): void
    {
        $filePath = $this->getFilePath($slug);
        if (file_exists($filePath)) {
            unlink($filePath);
        }
    }

    /**
     * Get file path for a slug
     */
    protected function getFilePath(string $slug): string
    {
        // Sanitize slug for filename
        $safeSlug = preg_replace('/[^a-z0-9_-]/i', '_', $slug);
        return $this->basePath . '/' . $safeSlug . '.json';
    }

    /**
     * Load mentions from file
     */
    protected function load(string $filePath): array
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
     * Save mentions to file
     */
    protected function saveFile(string $filePath, array $mentions): void
    {
        $json = json_encode($mentions, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($filePath, $json, LOCK_EX);
    }

    /**
     * Clear Grav cache for a slug
     */
    protected function clearCacheForSlug(string $slug): void
    {
        $grav = Grav::instance();
        $cache = $grav['cache'];

        // Clear the cache key for this page
        $cacheKey = 'bridgyfed_' . md5($slug);
        $cache->delete($cacheKey);
    }
}
