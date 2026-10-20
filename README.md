# Tidemark

[![CI](https://github.com/tidemark-analytics/tidemark/actions/workflows/ci.yml/badge.svg)](https://github.com/tidemark-analytics/tidemark/actions/workflows/ci.yml) [![Latest release](https://img.shields.io/github/v/release/tidemark-analytics/tidemark)](https://github.com/tidemark-analytics/tidemark/releases/latest) [![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Simple, privacy-first web analytics that runs on **ordinary PHP shared hosting**. One light dashboard in the spirit of
[Plausible](https://plausible.io), built with Laravel — upload a zip, open `/install`, done. No Node, no Docker, no SSH required.

- Cookie-free, no personal data: visitor ids are a salted hash that rotates every day, and IP addresses are never written to the database
- One page, everything on it: visitors, visits, pageviews, views per visit, bounce rate and visit duration, with sources, pages, locations, devices and goals underneath
- Plausible-compatible tracking script and Events API (`data-domain`, `window.plausible(...)`, `script.outbound-links.js`-style variants)
- Works on SQLite, MySQL/MariaDB and PostgreSQL
- Designed to scale out later: swappable event sink, separate analytics database connection, stateless app tier

## Install on shared hosting (no SSH)

1. Download the latest `tidemark-x.y.z.zip` from [Releases](https://github.com/tidemark-analytics/tidemark/releases/latest) (dependencies are bundled), or build it yourself: `scripts/build-release.sh 0.8.0` → `dist/tidemark-0.8.0.zip`.
2. Upload and unzip it on your host. **Point the domain's document root at the `public/` folder.**
   If your host won't let you, upload to the web root as-is — the bundled root `.htaccess` forwards to `public/` and blocks internals.
3. Visit `https://your-domain/install`: the page checks the server, connects your database (SQLite needs no setup), runs the migrations and creates your admin account.
4. Add a cron job (cPanel → *Cron Jobs*) — only needed for queue ingestion and data retention:

   ```
   * * * * * php /home/you/tidemark/artisan schedule:run >> /dev/null 2>&1
   ```
5. Add a site and paste the snippet into your site's `<head>`. The dashboard waits for the first pageview and then opens by itself.

Requirements: PHP 8.3+, `mbstring`, `openssl`, `pdo` + one of `pdo_sqlite` / `pdo_mysql` / `pdo_pgsql`, `fileinfo`, `ctype`, `tokenizer`, `xml`.

### Local development

```bash
composer install && cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan tidemark:demo        # fake traffic for demo.example.com; login demo@example.com / password
php artisan serve
php artisan test
```

## Updating

Sign in as an administrator, open **Account settings → Updates** and Tidemark shows when a newer release exists on GitHub; **Download and install** fetches it, checks its SHA-256 checksum and installs it. (The GitHub check only happens when an administrator opens that page; set `TIDEMARK_UPDATE_REPO` to follow a fork.) You can also upload a release zip (`tidemark-x.y.z.zip`) yourself. Either way Tidemark validates the zip, backs up the current code to `storage/app/updates/backups/`, replaces the application files (never `.env`, `storage/` or your database), then runs the database migrations on the next request. The upload is sent in small pieces, so low PHP upload limits don't matter. It needs PHP's `zip` extension and a folder PHP can write to; otherwise upload the release over FTP as usual and visit the site once to run migrations. `scripts/build-release.sh <version>` stamps the version into the zip.

## Tracking

```html
<script defer data-domain="example.com" src="https://your-tidemark.example/js/script.js"></script>
```

Pageviews are sent on load and on every history change, so single-page apps work without extra code. Optional behavior is picked by
the file name — combine as many as you like (`script.outbound-links.file-downloads.js`):

| Variant | Adds |
|---|---|
| `outbound-links` | `Outbound Link: Click` events (`url` property) |
| `file-downloads` | `File Download` events (`url` property) |
| `form-submissions` | `Form: Submission` events |
| `tagged-events` | click events from `class="plausible-event-name=Signup"` |
| `hash` | pageviews on `#hash` route changes |
| `manual` | no automatic pageviews; call `plausible('pageview')` yourself |
| `local` | also track `localhost` |

**Site settings → General** picks the right file name for you. Custom events and properties:

```js
plausible('Signup', { props: { plan: 'pro' }, callback: () => {} })
```

Create a matching **goal** (custom event or page) in the site's settings to see it under *Behaviors*. Use `data-api="https://…/api/event"` to send through a proxy on your own domain.
Anything that speaks Plausible's Events API works too: `POST /api/event` with `{"n": "pageview", "u": "https://example.com/", "d": "example.com", "r": null, "w": 1280, "p": {…}}`.

## What's included

- **Dashboard** — the six headline metrics with comparison (previous period or year over year), a graph with hour/day/week/month intervals, and a *view as table* twin.
- **Reports** — Sources (channels, sources, UTM campaigns), Pages (top, entry, exit), Locations (world map, countries, regions, cities), Devices (browsers, operating systems, device types), Behaviors (goals, custom properties). Every list has a *Details* view with search and paging.
- **Filters** — click any row, or use *Filter*: *is*, *is not*, *contains*, *does not contain*, wildcards (`/blog/*`), several values per filter. Filtering by a **goal** switches the dashboard into conversion mode.
- **Periods** — today, realtime, 24 hours, 7/28/30/91 days, month, 6/12 months, year, all time, custom; keyboard shortcuts for all of them (`D R W T Q M S L Y A C`, `X` compare, `F` filter, `←/→` step through days, months or years).
- **Sharing** — make a dashboard public, or create private shared links with an optional password.
- **Goals, shields, people** — conversion goals, block rules (IP, country, page, hostname allow-list) and owner/admin/viewer roles per site.
- **CSV export**, a **Stats API**, API keys and two-factor sign-in.

## Brand

Logo (pictorial mark: pier pilings with a tide line across them), palette, typography, voice, naming rules and marketing copy are documented in `resources/brand/index.html` (open it through any static server, e.g. `python3 -m http.server` from the project root, then visit `/resources/brand/`). The logo set and contour motif are generated by `scripts/build-brand.py`; PNG/favicon exports by `scripts/export-brand-png.sh`. Source of truth for colors is `public/assets/app.css`.

## Interface notes

- **Layout:** one top bar and one dashboard per site at `/{domain}` — no sidebar, no footer. Site switcher, current visitors, *Filter*, date picker and a ⋮ menu (interval, CSV export, settings) sit in one sticky toolbar.
- **Charts:** hairline grids, one tooltip with the comparison series, keyboard control (←/→) and a *View as table* twin. Refetching dims the previous render instead of flashing skeletons.
- **Colors:** deep-water neutrals with **Tide** teal as the interface color and **Buoy** amber as the signal. Chart series (`--series-1` Lagoon teal, `--series-2` amber) pass the palette validator on both themes — light `#0891a0`/`#d97706`, dark `#1fa3a8`/`#cf7f0c`. Re-validate if you change them.
- **Accessibility:** visible focus rings, focus-trapped dialogs, labeled controls, `prefers-reduced-motion` and forced-colors support, light/dark/system themes.
- **Fonts:** Inter is self-hosted (`public/assets/fonts`, SIL OFL) — no third-party requests.

## Configuration (`.env`)

| Variable | Default | Notes |
|---|---|---|
| `TIDEMARK_MODE` | `self-hosted` | `cloud` opens registration and enables plan limits |
| `TIDEMARK_REGISTRATION` | `false` (`true` in cloud) | Allow sign-ups |
| `TIDEMARK_INGEST` | `sync` | `queue` buffers writes; drained by `schedule:run` or a worker |
| `TIDEMARK_ANALYTICS_DB` | *(app DB)* | Separate connection for `visits`, `events` and `event_props` |
| `TIDEMARK_IGNORE_IP`, `TIDEMARK_HOSTNAMES` | – | Comma-separated lists |
| `TIDEMARK_RETENTION_DAYS` | `0` | Delete raw data older than N days (daily via scheduler) |
| `TIDEMARK_GEOIP_DATABASE` | `storage/app/geo/GeoLite2-City.mmdb` | Needs `composer require geoip2/geoip2`; Cloudflare/Vercel/CloudFront geo headers work with no setup |
| `TIDEMARK_MAX_SITES`, `TIDEMARK_MAX_EVENTS` | `0` | Plan limits (cloud) |

Behind a CDN (Cloudflare etc.) the real client IP and country are read from its headers automatically.

## Stats API

Create a key under **Account settings → API keys** and send it as `Authorization: Bearer …`. `site_id` is the site's domain; the query format follows Plausible's Stats API v1.

```
GET /api/v1/stats/realtime/visitors?site_id=example.com
GET /api/v1/stats/aggregate?site_id=example.com&period=30d&metrics=visitors,pageviews,bounce_rate
GET /api/v1/stats/timeseries?site_id=example.com&period=30d&metrics=visitors
GET /api/v1/stats/breakdown?site_id=example.com&period=30d&property=visit:source&limit=10
```

Filters use `filters=visit:country==DE;event:page==/blog/*`. The dashboard's own JSON endpoints live under `/api/sites/{domain}/stats/…`.

## Architecture & the cloud question

```
 browser ──► POST /api/event ──► Collector ──► EventSink ──► analytics DB (visits, events, event_props)
                                    │           ├─ DatabaseSink  (default: write in-request, stitches visits)
                                    │           └─ QueueSink     (buffer → worker → DatabaseSink)
 dashboard (Preact, no build) ──► JSON API ──► Stats engine (Period + Filters) ──► analytics DB
                                                └─ metadata DB (users, sites, goals, shared links, shields)
```

- **Visits, not sessions.** A visit is one row, stitched in the sink: hits from the same visitor within 30 minutes of inactivity extend it (entry/exit page, pageviews, bounce, duration). Reports read visits for most numbers and events for pageviews and goals.
- **Two logical databases.** Hot, append-only tables go through `TIDEMARK_ANALYTICS_DB`, everything else through the default connection. Tables are keyed by `site_id` and indexed on time, so they partition and shard naturally.
- **Stateless app tier.** Sessions and cache can move to Redis/DB; any number of PHP workers can serve a cell.
- **Swappable seams.** `EventSink` (writes) and `Stats\Stats` (reads) are the only places that know about the storage engine — a ClickHouse implementation can replace them without touching controllers or the UI.
- **Privacy by construction.** Visitor ids are `hash(site, ip, user-agent, daily salt)`; raw IPs and user agents are never stored, and because the salt changes every day a visitor cannot be followed from one day to the next.

**Decision for the hosted version: multi-tenant "cells", not an installation per customer.**
Per-customer installs look attractive for privacy, but at analytics price points they multiply operational cost (upgrades, migrations, monitoring, backups, support) linearly with customers, make free tiers impossible and prevent any shared capacity planning. Privacy is better achieved by what the product *stores* (no IPs, no cookies, rotating salts) plus strict tenant scoping on every query than by where the PHP files live. So:

1. Run the same codebase as a **cell** (`TIDEMARK_MODE=cloud`): app servers + a metadata DB + one or more analytics DBs. New cells (e.g. EU, US) give data-residency; customers are pinned to a cell.
2. Scale reads/writes by enabling `TIDEMARK_INGEST=queue`, moving the analytics connection to a dedicated (replicated) server, then swapping in a columnar sink when a cell outgrows MySQL/Postgres.
3. Offer **dedicated, single-tenant deployments** as an enterprise/compliance tier — the self-hosted build is exactly that, so it costs nothing extra to support.

## Project name

**Tidemark** — a tidemark is the line a tide leaves behind on a shore or harbor wall: a record of how high the water reached, kept without keeping the water. That is the product's privacy model in one image: it keeps the aggregate line and never the footprints of individual visitors.

Conflict check (when the name was chosen): no web analytics product or GitHub/Packagist project uses it. The only well-known "Tidemark" was an enterprise financial-planning software company, since acquired, and the GitHub repos with the name are unrelated hobby tools. The bare `tidemark.com`, `.io`, `.app` and the `use`/`try`/`get` variants are taken, so the practical domains are `tidemarkanalytics.com`, `tidemarkstats.com` or `withtidemark.com`, which were unregistered at the time of writing. Availability changes daily, so re-check before registering.

## Icons

Icons are [Lucide](https://lucide.dev) (ISC license), served from one self-hosted sprite at `public/assets/icons.svg` (only the icons in `scripts/icons.txt`). To add one: add its Lucide name to `scripts/icons.txt`, then `npm i --no-save lucide-static && node scripts/build-icons.mjs`. Use it with `<${Icon} name="chart-column" />`.

## License

MIT


## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: [SECURITY.md](SECURITY.md). Releases are built by GitHub Actions when a `vX.Y.Z` tag is pushed (the tag must match the `VERSION` file).
