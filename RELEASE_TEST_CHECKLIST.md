# Release Smoke-Test Checklist

Run these checks with a disposable database before publishing a release.

## Installation and authentication

- [ ] Open `install.php` and confirm the field is labeled “Database Filename or Path.”
- [ ] Complete setup once with a plain filename and once with a relative path.
- [ ] Confirm `config.php` is created and the configured database opens.
- [ ] Confirm the installer’s completion link opens `admin.php`, not a rewritten `/admin/admin` path.
- [ ] Confirm an incorrect login is rejected.
- [ ] Confirm the correct login succeeds.
- [ ] Confirm logout returns to the login screen.

## Tables, values, and undo

Create a test table containing:

- an `INTEGER PRIMARY KEY`
- `TEXT`
- `INTEGER`
- `REAL`
- `BLOB`
- a nullable field

Then verify:

- [ ] Open the Insert Row screen and confirm it does not return “This action requires POST.”
- [ ] Insert a row with an automatic integer primary key.
- [ ] Insert SQL `NULL` through the visual form.
- [ ] Insert an empty string and confirm it displays as `(empty)`, not `NULL`.
- [ ] Edit text, integer, and real values.
- [ ] Confirm a BLOB remains unchanged after editing other fields.
- [ ] Delete one row and undo it.
- [ ] Bulk-delete multiple rows and undo the deletion.

## Views and schema tools

- [ ] Create a simple SQLite view through the SQL screen.
- [ ] Confirm the view appears in the sidebar with a view badge.
- [ ] Confirm the view can be searched, filtered, and exported.
- [ ] Confirm edit, insert, delete, and schema controls are absent for the view.
- [ ] Create and rename a test table.
- [ ] Add and rename a column.
- [ ] Drop the disposable table through the typed confirmation dialog.

## Import and export

- [ ] Export CSV and re-import it into a matching table.
- [ ] Confirm `\\N` round-trips as SQL `NULL`.
- [ ] Confirm BLOB values round-trip through the `base64:` representation.
- [ ] Export and re-import JSON.
- [ ] Export table SQL and load it into a fresh database.
- [ ] Export the complete database and run `PRAGMA integrity_check` on the download.

## Multiple databases

- [ ] Import a second disposable SQLite database.
- [ ] Switch between both databases.
- [ ] Confirm the configured primary database cannot be deleted in the UI.
- [ ] Delete the imported disposable database using typed filename confirmation.

## Browser checks

- [ ] Switch between light and dark themes without losing the current table.
- [ ] Resize the desktop sidebar and reload the page.
- [ ] Check the interface on a narrow/mobile viewport.
- [ ] Confirm the table fills the available vertical workspace instead of stopping halfway down.
- [ ] Scroll a table and confirm no row text appears above the sticky header.
- [ ] Confirm table and sidebar scrollbars remain compact.
- [ ] Confirm no PHP warnings, JavaScript errors, or failed requests appear.

## PHP compatibility

- [ ] Run syntax checks or smoke tests on PHP 7.0/7.4, PHP 8.0, and PHP 8.3 when those runtimes are available.
- [ ] Confirm JSON import/export and full-database export work on both PHP 7.x and PHP 8.x.
