#!/usr/bin/env php
<?php

/**
 * retention-run.php -- applies every user's retention policies.
 *
 * Run nightly (systemd timer or cron) as the same system user as
 * Roundcube's PHP-FPM pool, so it can read Roundcube's config/database and
 * use the same sudo rule as the plugin for helper/rc-retention. Roundcube
 * itself only runs while someone is logged in, so this is what actually
 * removes or archives expired mail -- the plugin just stores the policies.
 *
 * Usage: retention-run.php [--dry-run] [--user=<username>]
 *
 *   --dry-run   report what would happen; never moves or deletes anything
 *   --user=X    only process this Roundcube username
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

// plugins/retentionpolicy/bin -> Roundcube root.
define('INSTALL_PATH', realpath(__DIR__ . '/../../..') . '/');

require_once INSTALL_PATH . 'program/include/clisetup.php';
require_once __DIR__ . '/../retentionpolicy_engine.php';

$options = getopt('', ['dry-run', 'user:', 'help']);

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: {$argv[0]} [--dry-run] [--user=<username>]\n");
    exit(0);
}

$dry_run = isset($options['dry-run']);
$only_user = $options['user'] ?? null;

$rcube = rcube::get_instance();
$config = $rcube->config;
foreach (['config.inc.php.dist', 'config.inc.php'] as $file) {
    if (is_file(__DIR__ . '/../' . $file)) {
        $config->load_from_file(__DIR__ . '/../' . $file);
    }
}

$helper = $config->get('retentionpolicy_helper');
if (empty($helper)) {
    fwrite(STDERR, "retentionpolicy_helper is not configured -- see config.inc.php.dist\n");
    exit(1);
}

$admins = (array) $config->get('retentionpolicy_admins');
$exit_status = 0;

/**
 * Runs the helper; returns [exit code, stdout lines].
 */
$run_helper = function (array $args) use ($helper): array {
    exec($helper . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $exit);

    return [$exit, $output];
};

$report = function (string $user, string $message) use ($dry_run): void {
    $line = ($dry_run ? '[dry-run] ' : '') . "user={$user} {$message}";
    fwrite(STDOUT, $line . "\n");
    if (!$dry_run) {
        rcube::write_log('retentionpolicy', $line);
    }
};

// One row per Roundcube account. The same username can appear more than
// once (one row per IMAP host it ever logged in through -- e.g. left over
// from a server migration), so keep only the most recently used row that
// actually has policies.
$db = $rcube->get_dbh();
$result = $db->query('SELECT username, preferences, last_login FROM ' . $db->table_name('users', true));
$accounts = [];

while ($row = $db->fetch_assoc($result)) {
    $prefs = @unserialize((string) $row['preferences'], ['allowed_classes' => false]);

    if (!is_array($prefs) || empty($prefs[retentionpolicy_engine::PREF])) {
        continue;
    }
    if ($only_user !== null && $row['username'] !== $only_user) {
        continue;
    }
    if ($admins && !in_array($row['username'], $admins, true)) {
        continue;
    }

    $current = $accounts[$row['username']] ?? null;
    if (!$current || strcmp((string) $row['last_login'], (string) $current['last_login']) > 0) {
        $accounts[$row['username']] = ['prefs' => $prefs, 'last_login' => $row['last_login']];
    }
}

foreach ($accounts as $user => $account) {
    $prefs = $account['prefs'];
    $policies = (array) $prefs[retentionpolicy_engine::PREF];
    $delimiter = (string) ($prefs[retentionpolicy_engine::PREF_DELIMITER] ?? '/');
    $trash = (string) ($prefs['trash_mbox'] ?? $config->get('trash_mbox', ''));
    $archive = (string) (($prefs['archive_mbox'] ?? null) ?: ($config->get('archive_mbox')
        ?: $config->get('retentionpolicy_archive_fallback', 'Archive')));
    $archive_type = (string) ($prefs['archive_type'] ?? $config->get('archive_type', ''));
    if (!in_array($archive_type, retentionpolicy_engine::ARCHIVE_TYPES, true)) {
        $archive_type = '';
    }

    [$exit, $lines] = $run_helper(['list', $user]);
    if ($exit !== 0) {
        $report($user, 'error=list-failed output=' . implode(' ', $lines));
        $exit_status = 1;
        continue;
    }

    // Helper/doveadm speak UTF-8; stored policies use Roundcube's UTF7-IMAP.
    $to_utf7 = fn (string $name) => rcube_charset::convert($name, 'UTF-8', 'UTF7-IMAP');
    $to_utf8 = fn (string $name) => rcube_charset::convert($name, 'UTF7-IMAP', 'UTF-8');

    foreach (array_filter($lines, 'strlen') as $folder_utf8) {
        $folder = $to_utf7($folder_utf8);
        $effective = retentionpolicy_engine::effective($folder, $policies, $delimiter);
        if (!$effective) {
            continue;
        }

        $policy = $effective['policy'];
        $action = retentionpolicy_engine::resolve_action($policy, $folder, $trash, $archive, $delimiter);
        if (!$action) {
            continue;
        }

        $before = retentionpolicy_engine::cutoff($policy['period']);
        $basis = retentionpolicy_engine::date_basis($folder, $trash, $delimiter);

        [$exit, $rows] = $run_helper(['scan', $user, $folder_utf8, $before, $basis]);
        if ($exit !== 0) {
            $report($user, "folder={$folder_utf8} error=scan-failed output=" . implode(' ', $rows));
            $exit_status = 1;
            continue;
        }

        // "<uid>\t<date>" per message; group into one operation per
        // destination (archive layouts can split a folder across several).
        $groups = [];
        foreach (array_filter($rows, 'strlen') as $row) {
            [$uid, $date] = array_pad(explode("\t", $row, 2), 2, '');
            if (!ctype_digit($uid)) {
                continue;
            }

            $dest = match ($action) {
                'trash' => $trash,
                'archive' => retentionpolicy_engine::archive_destination($archive, $archive_type, $folder, $date, $delimiter),
                default => '',
            };
            $groups[$dest][] = $uid;
        }

        foreach ($groups as $dest => $uids) {
            $set = retentionpolicy_engine::uid_set($uids);
            $desc = "folder={$folder_utf8} action={$action} policy=" . $policy['action'] . '/' . $policy['period']
                . ' from=' . $to_utf8($effective['from']) . " before={$before} basis={$basis}"
                . ($dest !== '' ? ' dest=' . $to_utf8($dest) : '') . ' count=' . count($uids);

            if ($dry_run) {
                $report($user, $desc);
                continue;
            }

            [$exit, $out] = $action === 'expunge'
                ? $run_helper(['expunge', $user, $folder_utf8, $before, $basis, $set])
                : $run_helper(['move', $user, $folder_utf8, $before, $basis, $to_utf8($dest), $set]);

            if ($exit !== 0) {
                $report($user, $desc . ' error=' . implode(' ', $out));
                $exit_status = 1;
            } else {
                $report($user, $desc . ' done=' . trim(implode(' ', $out)));
            }
        }
    }
}

exit($exit_status);
