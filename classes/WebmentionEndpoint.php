<?php

namespace Grav\Plugin\Bridgyfed;

use Grav\Common\Grav;

/**
 * Handles incoming webmention requests
 */
class WebmentionEndpoint
{
    /** @var array */
    protected $config;

    /** @var WebmentionStorage */
    protected $storage;

    /** @var RateLimiter */
    protected $rateLimiter;

    /** @var WebmentionParser */
    protected $parser;

    public function __construct(array $config, WebmentionStorage $storage)
    {
        $this->config = $config;
        $this->storage = $storage;
        $this->rateLimiter = new RateLimiter($config);
        $this->parser = new WebmentionParser($config);
    }

    /**
     * Handle incoming webmention request
     */
    public function handle(): array
    {
        // 1. Validate method
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return $this->response(405, ['error' => 'Method Not Allowed']);
        }

        // 2. Check rate limit
        $clientIp = $this->getClientIp();
        if (!$this->rateLimiter->check($clientIp)) {
            return $this->response(429, ['error' => 'Too Many Requests']);
        }

        // 3. Extract source and target
        $source = $_POST['source'] ?? '';
        $target = $_POST['target'] ?? '';

        if (!$source || !$target) {
            return $this->response(400, ['error' => 'Missing source or target']);
        }

        // 4. Validate URLs
        if (!$this->isValidUrl($source) || !$this->isValidUrl($target)) {
            return $this->response(400, ['error' => 'Invalid URL']);
        }

        // 5. Check allowed source
        if (!$this->isAllowedSource($source)) {
            return $this->response(403, ['error' => 'Source not allowed']);
        }

        // 6. Find target page
        $page = $this->findPageByUrl($target);
        if (!$page) {
            return $this->response(400, ['error' => 'Target not found']);
        }

        // 7. Fetch and parse source
        try {
            $html = $this->fetchSource($source);
            $data = $this->parser->parse($html, $source);
        } catch (\Exception $e) {
            Grav::instance()['log']->error('Bridgy Fed: Failed to fetch source - ' . $e->getMessage());
            return $this->response(400, ['error' => 'Failed to fetch source']);
        }

        // 8. Determine interaction type
        $type = $data['type'] ?? 'mention';

        // 9. Store the webmention
        $webmention = [
            'id' => $this->generateId($source),
            'source' => $source,
            'target' => $target,
            'type' => $type,
            'author' => $data['author'] ?? ['name' => '', 'url' => '', 'photo' => ''],
            'content' => $this->sanitizeContent($data['content'] ?? ''),
            'published' => $data['published'] ?? null,
            'received' => date('c'),
            'original_url' => $data['original_url'] ?? null,
        ];

        $this->storage->save($page->slug(), $webmention);

        // 10. Log
        Grav::instance()['log']->info('Bridgy Fed: Received ' . $type . ' webmention for ' . $page->route());

        // 11. Return 202 Accepted
        return $this->response(202, ['status' => 'Accepted', 'id' => $webmention['id']]);
    }

    /**
     * Build response array
     */
    protected function response(int $status, array $body): array
    {
        return [
            'status' => $status,
            'body' => $body,
        ];
    }

    /**
     * Get client IP address
     */
    protected function getClientIp(): string
    {
        // Check for forwarded IP (common in proxied setups)
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        }

        if (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            return $_SERVER['HTTP_X_REAL_IP'];
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Validate URL format
     */
    protected function isValidUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return false;
        }

        // Only allow http and https
        if (!in_array($parsed['scheme'], ['http', 'https'])) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Check if source domain is allowed
     */
    protected function isAllowedSource(string $source): bool
    {
        $allowedSources = $this->config['security']['allowed_sources'] ?? ['fed.brid.gy', 'brid.gy'];

        $sourceHost = parse_url($source, PHP_URL_HOST);

        foreach ($allowedSources as $allowed) {
            // Check exact match or subdomain
            if ($sourceHost === $allowed || str_ends_with($sourceHost, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find Grav page by URL
     */
    protected function findPageByUrl(string $url): ?\Grav\Common\Page\Page
    {
        $grav = Grav::instance();
        $pages = $grav['pages'];

        // Parse the URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '/';

        // Remove language prefix if multilang
        $languages = $grav['language']->getLanguages();
        if (!empty($languages)) {
            foreach ($languages as $lang) {
                if (strpos($path, '/' . $lang . '/') === 0) {
                    $path = substr($path, strlen('/' . $lang));
                    break;
                }
            }
        }

        // Find the page
        $page = $pages->find($path);

        return $page instanceof \Grav\Common\Page\Page ? $page : null;
    }

    /**
     * Fetch HTML from source URL
     */
    protected function fetchSource(string $url): string
    {
        $timeout = $this->config['security']['fetch_timeout'] ?? 5;
        $maxSize = $this->config['security']['max_content_size'] ?? 1048576;

        $context = stream_context_create([
            'http' => [
                'timeout' => $timeout,
                'user_agent' => 'Bridgyfed-Grav/1.0 (+https://trucs.hophop.be)',
                'follow_location' => true,
                'max_redirects' => 5,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context, 0, $maxSize);

        if ($content === false) {
            throw new \RuntimeException('Failed to fetch URL: ' . $url);
        }

        return $content;
    }

    /**
     * Generate unique ID for webmention
     */
    protected function generateId(string $source): string
    {
        return 'wm_' . substr(md5($source . microtime()), 0, 12);
    }

    /**
     * Sanitize HTML content
     */
    protected function sanitizeContent(string $content): string
    {
        $maxLength = $this->config['security']['max_content_length'] ?? 2000;

        if (!($this->config['security']['sanitize_html'] ?? true)) {
            return mb_substr($content, 0, $maxLength);
        }

        // Allowed tags
        $allowedTags = '<p><br><a><em><strong><b><i><u><s><blockquote><pre><code><ul><ol><li><img>';

        // Strip tags
        $content = strip_tags($content, $allowedTags);

        // Remove dangerous attributes
        $content = preg_replace('/\s*on\w+="[^"]*"/i', '', $content);
        $content = preg_replace('/\s*on\w+=\'[^\']*\'/i', '', $content);

        // Clean img tags - only allow src and alt
        $content = preg_replace_callback(
            '/<img\s+([^>]*)>/i',
            function ($match) {
                $attrs = $match[1];
                $newAttrs = [];

                if (preg_match('/src="([^"]*)"/i', $attrs, $srcMatch)) {
                    $newAttrs[] = 'src="' . htmlspecialchars($srcMatch[1]) . '"';
                }
                if (preg_match('/alt="([^"]*)"/i', $attrs, $altMatch)) {
                    $newAttrs[] = 'alt="' . htmlspecialchars($altMatch[1]) . '"';
                }

                return '<img ' . implode(' ', $newAttrs) . '>';
            },
            $content
        );

        // Clean a tags - only allow href
        $content = preg_replace_callback(
            '/<a\s+([^>]*)>(.*?)<\/a>/is',
            function ($match) {
                $attrs = $match[1];
                $text = $match[2];

                if (preg_match('/href="([^"]*)"/i', $attrs, $hrefMatch)) {
                    $href = htmlspecialchars($hrefMatch[1]);
                    return '<a href="' . $href . '" rel="nofollow noopener">' . $text . '</a>';
                }

                return $text;
            },
            $content
        );

        // Truncate
        if (mb_strlen($content) > $maxLength) {
            $content = mb_substr($content, 0, $maxLength) . '...';
        }

        return $content;
    }
}
