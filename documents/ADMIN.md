# DRD4 document database deployment

The application follows the collaboration database site's CERN SSO and
environment-variable configuration pattern. No credentials are stored here.

## Document database

```text
DOCUMENTS_MYSQL_HOST=dbod-drd4-collaboration-database.cern.ch
DOCUMENTS_MYSQL_PORT=5547
DOCUMENTS_MYSQL_DATABASE=drd4_documents
DOCUMENTS_MYSQL_USER=documents_reader
DOCUMENTS_MYSQL_PASSWORD=...
DOCUMENTS_MYSQL_WRITE_USER=documents_writer
DOCUMENTS_MYSQL_WRITE_PASSWORD=...
```

These variables are deliberately separate from the existing site's `MYSQL_*`
variables, which continue to select the `drd4` collaboration database.

The upload ceiling defaults to 20 MiB and can be changed with
`DOCUMENT_MAX_UPLOAD_BYTES`. PHP's `upload_max_filesize` and `post_max_size`
must be at least as large.

## Authentication and roles

Protect the service with the same CERN SSO/Auth Proxy arrangement as the
collaboration database. Do not expose a route bypassing the Auth Proxy when
proxy headers are trusted.

Configure exact, comma-separated authenticated identities:

```text
ADMIN_USERS=editor1@cern.ch,editor2@example.org
COORDINATION_USERS=coordinator1@cern.ch
RB_FULL_MEMBERS=full.member@example.org
```

The collaboration schema has no full/partial/ex-officio or coordination role
field. Full-RB and coordination access is therefore denied unless these lists
are configured. `ADMIN_USERS` controls administration only and does not grant
document visibility.

For ordinary RB and funding-agency affiliation, provide read-only credentials
for the existing collaboration database:

```text
COLLABORATION_MYSQL_DATABASE=drd4
COLLABORATION_MYSQL_USER=...
COLLABORATION_MYSQL_PASSWORD=...
```

The authenticated identity (and trusted forwarded email, when present) is
matched against `resources_board.email` and `agencies.email`. Funding-agency
IDs and labels come from `agencies`. If this lookup is unavailable, those roles
are denied by default and the server log identifies the missing integration.

## Security behavior

Every admin page and POST operation requires `ADMIN_USERS`. Writes use CSRF
tokens and prepared statements. Metadata and PDF inserts share one transaction.
Only approval fields are updated after upload. Deletion targets metadata and
relies on the database cascade. Lists, filter choices, details and PDF delivery
all use the same access-policy builder.
