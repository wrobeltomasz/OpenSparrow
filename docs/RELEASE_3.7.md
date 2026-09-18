# OpenSparrow 3.7

### Added
- **Automation email queue** (`Admin → System → Cron`) — a new admin module listing queued automation emails from the existing `spw_automation_emails` table: status filter (pending / sent / error), per-status counters, bulk requeue (resets attempts and error message) and bulk delete for up to 500 rows at once, plus purge by status with an optional retention window.
- **RAG document preview** — a Preview button in the documents list opens a modal showing the stored document content (filename, tags, size, dates) without having to download it.

### Changed
- **RAG admin section renamed to Centrum AI** — the sidebar, breadcrumbs, demo copy and guidance docs use the new naming, and the RAG settings fields gained explanatory tooltips (context-file limits, upload size, timeout).
- **Admin UI unified around section cards** — every settings form, data table and log now renders inside a `buildSectionCard` section with a consistent header style; section cards are never nested, and all settings tabs share one width via the `.admin-page` wrapper (per-panel `max-width` inline styles were removed).
- **Statistics tiles unified** — Usage / Log / Statistics tabs (External API, Click Statistics, Cron email queue, Users) follow the same tile-grid pattern as Centrum AI → Query Statistics.
- **Admin typography unified** — three sizes and two weights via CSS tokens; `.adm-sec-hdr` styling now lives in the stylesheet instead of inline overrides.
- RAG documents table respects container width; file and RAG action buttons stack vertically, and the rechunk action shows success feedback.

### Removed
- Nothing.

### Fixed
- Admin tab renderers no longer wipe section descriptions when re-rendering a tab — content is now rendered into the inner content div.

### Upgrade notes
- No database migration and no configuration keys are removed by this release. The email queue module operates on the existing `spw_automation_emails` table (created by earlier releases); if the table does not exist, the queue tab reports it instead of failing.

**Full changelog**: https://github.com/wrobeltomasz/OpenSparrow/compare/3.6...3.7

[![Download OpenSparrow](https://a.fsdn.com/con/app/sf-download-button)](https://sourceforge.net/projects/opensparrow/files/3.7/)