=== PIG Jobs ===
Contributors: pumpitupmarketing
Tags: jobs, portfolio, local-seo, custom-post-type
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The standalone renderer for Job Capture — a self-contained `pig_job` post type + shortcode/block for any
WordPress site.

== Description ==

PIG Jobs is the standalone counterpart to the Launchpad companion plugin: install it on any WordPress site
and the Job Capture control plane can publish completed-job posts to it. It registers a public, indexable
`pig_job` custom post type with prefixed `pig_city` / `pig_service` taxonomies, receives jobs over the
authed `launchpad/v1/job` REST contract (the SAME contract the companion plugin uses, so the control-plane
push is unchanged), and renders them two ways:

* the `[pig_jobs]` shortcode, and
* a server-rendered `launchpad/pig-jobs` block.

Both are filterable by `city` and `service`. With no argument, a `[pig_jobs]` on a page auto-scopes to the
city/service that page is tagged with (a metabox on every page/post — a standalone site has no
Location/Service records to infer from, so tagging is explicit). Each single job shows a keyless Leaflet +
OpenStreetMap map drawing a 1-mile radius circle over the approximate (jittered) location — the exact
address is never published.

Because the CPT is registered correctly, Elementor Loop Grid, Divi Blog, Essential Addons, Blocksy and
stock query loops all see the jobs natively — the shortcode/block are for everything else.

== Installation ==

1. Upload the `pig-jobs` folder to `/wp-content/plugins/`.
2. Activate the plugin (this flushes rewrite rules so the `/jobs/*` URLs resolve).
3. Create an Application Password for the account the control plane connects with (Users → Profile).
4. Connect the site in the Job Capture control plane using that Application Password.
5. Add `[pig_jobs]` to a page, or tag the page with a city/service and use a bare `[pig_jobs]`.

== Changelog ==

= 0.2.0 =
* Coexistence guard: when the full Launchpad companion plugin is also active, this standalone plugin now
  stands down entirely (the companion owns the `pig_job` CPT, the `launchpad/v1/job` route, and rendering).
  Running both otherwise double-registered the same route with different permission gates, which could 403
  a valid service-user Application Password on `/job`.
* Dedicated service identity: adds the `launchpad_service` role + `lp_manage_content` capability (same names
  the companion uses, so the control plane connects identically to either), granted to administrators too.
  `/job` now authorizes on that capability OR `edit_posts` (back-compat with existing Editor/Admin
  connections).
* Self-heal: re-assert the capability on every request and re-run the install repair after a version change,
  so a migrated/cloned site recovers without a manual reactivate instead of 403-ing.

= 0.1.0 =
* Initial scaffold: `pig_job` CPT + `pig_city`/`pig_service` taxonomies; the authed `launchpad/v1/job` +
  `/job/delete` receiver (ULID-keyed idempotent upsert, image sideloading + featured image); the
  `[pig_jobs]` shortcode + `launchpad/pig-jobs` block (city/service filterable, page-tag auto-scope); the
  single-job Leaflet radius map.
