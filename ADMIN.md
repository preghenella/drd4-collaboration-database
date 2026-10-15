# Database administration

The public `index.php` remains read-only. Authorized editors use `/admin` to
add and update institutions, funding agencies, Resources Board members, and
deliverables.

## Deployment

1. Apply `create-admin-tables.sql` to the same MySQL database used by the site.
2. Create a dedicated MySQL writer account. Do not add write privileges to the
   `MYSQL_USER` account used by the public page. Grant the writer only:

       CREATE USER 'writer'@'%' IDENTIFIED BY 'a-long-random-password';
       GRANT SELECT, INSERT, UPDATE ON drd4.institutions TO 'writer'@'%';
       GRANT SELECT, INSERT, UPDATE ON drd4.agencies TO 'writer'@'%';
       GRANT SELECT, INSERT, UPDATE ON drd4.resources_board TO 'writer'@'%';
       GRANT SELECT, INSERT, UPDATE ON drd4.deliverables TO 'writer'@'%';
       GRANT INSERT ON drd4.audit_log TO 'writer'@'%';

   The `%` host wildcard allows this account to connect from any host that can
   reach MySQL. The account is still limited to the table privileges above.
3. Set these additional variables in the web-server environment:

       MYSQL_WRITE_USER=writer
       MYSQL_WRITE_PASSWORD=a-long-random-password

   The public page continues to use `MYSQL_USER` and `MYSQL_PASSWORD`, which
   should remain read-only.
4. Protect the site with CERN SSO so Apache sets `REMOTE_USER` (the standard
   OIDC claim variables are also supported).
5. Set `ADMIN_USERS` in the web-server environment to a comma-separated list
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
