# RareToons Downloader API

A JSON API for [rareanimes.foo](https://www.rareanimes.foo/). It exposes the whole
catalogue — series, episodes, quality variants and every download link — and turns
host pages (HubCloud, Pixeldrain, Argon, MEGA) into direct file URLs.

Two ways to run it, one payload format:

| Deployment | Use it when | Needs |
| --- | --- | --- |
| **WordPress plugin** | You have server access to rareanimes.foo | WordPress 6.0+, PHP 7.4+ |
| **Standalone PHP** | You want the API on a separate host or domain | Any LAMP host with PHP 7.4+ |

Both share the same `core/`, so the JSON is identical whichever you pick.

---

## What you get

```
GET /wp-json/rai/v1/series?per_page=2
```

```json
{
  "items": [
    {
      "id": 28899,
      "slug": "tokyo-revengers-season-2-hindi-dubbed-episodes-download-hd",
      "title": "Tokyo Revengers Season 2 Hindi Dubbed Episodes Download HD",
      "url": "https://www.rareanimes.foo/tokyo-revengers-season-2-hindi-dubbed-episodes-download-hd/",
      "type": "series",
      "categories": ["Hindi Anime", "Hindi Dub"],
      "info": {
        "full_name": "Tokyo Revengers",
        "season": "02",
        "episode_count": 13,
        "release_year": "2021",
        "runtime": "24min",
        "genres": ["Animation", "Action & Adventure", "Crime", "Drama"],
        "languages": ["Hindi"],
        "qualities": ["480p", "720p", "1080p"],
        "synopsis": "Takemichi Hanagaki is a freelancer that has reached the absolute pits of despair…"
      },
      "counts": {
        "episodes": 14,
        "links": 41,
        "hosts": { "hubcloud": 26, "argon": 13, "pixeldrain": 1, "zipcloud": 1 }
      }
    }
  ],
  "total": 1946,
  "page": 1,
  "per_page": 2,
  "pages": 973,
  "cached": false
}
```

Every episode keeps the site's own structure — a quality variant per language, and
the links inside it:

```json
{
  "number": 1,
  "title": "It Is What It Is",
  "variants": [
    {
      "label": "Hindi FanDub",
      "links": [
        {
          "label": "WatchMultiQuality",
          "url": "https://argon.razorshell.space/downlead/lKVn5khoubgmkgA",
          "host": "argon",
          "host_label": "Argon (WatchMultiQuality)",
          "file_id": "lKVn5khoubgmkgA",
          "resolvable": true,
          "internal": false
        }
      ]
    },
    {
      "label": "Hindi Sub",
      "links": [
        { "label": "HubCloud", "url": "https://hubcloud.mov/drive/qbbatvvsaqjiivv", "host": "hubcloud", "resolvable": true },
        { "label": "WatchNow", "url": "https://yournewdomain.com/drive/qbbatvvsaqjiivv", "host": "hubcloud", "resolvable": true }
      ]
    }
  ]
}
```

Ask for resolved links and every link gains a `resolution` block with a real file
URL, the filename and the size:

```json
{
  "label": "HubCloud",
  "url": "https://hubcloud.mov/drive/qbbatvvsaqjiivv",
  "host": "hubcloud",
  "resolution": {
    "resolved": true,
    "direct_url": "https://161cc26b6ea08e39f2714552ce321481.r2.cloudflarestorage.com/hax/21b1c9f1e030b4255dc1b627f674f31b?X-Amz-Algorithm=AWS4-HMAC-SHA256&…",
    "filename": "TokyoRevengers S02E01 [RareToonsIndia].mkv",
    "size": 184954953,
    "size_human": "176.4 MB",
    "mime_type": "video/x-matroska",
    "expires_at": "2026-11-23T20:52:05+00:00"
  }
}
```

---

## Endpoints

Base URL: `https://www.rareanimes.foo/wp-json/rai/v1` (plugin) or whatever host you
point the standalone service at.

| Method | Path | What it returns |
| --- | --- | --- |
| GET | `/series` | Paginated list. `page`, `per_page`, `search`, `category`, `orderby`, `order` |
| GET | `/series/{slug}` | One series with every episode and link |
| GET | `/series/{slug}/episodes` | Episodes only, without the series wrapper |
| GET | `/series/{slug}/episodes/{number}` | One episode |
| GET | `/series/{slug}/episodes/{number}/links` | One episode with every link resolved to a direct URL. Add `?refresh=1` to bypass the cache |
| GET | `/resolve?url=` | Resolve any single host URL. Add `?refresh=1` to bypass the cache |
| GET | `/search?q=` | Search the catalogue |
| GET | `/categories` | Every category with its id, name and slug |
| GET | `/stats` | Series count, category count and the supported hosts |
| GET | `/health` | Liveness check (never requires a key) |

Errors come back as `{"error": {"code": "...", "message": "..."}}` with a matching
HTTP status: 400 for a bad request, 401 for a missing key, 404 for an unknown
series or episode.

### Examples

```bash
# Browse
curl "https://www.rareanimes.foo/wp-json/rai/v1/series?per_page=5&orderby=date"

# One series
curl "https://www.rareanimes.foo/wp-json/rai/v1/series/tokyo-revengers-season-2-hindi-dubbed-episodes-download-hd"

# Episode 3 with real download URLs
curl "https://www.rareanimes.foo/wp-json/rai/v1/series/tokyo-revengers-season-2-hindi-dubbed-episodes-download-hd/episodes/3/links"

# Resolve one link
curl "https://www.rareanimes.foo/wp-json/rai/v1/resolve?url=https://pixeldrain.com/u/7AgY7b6q"

# Search
curl "https://www.rareanimes.foo/wp-json/rai/v1/search?q=doraemon"
```

---

## Hosts the resolver understands

| Host | What the API gives you | Notes |
| --- | --- | --- |
| **HubCloud** (`hubcloud.mov`, `.ist`, `.pw`, `yournewdomain.com`) | Signed Cloudflare R2 URL, real filename, exact size, expiry time | The signature lasts about 8 hours, so the resolver cache is kept below that |
| **Pixeldrain** | Direct download URL, filename, size, SHA-256 | Fastest and most reliable of the lot |
| **Argon** (`argon.razorshell.space`) | Direct download URL per quality, plus the full quality list | The quality list is in `resolution.meta.qualities` |
| **MEGA** (`mega.nz`) | Direct URL, size, file key and the encrypted attributes | MEGA stores the payload encrypted, so the bytes need a MEGA aware client. `requires_client` is `true` |
| **ZipCloud** (`animetoonhindi.com`) | The original link only | The host sits behind a Cloudflare challenge, so it cannot be resolved server side |
| **Direct files** | Size, filename, MIME type | Anything ending in `.mkv`, `.mp4`, `.zip` and friends |

A host that cannot be resolved never breaks the response. The link is still
returned, with `resolved: false` and a `reason` explaining why.

---

## Installing the WordPress plugin

1. Upload `dist/rareanimes-downloader-api.zip` in **Plugins → Add New → Upload Plugin**,
   or copy the `rareanimes-downloader-api` folder into `wp-content/plugins/`.
2. Activate it.
3. Open **Settings → Downloader API**. Copy the API key, decide whether requests
   must include it, and set the allowed CORS origin.

Nothing else to configure — the plugin reads the posts from the database, so it
never scrapes your own site and it stays fast.

If you cannot use the admin installer, drop the folder into `wp-content/mu-plugins/`
instead; WordPress loads it automatically.

### Using the key

```bash
curl -H "X-RAI-Key: rai_xxxxxxxx" "https://www.rareanimes.foo/wp-json/rai/v1/series"
```

`?api_key=` works too, for clients that cannot set headers.

---

## Installing the standalone service

```bash
git clone <your-repo> rai-api && cd rai-api
cp standalone/config.example.php standalone/config.php
$EDITOR standalone/config.php
```

Edit `standalone/config.php`:

```php
'api_base'    => 'https://www.rareanimes.foo/wp-json/wp/v2',
'base_url'    => 'https://api.example.com',
'api_key'     => 'rai_change_me',
'cache_dir'   => __DIR__ . '/cache',
```

Point a virtual host at `standalone/`. For Apache, add `standalone/.htaccess`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

For nginx:

```nginx
location / {
    try_files $uri /index.php$is_args$args;
}
```

Test it:

```bash
php -S 127.0.0.1:8000 -t standalone
curl "http://127.0.0.1:8000/health"
```

The standalone service reads the catalogue through the public WordPress REST API,
so it needs no database access and can live on a completely different host.

---

## Authentication

Off by default so you can test straight away. Switch it on in the plugin settings
or with `'api_key' => '…'` in the standalone config. When a key is set, every
endpoint except `/health` needs it.

## Caching

| What | Default | Where |
| --- | --- | --- |
| Parsed series | 1 hour | Plugin: transients. Standalone: files in `cache/` |
| Resolved links | 30 minutes | Same |
| Category list | 6 hours | Same |

Resolved links are cached because a HubCloud signature is only valid for about
eight hours; cache longer than that and you hand out dead URLs.

## Performance

- The plugin queries the database directly (`WP_Query`), never over HTTP.
- Listing a page of series does not parse episode bodies; full parsing happens on
  `/series/{slug}`.
- Resolution is the only part that makes outbound requests, and it is cached.

---

## Repository layout

```
core/                     shared PHP: parser, resolver, HTTP, cache
plugin/                   the WordPress plugin
standalone/               the standalone front controller
tools/verify.php          parse the live catalogue and report
tools/test-plugin.php     exercise the plugin without WordPress
tools/build.php           copy the core into the plugin and build the zip
docs/openapi.yaml         OpenAPI 3.1 description
```

## Development

```bash
php tools/verify.php 40          # parse 40 live posts and print a report
php tools/verify.php 12 --resolve # also resolve one link per host
php tools/test-plugin.php        # run the plugin checks against a stubbed WordPress
php tools/build.php              # rebuild dist/rareanimes-downloader-api.zip
```

`tools/build.php` copies `core/` into the plugin folder, so **always rebuild the zip
after changing the core**.

## Notes

- The catalogue is parsed from the post body, so the parser follows the site's
  templates. If you change the templates, run `tools/verify.php` to check that the
  parser still reads everything.
- The plugin uses the raw `post_content` on purpose: the rendered content contains
  advertisement markup, and those links are not downloads.
- The API returns links to third party hosts that the site indexes. It does not
  host or mirror any media.
