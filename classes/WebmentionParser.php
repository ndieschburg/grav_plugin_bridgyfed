<?php

namespace Grav\Plugin\Bridgyfed;

/**
 * Parse Microformats2 from HTML
 *
 * Simple parser for webmention sources. For full mf2 support,
 * consider using php-mf2 library.
 */
class WebmentionParser
{
    /** @var array */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Parse HTML and extract webmention data
     */
    public function parse(string $html, string $sourceUrl): array
    {
        // Try to use php-mf2 if available
        if (class_exists('\Mf2\parse')) {
            return $this->parseWithMf2($html, $sourceUrl);
        }

        // Fallback to simple regex parsing
        return $this->parseSimple($html, $sourceUrl);
    }

    /**
     * Parse using php-mf2 library
     */
    protected function parseWithMf2(string $html, string $sourceUrl): array
    {
        $parsed = \Mf2\parse($html, $sourceUrl);

        // Find h-entry
        $hentry = $this->findHentry($parsed['items'] ?? []);

        if (!$hentry) {
            return [
                'type' => 'mention',
                'content' => '',
                'author' => ['name' => '', 'url' => '', 'photo' => ''],
            ];
        }

        return [
            'type' => $this->detectInteractionType($hentry),
            'author' => $this->extractAuthor($hentry, $parsed),
            'content' => $this->extractContent($hentry),
            'published' => $this->extractPublished($hentry),
            'original_url' => $this->extractOriginalUrl($hentry),
        ];
    }

    /**
     * Simple regex-based parsing (fallback)
     */
    protected function parseSimple(string $html, string $sourceUrl): array
    {
        $result = [
            'type' => 'mention',
            'author' => ['name' => '', 'url' => '', 'photo' => ''],
            'content' => '',
            'published' => null,
            'original_url' => null,
        ];

        // Detect interaction type
        if (preg_match('/class="[^"]*u-like-of[^"]*"/i', $html)) {
            $result['type'] = 'like';
        } elseif (preg_match('/class="[^"]*u-repost-of[^"]*"/i', $html)) {
            $result['type'] = 'repost';
        } elseif (preg_match('/class="[^"]*u-in-reply-to[^"]*"/i', $html)) {
            $result['type'] = 'reply';
        } elseif (preg_match('/class="[^"]*u-bookmark-of[^"]*"/i', $html)) {
            $result['type'] = 'bookmark';
        }

        // Extract author name
        if (preg_match('/class="[^"]*p-name[^"]*"[^>]*>([^<]+)</i', $html, $match)) {
            $result['author']['name'] = trim(html_entity_decode($match[1]));
        }

        // Extract author URL
        if (preg_match('/<a[^>]*class="[^"]*u-url[^"]*"[^>]*href="([^"]+)"/i', $html, $match)) {
            $result['author']['url'] = $match[1];
        }

        // Extract author photo
        if (preg_match('/<img[^>]*class="[^"]*u-photo[^"]*"[^>]*src="([^"]+)"/i', $html, $match)) {
            $result['author']['photo'] = $match[1];
        }

        // Extract content
        if (preg_match('/<[^>]*class="[^"]*e-content[^"]*"[^>]*>(.*?)<\/(?:div|p|article)>/is', $html, $match)) {
            $result['content'] = strip_tags($match[1], '<p><br><a><em><strong>');
        } elseif (preg_match('/<[^>]*class="[^"]*p-content[^"]*"[^>]*>(.*?)<\/(?:div|p|article)>/is', $html, $match)) {
            $result['content'] = strip_tags($match[1], '<p><br><a><em><strong>');
        }

        // Extract published date
        if (preg_match('/<time[^>]*class="[^"]*dt-published[^"]*"[^>]*datetime="([^"]+)"/i', $html, $match)) {
            $result['published'] = $match[1];
        }

        // Extract original URL (u-url)
        if (preg_match('/<a[^>]*class="[^"]*u-url[^"]*"[^>]*href="([^"]+)"/i', $html, $match)) {
            $result['original_url'] = $match[1];
        }

        return $result;
    }

    /**
     * Find h-entry in parsed mf2 items
     */
    protected function findHentry(array $items): ?array
    {
        foreach ($items as $item) {
            $types = $item['type'] ?? [];
            if (in_array('h-entry', $types)) {
                return $item;
            }

            // Check children
            if (!empty($item['children'])) {
                $found = $this->findHentry($item['children']);
                if ($found) {
                    return $found;
                }
            }
        }

        return null;
    }

    /**
     * Detect interaction type from h-entry
     */
    protected function detectInteractionType(array $hentry): string
    {
        $props = $hentry['properties'] ?? [];

        if (!empty($props['like-of'])) return 'like';
        if (!empty($props['repost-of'])) return 'repost';
        if (!empty($props['in-reply-to'])) return 'reply';
        if (!empty($props['bookmark-of'])) return 'bookmark';

        return 'mention';
    }

    /**
     * Extract author from h-entry
     */
    protected function extractAuthor(array $hentry, array $parsed): array
    {
        $author = ['name' => '', 'url' => '', 'photo' => ''];

        // Check p-author in h-entry
        if (isset($hentry['properties']['author'])) {
            $authorData = $hentry['properties']['author'][0];

            if (is_array($authorData) && isset($authorData['properties'])) {
                $props = $authorData['properties'];
                $author['name'] = $props['name'][0] ?? '';
                $author['url'] = $props['url'][0] ?? '';
                $author['photo'] = $props['photo'][0] ?? '';
                return $author;
            }

            if (is_string($authorData)) {
                $author['name'] = $authorData;
                return $author;
            }
        }

        // Fallback: find h-card at root level
        foreach ($parsed['items'] ?? [] as $item) {
            if (in_array('h-card', $item['type'] ?? [])) {
                $props = $item['properties'] ?? [];
                $author['name'] = $props['name'][0] ?? '';
                $author['url'] = $props['url'][0] ?? '';
                $author['photo'] = $props['photo'][0] ?? '';
                break;
            }
        }

        return $author;
    }

    /**
     * Extract content from h-entry
     */
    protected function extractContent(array $hentry): string
    {
        $props = $hentry['properties'] ?? [];

        // Try e-content first
        if (isset($props['content'])) {
            $content = $props['content'][0];
            if (is_array($content)) {
                return $content['html'] ?? $content['value'] ?? '';
            }
            return $content;
        }

        // Fallback to summary or name
        if (isset($props['summary'])) {
            return $props['summary'][0];
        }

        if (isset($props['name'])) {
            return $props['name'][0];
        }

        return '';
    }

    /**
     * Extract published date
     */
    protected function extractPublished(array $hentry): ?string
    {
        $props = $hentry['properties'] ?? [];

        if (isset($props['published'])) {
            return $props['published'][0];
        }

        return null;
    }

    /**
     * Extract original URL (u-url)
     */
    protected function extractOriginalUrl(array $hentry): ?string
    {
        $props = $hentry['properties'] ?? [];

        if (isset($props['url'])) {
            return $props['url'][0];
        }

        return null;
    }
}
