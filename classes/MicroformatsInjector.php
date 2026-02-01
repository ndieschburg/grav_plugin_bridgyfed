<?php

namespace Grav\Plugin\Bridgyfed;

use Grav\Common\Grav;
use Grav\Common\Page\Page;

/**
 * Injects Microformats2 markup into HTML pages
 *
 * Handles h-card on homepage and h-entry on articles without modifying theme templates.
 */
class MicroformatsInjector
{
    /** @var array */
    protected $config;

    /** @var Grav */
    protected $grav;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->grav = Grav::instance();
    }

    /**
     * Inject microformats into HTML
     */
    public function inject(string $html, Page $page): string
    {
        // 1. Inject into <head>
        $html = $this->injectHead($html, $page);

        // 2. If homepage, inject representative h-card
        if ($this->isHomepage($page)) {
            $html = $this->injectRepresentativeHcard($html);
        }

        // 3. If article page, inject/enhance h-entry
        if ($this->isArticle($page)) {
            $html = $this->injectHentry($html, $page);
        }

        return $html;
    }

    /**
     * Inject links into <head>
     */
    protected function injectHead(string $html, Page $page): string
    {
        $links = [];

        // Endpoint webmention (always)
        $endpoint = $this->config['webmention']['endpoint'] ?? '/webmention';
        $links[] = '<link rel="webmention" href="' . $endpoint . '" />';

        // Bridgy Fed verification (always)
        $links[] = '<link rel="me" href="https://fed.brid.gy/" />';

        // ActivityPub discovery (articles only)
        if ($this->isArticle($page) && ($this->config['microformats']['inject_activityjson_link'] ?? true)) {
            $url = $page->url(true);
            $links[] = '<link rel="alternate" type="application/activity+json" href="https://fed.brid.gy/r/' . htmlspecialchars($url) . '" />';
        }

        // Insert before </head>
        $linksHtml = "\n    " . implode("\n    ", $links) . "\n";
        return str_replace('</head>', $linksHtml . '</head>', $html);
    }

    /**
     * Inject representative h-card on homepage
     */
    protected function injectRepresentativeHcard(string $html): string
    {
        if (!($this->config['microformats']['inject_hcard_homepage'] ?? true)) {
            return $html;
        }

        $author = $this->config['author'] ?? [];

        // Resolve values with fallbacks
        $siteUrl = $author['url'] ?: $this->grav['uri']->rootUrl(true);
        $siteName = $this->resolveAuthorName($author['name'] ?? '');
        $sitePhoto = $this->resolveAuthorPhoto($author['photo'] ?? '');
        $siteNote = $this->resolveAuthorNote($author['note'] ?? '');
        $featured = $author['featured'] ?? '';
        $username = $author['username'] ?? '';

        // Build h-card
        $hcard = '<div class="h-card bridgyfed-hcard" hidden>';
        $hcard .= '<a class="p-name u-url" rel="me" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars($siteName) . '</a>';

        // Photo (required for Bridgy Fed)
        if ($sitePhoto) {
            $hcard .= '<img class="u-photo" src="' . htmlspecialchars($sitePhoto) . '" alt="" />';
        }

        // Featured/banner image (optional)
        if ($featured) {
            $hcard .= '<img class="u-featured" src="' . htmlspecialchars($featured) . '" alt="" />';
        }

        // Bio (optional)
        if ($siteNote) {
            $hcard .= '<p class="p-note">' . htmlspecialchars($siteNote) . '</p>';
        }

        // Custom handle (optional)
        if ($username) {
            $domain = parse_url($siteUrl, PHP_URL_HOST);
            $hcard .= '<a class="u-url" href="acct:' . htmlspecialchars($username) . '@' . $domain . '"></a>';
        }

        $hcard .= '</div>';

        // Insert after <body...>
        return preg_replace('/(<body[^>]*>)/i', '$1' . "\n" . $hcard, $html);
    }

    /**
     * Inject/enhance h-entry on article pages
     */
    protected function injectHentry(string $html, Page $page): string
    {
        if (!($this->config['microformats']['inject_hentry'] ?? true)) {
            return $html;
        }

        $header = $page->header();
        $author = $this->config['author'] ?? [];

        // Build hidden elements to inject
        $hiddenElements = [];

        // u-url - canonical URL
        $canonicalUrl = $page->url(true);
        $hiddenElements[] = '<a class="u-url" href="' . htmlspecialchars($canonicalUrl) . '" hidden></a>';

        // dt-published - check if already exists, if not add it
        $date = $page->date();
        $dateFormatted = date('c', $date);
        // We'll add this hidden if the theme doesn't have dt-published
        if (strpos($html, 'dt-published') === false) {
            $hiddenElements[] = '<time class="dt-published" datetime="' . $dateFormatted . '" hidden>' . date('Y-m-d', $date) . '</time>';
        }

        // p-author h-card (hidden)
        $siteUrl = $author['url'] ?: $this->grav['uri']->rootUrl(true);
        $siteName = $this->resolveAuthorName($author['name'] ?? '');
        $sitePhoto = $this->resolveAuthorPhoto($author['photo'] ?? '');

        $authorHcard = '<div class="p-author h-card" hidden>';
        $authorHcard .= '<a class="p-name u-url" href="' . htmlspecialchars($siteUrl) . '">' . htmlspecialchars($siteName) . '</a>';
        if ($sitePhoto) {
            $authorHcard .= '<img class="u-photo" src="' . htmlspecialchars($sitePhoto) . '" alt="" />';
        }
        $authorHcard .= '</div>';
        $hiddenElements[] = $authorHcard;

        // u-bridgy-fed trigger (only if publish is enabled)
        $publish = $header->bridgyfed['publish'] ?? false;
        $nobridge = $header->bridgyfed['nobridge'] ?? false;
        if ($publish && !$nobridge) {
            $hiddenElements[] = '<a class="u-bridgy-fed" href="https://fed.brid.gy/" hidden></a>';
        }

        // u-in-reply-to (if replying to a Fediverse post)
        $replyTo = $header->bridgyfed['reply_to'] ?? '';
        if ($replyTo) {
            $hiddenElements[] = '<a class="u-in-reply-to" href="' . htmlspecialchars($replyTo) . '" hidden></a>';
        }

        // p-category for tags
        if ($this->config['microformats']['inject_categories'] ?? true) {
            $tags = $header->taxonomy['tag'] ?? [];
            if (!empty($tags)) {
                $categoriesHtml = '<div class="p-category-list" hidden>';
                foreach ($tags as $tag) {
                    $tagUrl = $this->grav['uri']->rootUrl(true) . '/tag:' . urlencode($tag);
                    $categoriesHtml .= '<a href="' . htmlspecialchars($tagUrl) . '" class="p-category">#' . htmlspecialchars($tag) . '</a>';
                }
                $categoriesHtml .= '</div>';
                $hiddenElements[] = $categoriesHtml;
            }
        }

        // u-photo for hero image (featured image for Fediverse posts)
        $heroImage = $header->hero_image ?? '';
        if ($heroImage) {
            // Build full URL to the hero image
            $heroUrl = $page->url(true) . '/' . $heroImage;
            $hiddenElements[] = '<img class="u-photo" src="' . htmlspecialchars($heroUrl) . '" alt="" hidden />';
        }

        // Find the h-entry container and inject hidden elements
        $hiddenHtml = "\n" . implode("\n", $hiddenElements);

        // Look for existing h-entry and inject inside it
        if (preg_match('/(<[^>]*class="[^"]*h-entry[^"]*"[^>]*>)/i', $html, $matches)) {
            // Insert after the opening tag of h-entry
            $html = preg_replace(
                '/(<[^>]*class="[^"]*h-entry[^"]*"[^>]*>)/i',
                '$1' . $hiddenHtml,
                $html,
                1
            );
        } else {
            // No h-entry found, try to find article container and add h-entry class
            $html = preg_replace(
                '/(<article\s+class=")([^"]*)/i',
                '$1h-entry $2',
                $html,
                1
            );
            // Then inject after <article>
            $html = preg_replace(
                '/(<article[^>]*>)/i',
                '$1' . $hiddenHtml,
                $html,
                1
            );
        }

        // Add u-photo to images in e-content
        if ($this->config['microformats']['inject_photos'] ?? true) {
            $html = $this->addMediaClasses($html);
        }

        // Inject webmentions display if enabled
        if ($this->config['display']['auto_inject'] ?? true) {
            $html = $this->injectWebmentionsDisplay($html, $page);
        }

        return $html;
    }

    /**
     * Add u-photo class to images within e-content
     */
    protected function addMediaClasses(string $html): string
    {
        // Find e-content section
        if (!preg_match('/<[^>]*class="[^"]*e-content[^"]*"[^>]*>(.*?)<\/div>/is', $html, $matches)) {
            return $html;
        }

        $eContentStart = strpos($html, $matches[0]);
        $eContentEnd = $eContentStart + strlen($matches[0]);
        $eContent = $matches[0];

        // Add u-photo to images that don't have it
        $eContent = preg_replace_callback(
            '/<img\s+([^>]*)>/i',
            function ($imgMatch) {
                $attrs = $imgMatch[1];
                // Skip if already has u-photo
                if (strpos($attrs, 'u-photo') !== false) {
                    return $imgMatch[0];
                }
                // Add u-photo class
                if (preg_match('/class="([^"]*)"/i', $attrs, $classMatch)) {
                    $newClass = $classMatch[1] . ' u-photo';
                    $attrs = str_replace($classMatch[0], 'class="' . $newClass . '"', $attrs);
                } else {
                    $attrs .= ' class="u-photo"';
                }
                return '<img ' . $attrs . '>';
            },
            $eContent
        );

        // Add u-video to videos
        if ($this->config['microformats']['inject_videos'] ?? true) {
            $eContent = preg_replace_callback(
                '/<video\s+([^>]*)>/i',
                function ($videoMatch) {
                    $attrs = $videoMatch[1];
                    if (strpos($attrs, 'u-video') !== false) {
                        return $videoMatch[0];
                    }
                    if (preg_match('/class="([^"]*)"/i', $attrs, $classMatch)) {
                        $newClass = $classMatch[1] . ' u-video';
                        $attrs = str_replace($classMatch[0], 'class="' . $newClass . '"', $attrs);
                    } else {
                        $attrs .= ' class="u-video"';
                    }
                    return '<video ' . $attrs . '>';
                },
                $eContent
            );
        }

        return substr($html, 0, $eContentStart) . $eContent . substr($html, $eContentEnd);
    }

    /**
     * Inject webmentions display section
     */
    protected function injectWebmentionsDisplay(string $html, Page $page): string
    {
        $storage = new WebmentionStorage($this->config);
        $slug = $page->slug();
        $interactions = $storage->getBySlug($slug);

        if (empty($interactions)) {
            return $html;
        }

        $counts = $storage->getCounts($slug);

        // Build webmentions HTML
        $twig = $this->grav['twig'];
        $webmentionsHtml = $twig->twig->render('partials/webmentions.html.twig', [
            'interactions' => $interactions,
            'counts' => $counts,
            'config' => $this->config['display'] ?? [],
        ]);

        // Find e-content closing tag and inject after it
        $pattern = '/(<\/div>\s*)(<!--\s*\/e-content\s*-->|)(\s*<)/i';
        if (preg_match('/<[^>]*class="[^"]*e-content[^"]*"[^>]*>/i', $html)) {
            // Find the matching closing div of e-content
            // Simple approach: inject after the h-entry closes
            $html = preg_replace(
                '/(<\/div>\s*)(<!--\s*End of content-item\s*-->|)(\s*<p class="prev-next)/i',
                '$1' . "\n" . $webmentionsHtml . "\n" . '$2$3',
                $html,
                1
            );
        }

        return $html;
    }

    /**
     * Check if page is homepage
     */
    protected function isHomepage(Page $page): bool
    {
        $homeRoute = $this->grav['config']->get('system.home.alias', '/home');
        return $page->route() === $homeRoute || $page->route() === '/';
    }

    /**
     * Check if page is an article
     */
    protected function isArticle(Page $page): bool
    {
        $templates = $this->config['microformats']['templates'] ?? ['item', 'blog-item', 'post'];
        return in_array($page->template(), $templates);
    }

    /**
     * Resolve author name with fallbacks
     */
    protected function resolveAuthorName(?string $configName): string
    {
        if ($configName) {
            return $configName;
        }

        $config = $this->grav['config'];

        // Try site.author.name
        $authorName = $config->get('site.author.name');
        if ($authorName) {
            return $authorName;
        }

        // Fallback to site.title
        return $config->get('site.title', 'Unknown');
    }

    /**
     * Resolve author photo with fallbacks
     */
    protected function resolveAuthorPhoto(?string $configPhoto): string
    {
        if ($configPhoto) {
            return $configPhoto;
        }

        $locator = $this->grav['locator'];
        $rootUrl = $this->grav['uri']->rootUrl(true);

        // 1. Check theme config for custom_logo
        $theme = $this->grav['config']->get('system.pages.theme');
        $themeConfig = $this->grav['config']->get('themes.' . $theme);

        if (!empty($themeConfig['custom_logo']['user'])) {
            return $rootUrl . '/' . $themeConfig['custom_logo']['user'];
        }

        // 2. Check for author image in theme
        $authorPaths = [
            'theme://images/author.png',
            'theme://images/author.jpg',
            'theme://images/logo/author.png',
            'user://images/author.png',
        ];

        foreach ($authorPaths as $path) {
            $file = $locator->findResource($path, true, true);
            if ($file && file_exists($file)) {
                return $rootUrl . '/' . $locator->findResource($path, false);
            }
        }

        // 3. Check for favicon/logo
        $faviconPaths = [
            'theme://images/favicon.png',
            'theme://images/favicon.svg',
            'theme://images/logo.png',
            'user://images/favicon.png',
            'user://images/logo.png',
        ];

        foreach ($faviconPaths as $path) {
            $file = $locator->findResource($path, true, true);
            if ($file && file_exists($file)) {
                return $rootUrl . '/' . $locator->findResource($path, false);
            }
        }

        // 4. Gravatar fallback
        $authorEmail = $this->grav['config']->get('site.author.email');
        if ($authorEmail) {
            return 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($authorEmail))) . '?s=256&d=mp';
        }

        // 5. Plugin default avatar
        return $rootUrl . '/user/plugins/bridgyfed/images/default-avatar.png';
    }

    /**
     * Resolve author note/bio with fallbacks
     */
    protected function resolveAuthorNote(?string $configNote): string
    {
        if ($configNote) {
            return $configNote;
        }

        return $this->grav['config']->get('site.metadata.description', '');
    }
}
