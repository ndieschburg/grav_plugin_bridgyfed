<?php

namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Uri;
use Grav\Plugin\Bridgyfed\MicroformatsInjector;
use Grav\Plugin\Bridgyfed\WebmentionEndpoint;
use Grav\Plugin\Bridgyfed\WebmentionSender;
use Grav\Plugin\Bridgyfed\WebmentionStorage;
use RocketTheme\Toolbox\Event\Event;

/**
 * Bridgy Fed Plugin
 *
 * Connect your Grav site to the Fediverse via Bridgy Fed.
 * Handles microformats injection, webmention sending/receiving, and well-known redirects.
 */
class BridgyfedPlugin extends Plugin
{
    /** @var MicroformatsInjector */
    protected $injector;

    /** @var WebmentionStorage */
    protected $storage;

    /**
     * @return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100000],
                ['onPluginsInitialized', 0]
            ],
        ];
    }

    /**
     * Composer autoload
     *
     * @return ClassLoader
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Initialize the plugin
     */
    public function onPluginsInitialized(): void
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->enable([
                'onAdminSave' => ['onAdminSave', 0],
                'onBlueprintCreated' => ['onBlueprintCreated', 0],
            ]);
            return;
        }

        // Initialize components
        $this->injector = new MicroformatsInjector($this->getPluginConfig());
        $this->storage = new WebmentionStorage($this->getPluginConfig());

        // Enable events
        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onOutputGenerated' => ['onOutputGenerated', 0],
            'onTwigExtensions' => ['onTwigExtensions', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onAssetsInitialized' => ['onAssetsInitialized', 0],
        ]);
    }

    /**
     * Handle page routes - webmention endpoint and well-known redirects
     */
    public function onPagesInitialized(Event $event): void
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];
        $path = $uri->path();

        // Handle webmention endpoint
        $webmentionEndpoint = $this->config->get('plugins.bridgyfed.webmention.endpoint', '/webmention');
        if ($path === $webmentionEndpoint) {
            $this->handleWebmentionRequest();
            return;
        }

        // Handle well-known redirects for Fediverse discovery
        if (strpos($path, '/.well-known/') === 0) {
            $this->handleWellKnownRedirect($path);
            return;
        }

        // Handle @username profile redirect
        if (preg_match('/^\/@([a-zA-Z0-9_-]+)$/', $path, $matches)) {
            $this->handleProfileRedirect($matches[1]);
            return;
        }
    }

    /**
     * Handle incoming webmention requests
     */
    protected function handleWebmentionRequest(): void
    {
        $endpoint = new WebmentionEndpoint($this->getPluginConfig(), $this->storage);
        $response = $endpoint->handle();

        // Send response
        http_response_code($response['status']);
        header('Content-Type: application/json');
        echo json_encode($response['body']);
        exit;
    }

    /**
     * Redirect well-known paths to Bridgy Fed
     */
    protected function handleWellKnownRedirect(string $path): void
    {
        $redirects = [
            '/.well-known/host-meta' => 'https://fed.brid.gy/.well-known/host-meta',
            '/.well-known/host-meta.json' => 'https://fed.brid.gy/.well-known/host-meta.json',
            '/.well-known/webfinger' => 'https://fed.brid.gy/.well-known/webfinger',
        ];

        // Special case for atproto-did (Bluesky)
        if ($path === '/.well-known/atproto-did') {
            $host = $this->grav['uri']->host();
            header('Location: https://fed.brid.gy/.well-known/atproto-did?protocol=web&id=' . $host, true, 302);
            exit;
        }

        // Standard redirects
        if (isset($redirects[$path])) {
            $target = $redirects[$path];
            // Preserve query string for webfinger
            $query = $this->grav['uri']->query();
            if ($query && $path === '/.well-known/webfinger') {
                $target .= '?' . $query;
            }
            header('Location: ' . $target, true, 302);
            exit;
        }
    }

    /**
     * Redirect @username to Bridgy Fed profile
     */
    protected function handleProfileRedirect(string $username): void
    {
        $siteUrl = $this->grav['uri']->rootUrl(true);
        $target = 'https://fed.brid.gy/r/' . $siteUrl;
        header('Location: ' . $target, true, 302);
        exit;
    }

    /**
     * Inject microformats into output HTML
     */
    public function onOutputGenerated(Event $event): void
    {
        $page = $this->grav['page'];
        if (!$page) {
            return;
        }

        // Only process HTML pages (check page content type)
        $contentType = $page->contentMeta()['content_type'] ?? 'text/html';
        if (strpos($contentType, 'text/html') === false) {
            return;
        }

        // Get the current output
        $output = $this->grav->output;

        // Skip if no output or not HTML
        if (!$output || strpos($output, '<html') === false) {
            return;
        }

        // Inject microformats
        $output = $this->injector->inject($output, $page);

        // Update output
        $this->grav->output = $output;
    }

    /**
     * Handle admin save - send webmention if publishing is enabled
     */
    public function onAdminSave(Event $event): void
    {
        $page = $event['object'];

        if (!$page instanceof Page) {
            return;
        }

        // Check if this is an article template
        $templates = $this->config->get('plugins.bridgyfed.microformats.templates', ['item', 'blog-item', 'post']);
        if (!in_array($page->template(), $templates)) {
            return;
        }

        // Check if publish to Fediverse is enabled
        $header = $page->header();
        $publish = $header->bridgyfed['publish'] ?? false;
        $nobridge = $header->bridgyfed['nobridge'] ?? false;
        $alreadyPublished = $header->bridgyfed['published_at'] ?? null;

        if ($publish && !$nobridge && !$alreadyPublished) {
            // Send webmention to Bridgy Fed
            $sender = new WebmentionSender($this->getPluginConfig());
            $result = $sender->send($page);

            if ($result->success) {
                // Update published_at timestamp
                $header->bridgyfed['published_at'] = date('Y-m-d H:i:s');
                $page->header($header);

                // Log success
                $this->grav['log']->info('Bridgy Fed: Webmention sent for ' . $page->route());
            } else {
                // Log error
                $this->grav['log']->error('Bridgy Fed: Failed to send webmention - ' . $result->message);
            }
        }
    }

    /**
     * Extend page blueprints with Fediverse tab
     */
    public function onBlueprintCreated(Event $event): void
    {
        $blueprint = $event['blueprint'];
        $type = $event['type'] ?? '';

        // Only extend article templates
        $templates = $this->config->get('plugins.bridgyfed.microformats.templates', ['item', 'blog-item', 'post']);
        if (!in_array($type, $templates)) {
            return;
        }

        // Add Fediverse tab directly
        if ($blueprint->get('form/fields/tabs/type') === 'tabs') {
            $blueprint->extend([
                'form' => [
                    'fields' => [
                        'tabs' => [
                            'fields' => [
                                'bridgyfed' => [
                                    'type' => 'tab',
                                    'title' => 'PLUGIN_BRIDGYFED.ADMIN.TAB_FEDIVERSE',
                                    'fields' => [
                                        'header.bridgyfed.publish' => [
                                            'type' => 'toggle',
                                            'label' => 'PLUGIN_BRIDGYFED.ADMIN.PUBLISH_FEDIVERSE',
                                            'help' => 'PLUGIN_BRIDGYFED.ADMIN.PUBLISH_FEDIVERSE_HELP',
                                            'highlight' => 1,
                                            'default' => 0,
                                            'options' => [
                                                1 => 'PLUGIN_ADMIN.YES',
                                                0 => 'PLUGIN_ADMIN.NO',
                                            ],
                                            'validate' => ['type' => 'bool'],
                                        ],
                                        'header.bridgyfed.published_at' => [
                                            'type' => 'datetime',
                                            'label' => 'PLUGIN_BRIDGYFED.ADMIN.PUBLISHED_AT',
                                            'toggleable' => true,
                                            'help' => 'PLUGIN_BRIDGYFED.ADMIN.PUBLISHED_AT_HELP',
                                        ],
                                        'header.bridgyfed.reply_to' => [
                                            'type' => 'text',
                                            'label' => 'PLUGIN_BRIDGYFED.ADMIN.REPLY_TO',
                                            'help' => 'PLUGIN_BRIDGYFED.ADMIN.REPLY_TO_HELP',
                                            'toggleable' => true,
                                            'placeholder' => 'https://mastodon.social/@user/123456',
                                        ],
                                        'header.bridgyfed.nobridge' => [
                                            'type' => 'toggle',
                                            'label' => 'PLUGIN_BRIDGYFED.ADMIN.NOBRIDGE',
                                            'help' => 'PLUGIN_BRIDGYFED.ADMIN.NOBRIDGE_HELP',
                                            'highlight' => 0,
                                            'default' => 0,
                                            'options' => [
                                                1 => 'PLUGIN_ADMIN.YES',
                                                0 => 'PLUGIN_ADMIN.NO',
                                            ],
                                            'validate' => ['type' => 'bool'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ], true);
        }
    }

    /**
     * Add Twig extensions
     */
    public function onTwigExtensions(): void
    {
        $twig = $this->grav['twig']->twig;

        // Add functions for templates
        $twig->addFunction(new \Twig\TwigFunction('bridgyfed_webmentions', function ($slug, $type = null) {
            return $this->storage->getBySlug($slug, $type);
        }));

        $twig->addFunction(new \Twig\TwigFunction('bridgyfed_counts', function ($slug) {
            return $this->storage->getCounts($slug);
        }));

        $twig->addFunction(new \Twig\TwigFunction('bridgyfed_has_webmentions', function ($slug) {
            $mentions = $this->storage->getBySlug($slug);
            return !empty($mentions);
        }));
    }

    /**
     * Add plugin template path
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
    }

    /**
     * Add CSS assets
     */
    public function onAssetsInitialized(): void
    {
        $this->grav['assets']->addCss('plugin://bridgyfed/css/bridgyfed.css');
    }

    /**
     * Get plugin configuration
     */
    protected function getPluginConfig(): array
    {
        return $this->config->get('plugins.bridgyfed', []);
    }
}
