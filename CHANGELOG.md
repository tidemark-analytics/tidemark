# Changelog

All notable changes are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/), and versions follow [Semantic Versioning](https://semver.org/).

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
- Plausible-style dashboard: visitors, visits, pageviews, views per visit, bounce rate and visit duration with period comparison, plus Sources, Pages, Locations, Devices and Behaviours reports.
- Filters (is, is not, contains, does not contain, wildcards), goal-based conversion mode, custom properties, realtime view, CSV export.
- Plausible-compatible tracking script (`script.<variants>.js`) and Events API (`POST /api/event`).
- Cookie-free visitor ids from a daily-rotating salted hash; IP addresses are never stored.
- Public dashboards and password-protectable shared links, goals, shields (IP, country, page, hostname), roles per site.
- Stats API v1 subset with API keys, two-factor sign-in.
- Web installer for shared hosting, queue ingestion option, separate analytics database connection.
- In-app updater: upload a release zip under Account settings → Updates.
