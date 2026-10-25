# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.28.2] - 2026-11-07

### Fixed
- On phones, a goal list with revenue columns no longer pushes the page wider than the screen: the columns are narrower, the goal name gets its own line, and the Average column is hidden on very small screens (it stays in Details).

## [0.28.1] - 2026-11-07

### Changed
- An extension can now keep an administrator's site list to their own sites with no mention of the others (an empty path from `LimitsSiteList`), so a hosted service's customer interface never hints at other customers.

## [0.28.0] - 2026-11-07

### Added
- Extensions can provide a separate administration interface (`registerAdminUi`). Administrators get an Admin link in the sidebar, and Users and Updates move out of Account settings into that interface. Without an extension nothing changes.

## [0.27.1] - 2026-11-07

### Fixed
- On phones, the site list opened below the Filter row instead of right under the site button.

## [0.27.0] - 2026-11-07

### Changed
- Site settings are grouped into four tabs: Tracking (snippet, extras and shields), Conversions (goals and funnels), Sharing and access (people, public dashboard, shared links and branding) and Data (reporting timezone, export and import, and the danger zone). Rollups have Sites, Conversions, Sharing and Data. Links to the old tabs still work.
- Account settings are four tabs: Profile, Security (password, two-factor and API keys), Users and Updates, alongside any tabs your install adds. The light, dark or system theme is chosen under Profile; the toggle is gone from the sidebar, the top bar and the command palette for signed-in users, and stays on public and shared dashboards.
- Goals, funnels, shared links, people, branding, shields, rollup sites, API keys and users have a new look: inline forms and lists instead of pop-ups and tables, empty states, and a live preview for branding.
- The filter menu is calmer: after choosing what to filter by, one search box with the operator beside it lists the values with their visitor counts. Tick several, or type to narrow the list and press Enter, then apply. Filters have icons, the Filter button shows how many are active, and Save as segment and Manage segments have a cleaner layout.
- The sidebar keeps the same Workspace links on every page (Sites, Account, and Users and Updates for administrators) with Community and support under Help. The sites list also shows your busiest sites. The site switcher appears only while you are inside a site, and switching keeps you on the same settings page. The back links on the settings pages are hidden where the sidebar is shown.

### Fixed
- A long email address no longer gets cut off in the account menu in the sidebar; it wraps onto a second line.

## [0.26.0] - 2026-11-06

### Changed
- The sidebar's list of sites is now a searchable dropdown at the top, so it stays tidy with many sites. Open it, type to filter, and press Enter to jump to a site. The dashboard sections stay in the sidebar on a site's settings pages, and choosing one from there opens the dashboard at that section.
- Updated Laravel and the build tooling to their latest releases.

### Fixed
- "Save as segment" and "Clear all" now line up with the filter chips, and the chips in the Edit filter menu have proper padding.
- The sidebar could highlight Conversions after returning to the dashboard from settings.

## [0.25.0] - 2026-11-05

### Changed
- A new look and layout. A harbor sidebar lists your sites and the sections of the open dashboard, with a ⌘K command palette to jump between sites, change the date range, compare or filter.
- The dashboard opens with a plain-language summary and the top channel, page and country, then KPI tiles with a sparkline each, then Traffic, Audience and Conversions sections.
- Settings sections are a tab bar under the title; the sites list shows the combined visitor count.

## [0.24.0] - 2026-11-04

### Changed
- A rollup's goals can now report revenue. A rollup adds up only the amounts its sites sent in the goal's own currency, because the sites' goals may report in different ones.

## [0.23.0] - 2026-11-03

### Added
- **Rollups.** One dashboard that adds up several of your sites (Your sites → Create a rollup). Choose the sites, or include all of them, including ones you add later. A rollup has the dashboard you know, with its own goals, funnels, segments, branding, visibility and shared links, and its timezone starts as the one most of its sites use. Your sites keep their own dashboards and data. Imported data and revenue goals are left out (the sites may use different currencies), and a rollup only ever shows sites you belong to. Extensions can limit who may create them with the new optional `App\Contracts\AllowsRollups`.

## [0.22.1] - 2026-11-02

### Fixed
- The top stats show the date ranges and previous values only while a comparison is switched on; otherwise they show just the value and its change, as before.

## [0.22.0] - 2026-11-02

### Added
- **Segments.** Save the filters on screen as a named segment (Filter → Save current filters as segment) and apply it later from the Filter menu. A personal segment is yours alone; owners and admins can share one with everyone who can see the site, including public and shared dashboards. Rename, update or delete them under Filter → Manage segments.
- **Journeys.** A new Explore tab in Behaviors follows visits step by step: choose a starting point (or switch to an ending point) and see what visitors did next, then pick a step to go deeper. Pages, folders (`/blog/*`) and custom events are all steps, and visit filters such as country or source narrow who is counted.
- Extensions can limit both with the new optional `App\Contracts\AllowsExploration`, next to `Entitlements`. Without it every site may use them.

### Fixed
- Deleting a site now also removes its funnels and segments.

## [0.21.0] - 2026-11-01

### Changed
- Dashboard behaviour now follows Plausible's more closely. "Last 7 / 28 / 91 days" end with yesterday, because today is still partial (Month to date and Year to date still include today). The date menu has Yesterday, Last month and Plausible's keyboard shortcuts (D, E, R, H, W, F, N, M, P, Y, L, A, C, X); Last 30 days and Last 6 months are no longer offered but old links keep working, and the filter shortcut `F` is gone because `F` now picks Last 28 days.
- Comparison has its own control next to the date ("vs Previous period"), and the top stats show each metric's date range with the previous value underneath.
- The period you last chose on a site is remembered in your browser.
- The Campaigns tab lists UTM mediums, sources, campaigns, contents and terms in Plausible's order.

## [0.20.0] - 2026-11-01

### Added
- **Funnels.** Chain two to eight goals into a funnel (Site settings → Funnels) and see how many visitors complete each step, in order, with the drop-off between steps, in the Behaviors panel. Visit-level filters such as country or source narrow who is counted.
- **Revenue.** Give a custom-event goal a currency (Site settings → Goals) and send an amount with the event: `plausible('Purchase', { revenue: { amount: 29.99, currency: 'USD' } })`, the Events API's `revenue` field, or the `plausible-revenue-amount` and `plausible-revenue-currency` classes with `tagged-events`. Filtering by that goal shows total and average revenue in the top stats, the graph, the Goals list and every report. Revenue sent in another currency is converted with the European Central Bank's daily rates (`tidemark:refresh-rates`, fetched only when a goal reports revenue; turn off with `TIDEMARK_EXCHANGE_RATES=false`).
- The Stats API accepts `events`, `conversion_rate`, `total_revenue` and `average_revenue` metrics with a goal filter. Site exports and imports carry funnels, goal currencies and event revenue. The CSV export gains revenue columns and `funnels.csv`.
- Extensions can limit these with the new optional `App\Contracts\AllowsConversionReports`, next to `Entitlements`. Without it every site may use them.

## [0.19.1] - 2026-10-24

### Fixed
- The PNG app icons (home-screen and web app icons) were cropped off-centre or blank; they are re-rendered from the logo.

## [0.19.0] - 2026-10-24

### Added
- The account menu links to the Tidemark community on Discord.

## [0.18.0] - 2026-10-23

### Added
- Extensions can keep an administrator's site list and site switcher to the sites they belong to, by implementing the new optional `App\Contracts\LimitsSiteList` next to `Entitlements`. The Sites page then points to where the other sites can be browsed. Self-hosted installs are unchanged: administrators still see every site.

## [0.17.0] - 2026-10-23

### Added
- Account menu entries registered by an extension can run a function instead of opening a link: `registerMenuItem({ label, onClick, icon })`.

## [0.16.2] - 2026-10-23

### Changed
- Stat cards: the change against the previous period is plain coloured text instead of a pill, the padding above and below is even, and the number scales with the card so the number and its change always stay on one line. The six-card row now switches to three columns below 1140 px, where the cards would otherwise be too narrow.

## [0.16.1] - 2026-10-23

### Changed
- The stat cards above the graph are shorter. The change against the previous period now sits to the right of the number instead of on its own line, so the cards are the same compact height whether or not a comparison is shown (on narrow cards it wraps below).

## [0.16.0] - 2026-10-23

### Added
- Import from Plausible. In a site's Settings → General, upload the zip that Plausible's Settings → Imports & exports prepares. The browser reads it and sends the numbers in batches, so very large exports work (a 215 MB export of 3.6 years imports in about a minute).
- Imported days are daily totals added to the top stats, the graph and the reports (sources, channels, referrers, UTM fields, pages, entry and exit pages, countries, regions, devices, browsers, operating systems, and custom events for matching goals). They are left out while a filter is applied, only days before Tidemark's first recorded visit are imported, and importing again replaces the earlier import. "Remove imported data" undoes it.

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
