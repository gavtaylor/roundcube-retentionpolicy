# Mail-server side

Two pieces run on the Dovecot host (not the Roundcube web host, unless
they're the same box):

- **`rc-retention`**, the privileged helper. It's the only part that reads or
  changes mail, using `doveadm`. The plugin calls it to preview how many
  messages a policy would affect, and the nightly runner calls it to apply
  policies. See the header comment in the script for exactly what it
  validates and why.
- **The nightly runner**, `bin/retention-run.php`, scheduled with the systemd
  units in this directory. It runs as the same user as Roundcube's PHP-FPM
  pool, so it can read Roundcube's config and database (where policies are
  stored in each user's preferences) and use the same sudo rule as the
  plugin.

## Why a helper at all

`doveadm` needs root (or the mail user) to read another user's `vmail`-owned
mailbox, and Roundcube only ever runs as an unprivileged web user. Like
sievereplay's `rc-sieve-refilter`, the helper is granted via a sudoers rule
scoped to this one script.

The helper doesn't just trust its caller's choice of messages. Every move or
expunge is filtered again on the server by age (`BEFORE` / `SAVEDBEFORE`),
and it refuses any cutoff less than 7 days old. So even a buggy or
compromised caller passing arbitrary message UIDs can't touch recent mail.
Its optional trailing options are enforced the same way: `keep-flagged` adds
`NOT FLAGGED` and `keep-unread` adds `SEEN`. A protected message is never
moved or expunged, whatever UIDs it's given.

## Installation

On the mail server, as root:

```bash
install -o root -g root -m 0700 rc-retention /usr/local/sbin/rc-retention
# Upgrading: install the new helper *before* the new plugin. Newer plugin
# versions pass options (keep-flagged in v0.2.0, keep-unread in v0.3.0)
# that an older helper rejects.
install -o root -g root -m 0644 /dev/null /var/log/retentionpolicy-helper.log
```

Add a sudoers rule for whichever system user your Roundcube PHP-FPM pool runs
as (its `user =` setting, e.g. `apache`, `www-data`). Validate it with
`visudo -cf` and `chmod 0440` it:

```
# /etc/sudoers.d/retentionpolicy
<php-fpm-user> ALL=(root) NOPASSWD: /usr/local/sbin/rc-retention
```

Then install the timer. Edit `retentionpolicy.service` for your PHP binary,
Roundcube path and PHP-FPM user first:

```bash
install -m 0644 retentionpolicy.service retentionpolicy.timer /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now retentionpolicy.timer
```

Before relying on it, run it once as a dry run and read what it says it would
do:

```bash
sudo -u <php-fpm-user> php /path/to/roundcube/plugins/retentionpolicy/bin/retention-run.php --dry-run
```

Real runs are logged to Roundcube's `retentionpolicy` log, and every move or
expunge the helper performs is also logged to
`/var/log/retentionpolicy-helper.log`.
