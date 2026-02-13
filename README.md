# FirstMediaVisibility

Omeka S admin module to review all items and quickly toggle visibility of each item's **first media**.

## Features

- Lists all items in admin with pagination.
- Shows first media thumbnail, item title, and first media name.
- Sorts by item title / first media name.
- Filters list rows by item title or first media name.
- Shows first media visibility with Omeka standard eye icon.
- Toggles first media visibility inline (AJAX).
- Synchronizes item primary media when toggling first media visibility.
- Default rows per page: `50` (configurable from the page).
- Page jump input at top and bottom (Go button + Enter submit).

## Requirements

- Omeka S `^4.0.0`

## Installation

1. Place this module directory as:
   - `modules/FirstMediaVisibility`
2. Open Omeka S admin:
   - `Modules` → `First Media Visibility` → `Install`

## Usage

1. Open admin menu:
   - `First Media Visibility`
2. Review rows and thumbnails.
3. Use the top filter input to narrow rows by item title or media name.
4. Click `Toggle` to switch first media visibility for that item.
5. Use sort links and page controls to navigate large datasets.

## Notes

- If an item has no media, toggle is disabled for that row.
- For safety, toggle action verifies the selected media is still the first media at update time.
- Primary media behavior: when first media is set to private, the second media becomes primary (or none if absent); when set to public again, the first media is restored as primary.

## License

MIT License. See [LICENSE](LICENSE).
