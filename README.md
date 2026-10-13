# retentionpolicy

A [Roundcube](https://roundcube.net/) webmail plugin that adds Outlook-style
per-folder retention policies. You can automatically delete or archive messages
in a folder once they're older than a set period, and the policies are applied
nightly on the Dovecot mail server.

> **This project was built using agentic AI development** (Claude Code). We're
> disclosing this up front because we know not everyone's comfortable with
> AI-authored code in something that deletes mail. Review it accordingly before
> you install it.

## Status

**Working, verified end to end** on one live instance (Roundcube 1.7.4,
Dovecot 2.3.16, Elastic skin, contextmenu plugin), using disposable test
folders filled with copies of real mail. Real mail was never the test subject.

What was checked from the browser:
- the right-click and folder-actions entries, including that right-clicking
  a folder other than the open one configures the folder you clicked;
- the dialog's field toggling and its live preview count;
- inheritance, including "keep forever" stopping a parent policy;
- the Trash-specific options;
- the Settings → Folders section, including a rename (policies followed the
  folder and its subfolders) and a delete (policies removed).

The nightly runner was checked with a dry run and then a real run. It moved
exactly what the dry run predicted (old mail to Trash and to Archive), left
mail newer than the cutoff and a "keep forever" subfolder alone, and logged
each action. Mail moved into Trash was confirmed to take a fresh saved date,
so a Trash policy doesn't remove it the same night.

The helper's own guards were each tested: it refuses a bad username, a
missing folder, a destination that's the same as the source, an unknown date
basis, and a cutoff newer than 7 days.

Bugs found and fixed while testing:
- The dialog had a horizontal scrollbar, because Elastic turns a propform
  table into Bootstrap rows with negative margins. It now uses core's own
  dialog markup.
- The dialog didn't grow when rows appeared, so the preview was squeezed
  into a scroll strip.
- Archive was offered on Trash.
- An empty label rendered as a raw `[retention_hint]` in Settings.

## Why

Roundcube has no way to expire mail by age in an arbitrary folder. The closest
existing plugin, roundcube-toolbox's purge tool (successor to the abandoned
`purge` plugin), only empties Trash and Junk. Dovecot's own `autoexpunge` is
set per folder name in server config, for every account at once, so users
can't change it themselves.

## What you can set

Right-click a folder (with the [contextmenu](https://github.com/johndoh/roundcube-contextmenu)
plugin), use the folder-actions menu under the folder list, or edit the folder
under **Settings → Folders**. All three open the same options:

- **Policy**
  - **Use parent folder's policy.** This is the default. Subfolders inherit the
    nearest parent's policy, as in Outlook.
  - **Delete** messages older than the period, either moved to Trash
    (recoverable) or permanently.
  - **Archive** messages older than the period. They go to the archive folder,
    organised the same way as the core archive plugin's Archive button (by
    year, month, original folder, etc., using its `archive_type` setting).
  - **Keep forever.** Shown when there's a parent policy to override; stops it
    applying here and in this folder's subfolders.
- **Older than:** 1 week, 2 weeks, 1, 2, 3 or 6 months, or 1, 2, 5 or 10 years.

The dialog shows a live count of how many messages the policy would affect if
it ran tonight.

Outlook/Purview also has a "Retain" policy, meaning mail that can't be deleted.
It isn't offered here: any IMAP client can still delete mail, so a webmail
plugin can't enforce it.

### How messages are aged

- **Most folders** count from when the server *received* a message, not the
  `Date:` header, which the sender controls.
- **Trash** (and folders inside it) count from when a message was *moved into
  Trash*, like Outlook's Deleted Items. Otherwise mail that another policy moved
  to Trash would already look old, and a Trash policy would permanently delete
  it the same night, so "move to Trash" wouldn't be recoverable at all.
- A **delete-to-Trash** policy on Trash itself means permanently delete.
- **Archive** policies have no effect inside the archive folder, just as the
  Archive button skips mail that's already archived.

## How it works

```
Roundcube plugin (this repo)
   → stores each folder's policy in the user's Roundcube preferences
   → preview: asks helper/rc-retention (via sudo) how many messages match

bin/retention-run.php (nightly, systemd timer)
   → reads every user's policies from Roundcube's database
   → for each folder: works out its effective policy (own or inherited)
   → helper/rc-retention scan / move / expunge, via doveadm
```

The helper does its own validation as well as the caller's. It re-applies the
age filter on the server for every move or expunge, and refuses any cutoff less
than 7 days old. See [`helper/README.md`](helper/README.md).

## Installation

1. Set up the mail-server side (helper, sudoers rule, timer) per
   [`helper/README.md`](helper/README.md).
2. Install the plugin:

   ```bash
   cd /path/to/roundcube
   composer require gavtaylor/retentionpolicy
   ```

3. Add `retentionpolicy` to `$config['plugins']`, copy `config.inc.php.dist` to
   `config.inc.php` in the plugin directory, and set `retentionpolicy_helper`.

## Tests

```bash
php tests/engine_test.php
```

This covers the policy logic (inheritance, Trash/Archive special cases,
archive layouts, cutoff dates, renames) without needing Roundcube or a mail
server.

## License

[GPL-3.0-or-later](LICENSE), matching Roundcube core and the wider plugin
ecosystem it integrates with.
