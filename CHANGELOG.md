# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.15.2] - 2026-10-22

### Changed
- The Docker image accepts `PUID` and `PGID` (default 1000) and gives `/data` to that user on start, so bind-mounted folders work without a manual `chown`. Unraid's `99:100` works out of the box.

## [0.15.1] - 2026-10-22

### Changed
- A site's brand color now takes over the whole public page, not just the accents: backgrounds, borders and text pick up its hue, in both the light and the dark theme.
- The brand color is checked for contrast (WCAG AA). If the chosen color is too light or too dark for readable text, buttons or chart lines, Tidemark uses the closest shade that passes, and the Branding settings say which shades visitors will see.

## [0.15.0] - 2026-10-22

### Added
- Branding for public pages. In a site's settings, a new **Branding** tab sets a display name, a brand color and a logo (PNG, JPEG, WebP or SVG up to 100 KB). People who open the public dashboard or a shared link see them in the header and as the interface accent; signed-in members keep the normal look.
- Public dashboards and shared links now end with a small "Analytics by Tidemark" link (`TIDEMARK_BRAND_URL` changes where it points).
- Extensions can limit branding by implementing the new optional `App\Contracts\AllowsBranding` next to `Entitlements`. Self-hosted installs have no limit.

## [0.14.0] - 2026-10-22

### Added
- A Docker image, `ghcr.io/tidemark-analytics/tidemark`, for amd64 and arm64. It runs the app and its scheduler in one container, keeps everything that changes in a single `/data` volume, and works with SQLite, MySQL, MariaDB or PostgreSQL. A `docker-compose.yml` is included. Updating means pulling a newer image; the in-app updater is switched off inside the container.

## [0.13.1] - 2026-10-22

### Fixed
- The icon sprite is now versioned like the scripts, so a newly added icon shows up straight after an update instead of staying blank until the browser's or CDN's cached copy of the sprite expires.

## [0.13.0] - 2026-10-22

### Added
- Extensions can add an entry to the account menu with `registerMenuItem({ label, href, icon, external, visible })`.

## [0.12.4] - 2026-10-23

### Changed
- The terms, privacy and refund links on the sign-in and sign-up pages now sit at the bottom of the page instead of inside the card.

## [0.12.3] - 2026-10-21

### Fixed
- When the dashboard updater is turned off (`TIDEMARK_UPDATER=false`), saving your profile no longer brings the Updates tab back in Account settings. The saved-profile response now carries the same flag as every other user response.

## [0.12.2] - 2026-10-21

### Fixed
- After an update, browsers and CDNs no longer keep serving old dashboard scripts: every script module is now requested with a version in its address (through an import map), so a new release is picked up straight away without clearing any cache.

## [0.12.1] - 2026-10-21

### Fixed
- Report lists: the column headers (Visitors, Conversions, CR, Uniques, Total) now line up exactly with their values.
- The Locations panel now has a Details link like the other reports.
- Phones: the dashboard toolbar keeps the site and live count on one row and Filter, date and menu on the next, with popovers that fit the screen; the Settings sections scroll to the one you are on.
- Code snippets wrap at spaces instead of mid-word, and keep the Copy button on its own line on narrow screens; the first-pageview card stacks on phones.
- Metric tiles no longer show a meaningless 0% badge when there is nothing to compare, and the filter menu's heading lines up with its items.
- The Import a file button now matches the other buttons.

## [0.12.0] - 2026-10-21

### Added
- Move your data: Site settings now has a "Move your data" section. Download a site's raw visits and events as one file, and import that file into any other Tidemark install, for example to move from self-hosted to Tidemark Cloud. Imports run in the browser in small batches, can be repeated safely (visits already there are skipped), and bring over the site's goals.
- `App\Contracts\LimitsImportedHistory`, an optional companion to `Entitlements` that lets an extension cap how far back an import may reach.

## [0.11.2] - 2026-10-21

### Added
- `TIDEMARK_TERMS_URL`, `TIDEMARK_PRIVACY_URL` and `TIDEMARK_REFUNDS_URL`: when set, the sign-in and sign-up screens link to your legal pages, and sign-up says that creating an account means agreeing to the terms.

## [0.11.1] - 2026-10-21

### Added
- `TIDEMARK_UPDATER=false` turns off updating from the dashboard (the Updates tab and its endpoints). For deployments that build and ship their own releases.

## [0.11.0] - 2026-10-21

### Added
- **Password reset.** With an SMTP server configured, the sign-in page offers "Forgot your password?" and emails a one-hour link.
- **Optional email verification.** `TIDEMARK_VERIFY_EMAIL=true` makes new accounts confirm their address before adding sites. Existing accounts and accounts created by an administrator count as verified.
- **Extension points** for packages that build on Tidemark: an `Entitlements` contract (site creation and per-event recording checks), a `SiteCreated` event, and dashboard modules that can add Account settings tabs, banners and pages.

### Removed
- `TIDEMARK_MAX_EVENTS`, which was never enforced. Event quotas belong in an `Entitlements` implementation.

## [0.10.4] - 2026-10-20

### Fixed
- On the world map, a hovered country's outline was partly hidden behind neighboring countries that are drawn after it. The outline is now drawn on top of all of them.

## [0.10.3] - 2026-10-20

### Fixed
- Update detection no longer depends on GitHub's API, whose anonymous rate limit shared hosts often exhaust. The newest version is read from github.com's release redirect; the API is only used for release notes. This could leave the update dot and the Updates page empty on shared hosting.
- Installed copies re-check every 6 hours while an administrator is using the app (they used to wait 24), so a new release shows its dot the same day.

## [0.10.2] - 2026-10-20

### Fixed
- In code boxes with a Copy button (for example the public dashboard link), the text and the button now sit on the same center line.

## [0.10.1] - 2026-10-20

### Added
- Clicking a goal such as "Outbound Link: Click" or "File Download" now drills down: the Behaviors panel shows an "Outbound links" / "File downloads" / "Form submissions" tab listing the URLs (or pages) behind it, with conversions and conversion rate. Outbound and download URLs have an open-in-new-tab icon.

### Fixed
- Clearing a goal filter with its × updated the address bar but left the dashboard stuck on the filtered view (the graph crashed on stale data). It now clears properly.

## [0.10.0] - 2026-10-20

### Added
- Automatic update checks: once a day (a scheduled task, and also when an administrator is using the app, so no cron is needed) Tidemark asks GitHub for the latest release version. When a newer one exists, a dot appears on the profile menu, next to **Account settings**, and next to **Updates** inside it. Only the version number is requested; nothing about your sites or visitors is sent. Turn it off with `TIDEMARK_UPDATE_CHECK=false`. New command: `php artisan tidemark:check-updates`.

### Changed
- Version 0.9.x only contacted GitHub when an administrator opened Account settings → Updates. The daily check is on by default; set `TIDEMARK_UPDATE_CHECK=false` to keep the old behavior.

## [0.9.6] - 2026-10-20

### Changed
- American English throughout: the report panel is now "Behaviors", and docs, brand guide and code comments use US spellings.

## [0.9.5] - 2026-10-20

### Changed
- The release zip is back to unpacking into a `tidemark-x.y.z/` folder, as in 0.9.3 and earlier. 0.9.4 (which put files at the archive root) was a mistake; the in-app updater accepts either layout, so nothing else changes.

## [0.9.3] - 2026-10-20

### Fixed
- Public dashboards redirected signed-out visitors to the login page, because the site switcher requested the signed-in-only site list. Signed-out viewers now see the dashboard.

## [0.9.2] - 2026-10-20

### Fixed
- The event endpoint (`POST /api/event`) answered with a `tidemark-session` cookie. It no longer starts a session or sets any cookie, so tracking is cookie-free as documented. **Upgrade promptly if you rely on that claim.**

## [0.9.1] - 2026-10-20

### Fixed
- Replacing the files by hand (FTP) now runs the pending database migrations on the first request, the same as an in-app update. Previously nothing triggered them.
- `.env.example` described the visitor-id salt as monthly; it changes daily.

## [0.9.0] - 2026-10-20

### Added
- One-click updates: Account settings → Updates checks GitHub for the latest release, downloads it, verifies its SHA-256 checksum and installs it. Nothing is requested until an administrator opens that page; set `TIDEMARK_UPDATE_REPO` to point at a fork.

### Changed
- CI uses `actions/checkout` and `actions/upload-artifact` v7.

## [0.8.0] - 2026-10-20

First public release.

### Added
- Plausible-style dashboard: visitors, visits, pageviews, views per visit, bounce rate and visit duration with period comparison, plus Sources, Pages, Locations, Devices and Behaviors reports.
- Filters (is, is not, contains, does not contain, wildcards), goal-based conversion mode, custom properties, realtime view, CSV export.
- Plausible-compatible tracking script (`script.<variants>.js`) and Events API (`POST /api/event`).
- Cookie-free visitor ids from a daily-rotating salted hash; IP addresses are never stored.
- Public dashboards and password-protectable shared links, goals, shields (IP, country, page, hostname), roles per site.
- Stats API v1 subset with API keys, two-factor sign-in.
- Web installer for shared hosting, queue ingestion option, separate analytics database connection.
- In-app updater: upload a release zip under Account settings → Updates.
