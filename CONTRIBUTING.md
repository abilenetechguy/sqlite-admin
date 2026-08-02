# Contributing

Thank you for improving SQLite Admin.

1. Create a focused branch.
2. Test with a disposable SQLite database containing tables, a view, `NULL` values, empty strings, BLOB data, a table with a primary key, and a rowid-backed table.
3. Run PHP syntax checks:

```bash
php -l admin.php
php -l install.php
```

4. Do not commit `config.php`, database files, credentials, or private data.
5. Describe user-visible and security-relevant behavior in the pull request.

For security reports, follow `SECURITY.md` instead of opening a public issue.
