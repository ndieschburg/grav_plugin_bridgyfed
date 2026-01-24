<?php

namespace Grav\Plugin\Bridgyfed;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Sends webmentions to Bridgy Fed
 */
class WebmentionSender
{
    private const BRIDGY_FED_ENDPOINT = 'https://fed.brid.gy/webmention';

    /** @var array */
    protected $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Send webmention for a page to Bridgy Fed
     */
    public function send(Page $page): WebmentionResult
    {
        $source = $page->url(true);
        $target = 'https://fed.brid.gy/';

        // Check post age
        $publishDate = $page->date();
        $maxAgeDays = $this->config['advanced']['max_post_age_days'] ?? 14;
        $maxAgeSeconds = $maxAgeDays * 24 * 60 * 60;

        if ((time() - $publishDate) > $maxAgeSeconds) {
            return new WebmentionResult(false, "Post too old (>" . $maxAgeDays . " days)");
        }

        // Check nobridge
        $header = $page->header();
        if ($header->bridgyfed['nobridge'] ?? false) {
            return new WebmentionResult(false, 'nobridge is set');
        }

        // Send the webmention
        try {
            $endpoint = $this->config['webmention']['bridgy_fed_endpoint'] ?? self::BRIDGY_FED_ENDPOINT;

            $postData = http_build_query([
                'source' => $source,
                'target' => $target,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'User-Agent: Bridgyfed-Grav/1.0 (+https://trucs.hophop.be)',
                    ],
                    'content' => $postData,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $response = @file_get_contents($endpoint, false, $context);

            // Get response code from headers
            $statusCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                        $statusCode = (int) $matches[1];
                    }
                }
            }

            // 201 Created or 202 Accepted = success
            if ($statusCode === 201 || $statusCode === 202) {
                return new WebmentionResult(true, 'Webmention sent successfully');
            }

            return new WebmentionResult(false, "HTTP $statusCode: " . ($response ?: 'No response'));

        } catch (\Exception $e) {
            return new WebmentionResult(false, 'Exception: ' . $e->getMessage());
        }
    }

    /**
     * Send webmention for a reply to a Fediverse post
     */
    public function sendReply(Page $page, string $replyToUrl): WebmentionResult
    {
        $source = $page->url(true);

        try {
            $endpoint = $this->config['webmention']['bridgy_fed_endpoint'] ?? self::BRIDGY_FED_ENDPOINT;

            $postData = http_build_query([
                'source' => $source,
                'target' => $replyToUrl,
            ]);

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => [
                        'Content-Type: application/x-www-form-urlencoded',
                        'User-Agent: Bridgyfed-Grav/1.0 (+https://trucs.hophop.be)',
                    ],
                    'content' => $postData,
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $response = @file_get_contents($endpoint, false, $context);

            $statusCode = 0;
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/^HTTP\/\d+\.\d+\s+(\d+)/', $header, $matches)) {
                        $statusCode = (int) $matches[1];
                    }
                }
            }

            if ($statusCode === 201 || $statusCode === 202) {
                return new WebmentionResult(true, 'Reply webmention sent successfully');
            }

            return new WebmentionResult(false, "HTTP $statusCode: " . ($response ?: 'No response'));

        } catch (\Exception $e) {
            return new WebmentionResult(false, 'Exception: ' . $e->getMessage());
        }
    }
}

/**
 * Result of a webmention send operation
 */
class WebmentionResult
{
    public bool $success;
    public string $message;

    public function __construct(bool $success, string $message)
    {
        $this->success = $success;
        $this->message = $message;
    }
}
