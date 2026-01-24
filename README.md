# Grav Bridgy Fed Plugin

Connect your Grav CMS site to the Fediverse via [Bridgy Fed](https://fed.brid.gy/). This plugin automatically injects Microformats2 markup into your pages, handles webmentions, and manages the necessary redirects for Fediverse discovery.

## Features

- **Microformats2 injection**: Automatically adds h-card, h-entry, and other microformats to your pages
- **Webmention support**: Receive and display interactions from the Fediverse (likes, reposts, replies)
- **Well-known redirects**: Handles `/.well-known/webfinger`, `/.well-known/host-meta`, etc.
- **Bluesky support**: Includes `/.well-known/atproto-did` redirect for AT Protocol
- **Profile redirect**: `/@username` redirects to your Bridgy Fed profile
- **Facepile display**: Show avatars of people who liked/reposted your content
- **Rate limiting**: Protect your webmention endpoint from abuse
- **Multi-language support**: Works with Grav's multilingual features

## Requirements

- Grav CMS 1.7+
- PHP 7.4+
- Composer

## Installation

### Manual Installation

1. Download or clone this repository to `user/plugins/bridgyfed`
2. Run `composer install` in the plugin directory
3. Enable the plugin in Admin > Plugins > Bridgy Fed

### GPM Installation (coming soon)

```bash
bin/gpm install bridgyfed
```

## Configuration

All settings can be configured via the Admin panel under **Plugins > Bridgy Fed**, or by editing `user/plugins/bridgyfed/bridgyfed.yaml`.

### General Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `enabled` | `true` | Enable/disable the plugin |

### Author Settings

Configure your Fediverse profile identity. If left empty, the plugin uses fallback values from your site configuration.

| Setting | Default | Description |
|---------|---------|-------------|
| `author.name` | - | Display name (fallback: `site.author.name` or `site.title`) |
| `author.photo` | - | Profile photo URL (fallback: theme logo or favicon) |
| `author.featured` | - | Banner/header image URL |
| `author.note` | - | Bio/description (fallback: `site.metadata.description`) |
| `author.username` | - | Custom handle, e.g., "alice" for @alice@yourdomain.com |

### Microformats Settings

Control which microformats are injected into your pages.

| Setting | Default | Description |
|---------|---------|-------------|
| `microformats.templates` | `['item', 'blog-item', 'post']` | Page templates to process |
| `microformats.inject_hcard_homepage` | `true` | Add h-card to homepage |
| `microformats.inject_hentry` | `true` | Add h-entry to articles |
| `microformats.inject_activityjson_link` | `true` | Add ActivityPub alternate link |
| `microformats.inject_photos` | `true` | Add u-photo class to images |
| `microformats.inject_videos` | `true` | Add u-video class to videos |
| `microformats.inject_categories` | `true` | Convert tags to p-category |

### Display Settings

Configure how received webmentions are displayed.

| Setting | Default | Description |
|---------|---------|-------------|
| `display.auto_inject` | `true` | Automatically show webmentions on posts |
| `display.show_likes` | `true` | Display likes |
| `display.show_reposts` | `true` | Display reposts/boosts |
| `display.show_replies` | `true` | Display replies |
| `display.show_bookmarks` | `true` | Display bookmarks |
| `display.show_facepile` | `true` | Show avatar facepile |
| `display.facepile_limit` | `20` | Maximum avatars in facepile |
| `display.replies_order` | `desc` | Sort order: `asc` or `desc` |

### Security Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `security.allowed_sources` | `['fed.brid.gy', 'brid.gy']` | Allowed webmention sources |
| `security.rate_limit.enabled` | `true` | Enable rate limiting |
| `security.rate_limit.max_requests` | `10` | Max requests per window |
| `security.rate_limit.window_seconds` | `60` | Rate limit window in seconds |
| `security.fetch_timeout` | `5` | HTTP fetch timeout in seconds |
| `security.max_content_length` | `2000` | Max characters for webmention content |
| `security.sanitize_html` | `true` | Sanitize HTML in webmentions |

### Advanced Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `webmention.endpoint` | `/webmention` | URL path for webmention endpoint |
| `webmention.bridgy_fed_endpoint` | `https://fed.brid.gy/webmention` | Bridgy Fed endpoint URL |
| `storage.path` | `user/data/bridgyfed` | Storage path for webmentions |
| `advanced.max_post_age_days` | `14` | Max age for posts to bridge (Bridgy Fed limit) |
| `advanced.respect_nobridge` | `true` | Respect #nobridge in post metadata |
| `cache.enabled` | `true` | Enable caching |
| `cache.ttl` | `3600` | Cache TTL in seconds |

## Usage

### Publishing to the Fediverse

1. Create or edit a blog post in Grav Admin
2. Go to the **Fediverse** tab in the page editor
3. Enable **"Publish to Fediverse"**
4. Save the page

The plugin will send a webmention to Bridgy Fed, which will create an ActivityPub post visible on Mastodon, Bluesky, and other federated platforms.

### Preventing Federation

To prevent a specific post from being federated:
- Enable the **"No Bridge"** option in the Fediverse tab
- Or add `#nobridge` to your post content

### Displaying Webmentions in Templates

The plugin provides Twig functions for custom template integration:

```twig
{# Get all webmentions for a post #}
{% set mentions = bridgyfed_webmentions(page.slug) %}

{# Get webmentions by type #}
{% set likes = bridgyfed_webmentions(page.slug, 'like') %}
{% set replies = bridgyfed_webmentions(page.slug, 'reply') %}

{# Get interaction counts #}
{% set counts = bridgyfed_counts(page.slug) %}
{{ counts.likes }} likes, {{ counts.reposts }} reposts

{# Check if post has webmentions #}
{% if bridgyfed_has_webmentions(page.slug) %}
  {# Show webmentions section #}
{% endif %}
```

### Using the Webmentions Partial

Include the built-in partial in your templates:

```twig
{% include 'partials/webmentions.html.twig' with {'page': page} %}
```

## How It Works

1. **Microformats Injection**: The plugin parses your HTML output and adds Microformats2 classes (`h-card`, `h-entry`, `p-author`, `e-content`, etc.) that Bridgy Fed uses to understand your content.

2. **Well-Known Redirects**: Requests to `/.well-known/webfinger`, `/.well-known/host-meta`, and `/.well-known/atproto-did` are redirected to Bridgy Fed, enabling Fediverse discovery.

3. **Webmention Flow**:
   - When you publish a post, the plugin sends a webmention to Bridgy Fed
   - Bridgy Fed creates an ActivityPub post
   - When someone interacts (like, reply, repost), Bridgy Fed sends a webmention back
   - The plugin stores and displays these interactions

## Troubleshooting

### Posts not appearing on the Fediverse

- Ensure the post is published (not draft)
- Check that the post is less than 14 days old (Bridgy Fed limit)
- Verify "Publish to Fediverse" is enabled in the post's Fediverse tab
- Check Grav logs for webmention errors

### Webmentions not displaying

- Verify `display.auto_inject` is enabled
- Check that your template includes the webmentions partial or uses the Twig functions
- Ensure the storage path is writable

### Profile not discoverable

- Verify the well-known redirects are working: visit `/.well-known/webfinger?resource=acct:you@yourdomain.com`
- Check that your site is accessible over HTTPS

## License

MIT License - see [LICENSE](LICENSE) file.

## Credits

- [Bridgy Fed](https://fed.brid.gy/) by Ryan Barrett
- [mf2/mf2](https://github.com/microformats/php-mf2) - Microformats2 parser
- Built for [Grav CMS](https://getgrav.org/)

## Author

**Noel Dieschburg**
Email: noel.dieschburg@gmail.com
Website: https://trucs.hophop.be
GitHub: https://github.com/ndieschburg/grav_plugin_bridgyfed
