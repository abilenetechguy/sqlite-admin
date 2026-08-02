# Security Policy

## Supported versions

Security fixes are provided for the latest published 1.1.x release.

## Reporting a vulnerability

Please do not open a public issue containing an exploitable vulnerability, credentials, database contents, or private server information.

Report security concerns privately through the contact method listed on [abilenetechguy.com](https://abilenetechguy.com/). Include the affected version, the relevant request or feature, and enough detail to reproduce the issue without including real private data.

## Deployment guidance

SQLite Admin is an authenticated database-administration application, not a public-facing content site. A successful login grants powerful access, including arbitrary SQL execution.

Recommended controls:

- HTTPS
- VPN or IP allowlisting
- an additional reverse-proxy or web-server authentication layer
- a unique, long administrator password
- database storage outside the public web root
- regular backups
- restricted filesystem permissions
- `debug = false` in production

The included `.htaccess` files protect common sensitive filenames on Apache. They have no effect on Nginx, Caddy, IIS, or PHP's built-in development server; configure equivalent deny rules there.

## Scope notes

The SQL Query screen is intentionally capable of executing write statements. This is expected behavior for an administrator who has already authenticated. Cross-site request forgery protection is applied to state-changing visual actions and SQL submissions.
