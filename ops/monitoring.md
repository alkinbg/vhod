# Vhod production monitoring

Monitoring should verify that Vhod is reachable, that its database dependency works, and that recoverability remains healthy without exposing internal diagnostics publicly.

## External availability

Monitor the public HTTPS endpoint `/healthz` from outside the application host. A successful response is HTTP 200 with `{"status":"ok"}`. The endpoint also checks MariaDB connectivity, but intentionally does not expose database names, hosts, versions, exceptions or stack traces.

A practical baseline is one external check per minute and an alert only after multiple consecutive failures so a single network hiccup does not page unnecessarily. Also monitor TLS certificate expiry.

## Application and infrastructure signals

Collect and alert on:

- sustained HTTP **5xx** responses and application error-log rate;
- repeated HTTP 429 responses, which can indicate brute-force activity or an incorrectly tuned rate limit;
- PHP-FPM availability and worker saturation;
- **MariaDB** availability, connection exhaustion and disk-space pressure;
- filesystem usage for the application, logs and `var/storage/documents`;
- free disk/inode capacity with enough headroom for a database dump and temporary encrypted backup creation;
- the newest successful encrypted **backup age**; alert if it exceeds 26 hours for a daily schedule;
- existence of the corresponding SHA-256 sidecar and successful transfer to the **off-host** backup destination;
- the most recent restore-drill result and its age.

## Error handling

External monitors should see only the health status. Detailed exceptions belong in restricted application/server logs. Do not expose Symfony debug mode, profiler routes, SQL errors or backup paths on the public host.

For alerting, prefer actionable thresholds over raw event volume. A short burst of one or two 5xx responses can be recorded without paging; sustained errors, a failed health check, critically low disk space or stale backups should trigger action.

## Recovery monitoring

A green backup command is necessary but insufficient. Track the newest encrypted backup timestamp independently from the job scheduler and verify that an **off-host** copy exists. Record monthly restore drills performed with `ops/backup/verify-restore.sh` and investigate any checksum, decrypt, MariaDB import or Doctrine schema-validation failure before relying on subsequent backups.

## Deployment checklist

After each production deployment:

1. Verify `/healthz` over HTTPS from outside the host.
2. Confirm response security headers and TLS.
3. Confirm Nginx rate-limit zones loaded without configuration errors.
4. Confirm the next scheduled encrypted backup runs successfully and reaches the off-host location.
5. Check application/PHP-FPM/MariaDB error dashboards for new 5xx patterns.
