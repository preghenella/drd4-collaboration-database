# Database administration

The public `index.php` remains read-only. Authorized editors use `admin.php` to
add and update institutions, funding agencies, Resources Board members, and
deliverables.

## Deployment

1. Apply `create-admin-tables.sql` to the same MySQL database used by the site.
2. Give the site's MySQL account `SELECT`, `INSERT`, and `UPDATE` privileges on
   the four data tables and `SELECT` and `INSERT` on `audit_log`.
3. Protect the site with CERN SSO so Apache sets `REMOTE_USER` (the standard
   OIDC claim variables are also supported).
4. Set `ADMIN_USERS` in the web-server environment to a comma-separated list
   of exact authenticated usernames, for example:

       ADMIN_USERS=rpreghen,gwilkins

   Editing is disabled when this variable is absent. Restart or reload the web
   service after changing its environment.

## Behavior

- All writes use POST requests, CSRF tokens, prepared statements, and database
  transactions.
- Every successful insert or update is recorded in `audit_log` with the editor
  identity and before/after values.
- Concurrent changes are detected before an update is applied.
- There is deliberately no delete operation in the web interface.
