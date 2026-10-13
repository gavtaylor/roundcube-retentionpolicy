<?php

/**
 * retentionpolicy_engine
 *
 * The policy logic shared by the Roundcube plugin (dialog, settings form,
 * preview counts) and the nightly runner (bin/retention-run.php): which
 * policy applies to a folder, what date counts as "too old", and where an
 * expiring message ends up. Deliberately free of any Roundcube or IMAP
 * calls so it behaves identically in both places and can be tested on its
 * own (tests/engine_test.php).
 *
 * Folder names throughout are whatever form the caller stores them in
 * (Roundcube uses IMAP's modified UTF-7) -- the engine only ever compares
 * and concatenates them, never decodes them.
 *
 * @author Gavin Taylor
 *
 * Copyright (C) Gavin Taylor
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see https://www.gnu.org/licenses/.
 */
class retentionpolicy_engine
{
    /** Preference key holding [folder (UTF7-IMAP) => policy]. */
    public const PREF = 'retentionpolicy_folders';

    /**
     * Preference key recording the IMAP hierarchy delimiter, so the
     * nightly runner (which never opens an IMAP session) resolves
     * inheritance with the same separator the UI did.
     */
    public const PREF_DELIMITER = 'retentionpolicy_delimiter';

    /**
     * Retention periods offered, keyed by the value stored in prefs.
     * Outlook's own personal tags use 1 week / 1 month / 6 months / 1 year
     * / 5 years -- the in-between steps are here because "two months" is
     * exactly what the old marketing sweep this replaces used (8 weeks).
     */
    public const PERIODS = [
        '1w' => 'P1W',
        '2w' => 'P2W',
        '1m' => 'P1M',
        '2m' => 'P2M',
        '3m' => 'P3M',
        '6m' => 'P6M',
        '1y' => 'P1Y',
        '2y' => 'P2Y',
        '5y' => 'P5Y',
        '10y' => 'P10Y',
    ];

    /**
     * delete:  remove messages older than the period (to Trash, or
     *          permanently, per delete_mode).
     * archive: move them to the archive folder, organised the same way
     *          the core archive plugin's "Archive" button would.
     * never:   explicitly keep forever -- only meaningful to stop a parent
     *          folder's policy being inherited.
     *
     * "Retain" (Purview's legal-hold style "can't be deleted") is not
     * offered: nothing a webmail plugin does can stop an IMAP client
     * deleting mail, so it would be a promise this plugin can't keep.
     */
    public const ACTIONS = ['delete', 'archive', 'never'];

    public const DELETE_MODES = ['trash', 'expunge'];

    /**
     * Archive layouts this engine can reproduce. Mirrors the core archive
     * plugin's archive_type option; 'sender' needs each message's From
     * header, which the runner never fetches, so it falls back to a flat
     * archive folder (documented in README.md).
     */
    public const ARCHIVE_TYPES = ['', 'year', 'month', 'tbmonth', 'folder', 'folderyear', 'foldermonth'];

    /**
     * Shortest period the mail-server helper will act on (it re-checks
     * this itself) -- a safety floor so a bad cutoff date can never reach
     * mail from the last few days.
     */
    public const MIN_AGE_DAYS = 7;

    /**
     * Validates and normalises one stored policy. Returns null for
     * anything malformed so a bad prefs entry is ignored rather than
     * acted on.
     */
    public static function normalize($policy): ?array
    {
        if (!is_array($policy) || !in_array($policy['action'] ?? null, self::ACTIONS, true)) {
            return null;
        }

        if ($policy['action'] === 'never') {
            return ['action' => 'never'];
        }

        if (!isset(self::PERIODS[$policy['period'] ?? null])) {
            return null;
        }

        $normalized = ['action' => $policy['action'], 'period' => $policy['period']];

        if ($policy['action'] === 'delete') {
            $normalized['delete_mode'] = in_array($policy['delete_mode'] ?? null, self::DELETE_MODES, true)
                ? $policy['delete_mode'] : 'trash';
        }

        // Flagged (starred) messages are exempt -- the per-message "never
        // delete" override, like Outlook's per-item retention tag. On unless
        // explicitly turned off, which also covers policies saved before
        // this option existed.
        $normalized['keep_flagged'] = (bool) ($policy['keep_flagged'] ?? true);

        return $normalized;
    }

    /**
     * The policy that applies to $folder: its own if it has one, otherwise
     * the nearest ancestor's (Outlook-style inheritance). An explicit
     * 'never' stops the walk. Returns ['policy' => ..., 'from' => folder
     * it came from] or null if nothing applies.
     */
    public static function effective(string $folder, array $policies, string $delimiter): ?array
    {
        $current = $folder;

        while (true) {
            if (isset($policies[$current]) && ($policy = self::normalize($policies[$current]))) {
                return $policy['action'] === 'never' ? null : ['policy' => $policy, 'from' => $current];
            }

            $pos = $delimiter === '' ? false : strrpos($current, $delimiter);
            if ($pos === false) {
                return null;
            }

            $current = substr($current, 0, $pos);
        }
    }

    /**
     * The policy $folder would inherit if it had none of its own -- what
     * the dialog shows next to "Use parent folder's policy".
     */
    public static function inherited(string $folder, array $policies, string $delimiter): ?array
    {
        $pos = $delimiter === '' ? false : strrpos($folder, $delimiter);

        return $pos === false ? null : self::effective(substr($folder, 0, $pos), $policies, $delimiter);
    }

    /**
     * Messages received before this date (Y-m-d, local time) are expired.
     * Uses the server's receipt date rather than the Date: header, which
     * the sender controls -- the same choice Outlook makes.
     */
    public static function cutoff(string $period, ?DateTimeImmutable $now = null): string
    {
        $now = $now ?: new DateTimeImmutable('today');

        return $now->setTime(0, 0)->sub(new DateInterval(self::PERIODS[$period]))->format('Y-m-d');
    }

    /**
     * Which date a message's age is measured from in $folder:
     *  - 'saved' (when it arrived in *this* folder) inside Trash, like
     *    Outlook's Deleted Items -- otherwise mail another policy moved to
     *    Trash tonight would keep its original, already-old receipt date
     *    and a Trash policy would permanently delete it the same night,
     *    defeating "move to Trash (recoverable)";
     *  - 'received' (server receipt date) everywhere else.
     */
    public static function date_basis(string $folder, string $trash, string $delimiter): string
    {
        return self::is_within($folder, $trash, $delimiter) ? 'saved' : 'received';
    }

    public static function is_within(string $folder, string $root, string $delimiter): bool
    {
        return $root !== '' && ($folder === $root || str_starts_with($folder, $root . $delimiter));
    }

    /**
     * What actually happens to an expiring message in $folder, after the
     * special cases:
     *  - "delete to Trash" inside Trash itself means permanently delete
     *    (moving Trash into Trash would loop forever);
     *  - archiving anything already inside the archive folder is skipped,
     *    exactly as the core archive plugin skips it.
     *
     * Returns 'expunge', 'trash', 'archive' or null (do nothing).
     */
    public static function resolve_action(array $policy, string $folder, string $trash, string $archive, string $delimiter): ?string
    {
        if ($policy['action'] === 'archive') {
            return self::is_within($folder, $archive, $delimiter) ? null : 'archive';
        }

        if ($policy['action'] === 'delete') {
            if ($policy['delete_mode'] === 'expunge' || self::is_within($folder, $trash, $delimiter)) {
                return 'expunge';
            }

            return 'trash';
        }

        return null;
    }

    /**
     * Destination folder for one archived message -- same layouts as the
     * core archive plugin (plugins/archive/archive.php), keyed off the
     * message's receipt date ($received, 'Y-m-d H:i:s').
     */
    public static function archive_destination(string $archive, string $type, string $folder, string $received, string $delimiter): string
    {
        $year = substr($received, 0, 4);
        $month = substr($received, 5, 2);

        $sub = match ($type) {
            'year' => $year,
            'month' => $year . $delimiter . $month,
            'tbmonth' => $year . $delimiter . $year . '-' . $month,
            'folder' => $folder,
            'folderyear' => $year . $delimiter . $folder,
            'foldermonth' => $year . $delimiter . $month . $delimiter . $folder,
            default => '',
        };

        return $archive . ($sub !== '' ? $delimiter . $sub : '');
    }

    /**
     * Compresses a list of UIDs into an IMAP sequence set ("1:5,9,12:13")
     * so a large expiry run still fits comfortably in one argument.
     */
    public static function uid_set(array $uids): string
    {
        $uids = array_values(array_unique(array_map('intval', $uids)));
        sort($uids);

        $ranges = [];
        $start = $prev = null;

        foreach ($uids as $uid) {
            if ($start === null) {
                $start = $prev = $uid;
            } elseif ($uid === $prev + 1) {
                $prev = $uid;
            } else {
                $ranges[] = $start === $prev ? (string) $start : "{$start}:{$prev}";
                $start = $prev = $uid;
            }
        }

        if ($start !== null) {
            $ranges[] = $start === $prev ? (string) $start : "{$start}:{$prev}";
        }

        return implode(',', $ranges);
    }

    /**
     * Applies renaming a folder to the stored policies: the folder's own
     * entry and every descendant's move with it.
     */
    public static function rename(array $policies, string $old, string $new, string $delimiter): array
    {
        $result = [];

        foreach ($policies as $folder => $policy) {
            if ($folder === $old) {
                $folder = $new;
            } elseif (str_starts_with($folder, $old . $delimiter)) {
                $folder = $new . substr($folder, strlen($old));
            }

            $result[$folder] = $policy;
        }

        return $result;
    }

    /**
     * Drops the policies for a deleted folder and everything under it.
     */
    public static function remove(array $policies, string $folder, string $delimiter): array
    {
        return array_filter($policies, fn ($key) => !self::is_within((string) $key, $folder, $delimiter), ARRAY_FILTER_USE_KEY);
    }
}
