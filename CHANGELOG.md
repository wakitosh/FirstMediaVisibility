# Changelog

All notable changes to this project are documented in this file.

## [0.1.1] - 2026-02-13

### Added

- Site slug selector dropdown populated from registered system sites.

### Changed

- First media thumbnail display size updated to `100x100` pixels in the admin list.
- Toggling first media visibility now also updates the item's primary media (`private` -> second media, `public` -> first media).

### Fixed

- MariaDB SQL syntax for site slug query (`slug != ''`).

## [0.1.0] - 2026-02-13

### Added

- Initial release of `FirstMediaVisibility`.
- Admin list of all items with first media thumbnail.
- Sortable columns (item title / first media name).
- Inline first-media visibility toggle with Omeka eye icon states.
- Per-page control (default `50`) and page jump forms (top/bottom).
- UI adjustments for long media names (wrapping) and compact action columns.

### Fixed

- Bootstrap service acquisition during install/bootstrap.
- SQL column references for Omeka schema (`resource` table join usage).
- Media name sort behavior aligned with displayed media title/source fallback.
