# Changelog

## 1.1.4 - 2026-08-02

### Fixed

- The Insert Row screen can now be opened with GET, while the actual database insert still requires POST and a valid CSRF token.
- Removed PHP 8-only syntax and runtime dependencies that prevented the application from loading on PHP 7.x.
- Added compatible JSON, session-cookie, and database-export fallbacks for older PHP 7 releases.

### Changed

- The installer now labels the database field **Database Filename or Path** and explains filename, relative-path, and absolute-path usage.
- Supported PHP range is now PHP 7.0 through PHP 8.3; PHP 8.x remains recommended for production.

## 1.1.3 - 2026-08-02

### Fixed

- Stopped the hidden column-filter form from consuming half of the table workspace.
- Closed the small sticky-header gap that allowed scrolling row text to show above the header.
- Restored compact 8-pixel scrollbars for tables, the sidebar, and other scrollable panels.
- Changed installer handoff links to `admin.php` and removed the bundled `DirectoryIndex` directive to avoid `/admin/admin` conflicts with site-level clean-URL rewrite rules.
- Internal return links now prefer the actual PHP script path instead of the rewritten request URL.


All notable changes to SQLite Admin are documented here.

## 1.1.2 — 2026-08-01

### Fixed

- Login now submits to the exact URL that loaded the application and completes without a filename-based redirect, preventing clean-URL rules from turning `/admin/` into `/admin/admin/`
- Logout and theme redirects now return to the current application URL instead of assuming the script is addressed as `admin.php`
- Apache installations now use `admin.php` as the directory index, allowing the application to run cleanly from its folder URL

### Changed

- Removed the installer default database path and filename; the database path field now starts empty
- Removed the custom project/GitHub URL setting from the installer and generated configuration
- Added the official SQLite Admin GitHub repository to the welcome screen and application footer for bug reports

## 1.1.1 — 2026-08-01

### Fixed

- Login and logout now post and redirect explicitly to `admin.php`, preventing 404 errors on hosts that report an unreliable `PHP_SELF` value for subdirectory installations

### Changed

- Installer guidance now clearly distinguishes application-relative database filenames from website URL paths

## 1.1.0 — 2026-08-01

### Added

- Read-only browsing and exporting of SQLite views
- Safe removal of imported database files with typed confirmation
- Enhanced welcome screen with feature overview
- Configurable rows per page
- Explicit handling of `NULL` versus empty strings
- BLOB-size display and preservation during visual edits
- CSRF protection for state-changing actions and SQL submissions
- Session hardening, inactivity timeout, and login throttling
- Consistent database snapshots through the SQLite backup API
- Server-side SQLite header and integrity validation for imported databases
- Apache protection files, security policy, and GitHub-ready repository files

### Changed

- Destructive actions now require POST requests
- Database and row operations use prepared statements where values are involved
- SQLite identifiers are quoted consistently
- Views are prevented from using visual write operations
- The installer validates the database before atomically writing `config.php`
- Minimum supported PHP version is now 8.2
- Passwords must contain at least 12 characters during installation
- CSV parsing now uses PHP's CSV parser instead of splitting on newlines
- Table exports honor active search and column filters

### Fixed

- Bulk-delete checkboxes are now submitted by the correct form
- Updating a row no longer changes SQL `NULL` values into empty strings
- Empty strings are no longer displayed as `NULL`
- Insert undo works for rowid-backed tables
- SQL export correctly preserves `NULL`
- View entries no longer expose edit and delete controls

## 1.0.0

Initial release candidate with table browsing, CRUD, search, filters, import/export, multiple database support, themes, schema tools, and session undo.
