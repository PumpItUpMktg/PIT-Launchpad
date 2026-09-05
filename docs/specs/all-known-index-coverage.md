# All-known index coverage — investigation & recommendation

**Status:** investigation only (read-only). No Launchpad code change ships from this doc beyond the
Indexing surface's honest "not yet enabled" state. The remedy is a WordPress-plugin change, scoped below.

## The gap

For a representative client (SPG), Google Search Console reports **~1,976 "known" URLs** while Launchpad
published **~400**. The Indexing surface's "all-known" panel was populated only by fixture data; in
production `page_index_states` holds **only the URLs Launchpad published**, so the panel would render
empty. The question: what are the other ~1,576 URLs, and is the fix in Launchpad or in WordPress?

## What the ~1,576 actually are

They are **WordPress archive / auto-generated URLs Google discovered by crawling internal links** — not
anything Launchpad published. The Launchpad WP stack renders them at HTTP 200 with WP core's default
`index, follow`, and nothing suppresses them:

- **Core archives with no suppression:** category, tag, author, date, paginated (`/page/N/`), attachment
  pages, and feeds. The block theme (`wordpress-theme/launchpad-blocks/templates/`) has only
  `index.html`, `page.html`, `single.html` — **no `archive`/`category`/`author`/`date`/`404` template**,
  so archives fall back to `index.html` and still return 200.
- **Archive-able URL types the companion plugin itself adds** (all `public => true`):
  - `category` taxonomy attached to `page` (`includes/class-plugin.php:140-143`).
  - `lp_area` → `/area/{town}/` (`includes/content/class-area-taxonomy.php:29-43`; its own comment calls
    the archive unused/"harmless").
  - `pig_job` archive `/jobs/` + `pig_city` `/job-city/{term}/` + `pig_service` `/job-service/{term}/`
    (`includes/content/class-job-cpt.php:26-64`).

**The plugin's only robots hook** (`includes/seo/class-head.php:55-76`) applies `noindex` **only** to a
Launchpad-managed *singular* page/post whose pushed SEO payload says so; every non-singular/archive URL
gets no directive and inherits `index, follow`.

## Why this is not a sitemap problem (already handled)

- WP core's `wp-sitemap.xml` (which would list categories/tags/authors) is **already disabled**
  (`includes/class-sitemap.php:22`).
- Launchpad's own sitemap includes only managed pages/posts + quality-gated single jobs; archives are
  **already excluded**. robots.txt adds no `Disallow`.
- So Launchpad is not *advertising* these URLs — Google found them by crawling internal links (nav,
  breadcrumbs, "posts in this town" loops, job-taxonomy links). Removing them from a sitemap (already
  done) does not make Google forget them; only `noindex` / 410 / redirect will.

## Why building "all-known ingestion" in Launchpad is the wrong fix

- **It's not the remedy.** Surfacing the 1,976 doesn't reduce them; the root cause is WordPress config.
- **It's largely infeasible via API.** Google exposes **no API for the Index-Coverage / "Pages" known-URL
  list** — that number lives only in the GSC web UI and the BigQuery bulk export.
  - `GoogleIndexInspector` (URL Inspection API) is **per-URL** — you must already hold the URL; it can't
    enumerate.
  - `GoogleSearchConsoleProvider::searchAnalytics()` (`page` dimension) returns only URLs with
    **impressions > 0**, not the full known set.
  - `SitemapSubmitter` (Sitemaps API) returns aggregate **counts**, not the individual URLs.
  - So the best any Launchpad "all-known" view could ever show is the impression-having subset diffed
    against managed content — never the 1,976. Build it later only as operator *visibility*, never as the
    fix.

Confirmed capture scope: `app/Operator/IndexCoverage.php:51-57,107-111` inspects only published `Content`
+ `Job`; `app/Metrics/Providers/IndexMetricProvider.php:59-108` writes `pages_known` from those findings,
so `pages_known ≈ 400` by construction.

## Recommendation — fix it at WordPress (Option A)

Add one noindex rule to the companion plugin's SEO layer (extend `Head::robots()` on its existing
non-managed/archive branch, or a small `ArchiveNoindex` registered from `class-plugin.php`), applied to
the archive branch so it never overrides a managed page's pushed directive:

```php
if ( is_archive() || is_author() || is_date() || is_search()
     || is_category() || is_tag() || is_tax()          // lp_area, pig_city, pig_service
     || is_post_type_archive('pig_job')                 // /jobs/ archive (not the job singles)
     || is_paged() || is_attachment() ) {
    $robots['noindex'] = true; unset($robots['index']);
}
```

Cut a few off at the source rather than only noindexing:
- **Feeds** — remove `feed_links`/`feed_links_extra` and 301/410 feed requests.
- **Attachment pages** — `template_redirect` → 301 to parent (images are sideloaded; attachment URLs are
  never needed).
- **Author archives** (single-author service sites) — redirect `is_author()` to home, or noindex.
- **`lp_area`** — its archive is explicitly unused; set `publicly_queryable => false` / `rewrite => false`
  (matching `lp_kit`, which is already archive-free), removing the URL entirely.

Leave alone (already correct): core sitemap is off; managed singulars already get correct
robots/canonical; `lp_kit` is already archive-free; single `pig_job` pages are intentionally indexable
(noindex the archive + job taxonomies, not the singles).

## What ships now vs later

- **Now (this PR):** the Indexing surface shows the all-known panel as an explicit **"not yet enabled"**
  state (config `launchpad.indexing.all_known_capture`, default off) instead of a silently-empty or
  fixture-only panel — so the UI never implies a capability that isn't wired.
- **Next (WordPress plugin, its own change):** the noindex rule above — the actual remedy for the 1,976.
- **Optional, later:** a limited operator "all-known" view over the impression-having subset
  (`GscSnapshotIngestor`) diffed against managed content — visibility only, gated by the same flag.
