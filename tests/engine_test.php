<?php

// Plain-PHP checks for retentionpolicy_engine -- no Roundcube or PHPUnit
// needed. Run: php tests/engine_test.php

require __DIR__ . '/../retentionpolicy_engine.php';

$failures = 0;

function check(string $name, $actual, $expected): void
{
    global $failures;

    if ($actual === $expected) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}\n     expected " . var_export($expected, true) . "\n     got      " . var_export($actual, true) . "\n";
    }
}

$e = 'retentionpolicy_engine';

// normalize
check('normalize: valid delete', $e::normalize(['action' => 'delete', 'period' => '1m', 'delete_mode' => 'expunge']),
    ['action' => 'delete', 'period' => '1m', 'delete_mode' => 'expunge']);
check('normalize: delete mode defaults to trash', $e::normalize(['action' => 'delete', 'period' => '1m']),
    ['action' => 'delete', 'period' => '1m', 'delete_mode' => 'trash']);
check('normalize: archive drops delete_mode', $e::normalize(['action' => 'archive', 'period' => '1y', 'delete_mode' => 'expunge']),
    ['action' => 'archive', 'period' => '1y']);
check('normalize: never needs no period', $e::normalize(['action' => 'never']), ['action' => 'never']);
check('normalize: unknown action rejected', $e::normalize(['action' => 'retain', 'period' => '1y']), null);
check('normalize: unknown period rejected', $e::normalize(['action' => 'delete', 'period' => '3d']), null);
check('normalize: non-array rejected', $e::normalize('delete'), null);

// effective / inherited
$policies = [
    'Marketing' => ['action' => 'delete', 'period' => '2m', 'delete_mode' => 'trash'],
    'Marketing/Keep' => ['action' => 'never'],
    'Projects' => ['action' => 'archive', 'period' => '1y'],
    'Broken' => ['action' => 'delete', 'period' => 'forever'],
];
check('effective: own policy', $e::effective('Marketing', $policies, '/')['from'], 'Marketing');
check('effective: inherited from parent', $e::effective('Marketing/Shops', $policies, '/')['from'], 'Marketing');
check('effective: inherited from grandparent', $e::effective('Projects/a/b', $policies, '/')['policy']['action'], 'archive');
check('effective: never stops inheritance', $e::effective('Marketing/Keep', $policies, '/'), null);
check('effective: never stops it for descendants too', $e::effective('Marketing/Keep/Old', $policies, '/'), null);
check('effective: no policy', $e::effective('INBOX', $policies, '/'), null);
check('effective: prefix is not parent', $e::effective('MarketingStuff', $policies, '/'), null);
check('effective: malformed entry ignored, falls through', $e::effective('Broken', $policies, '/'), null);
check('inherited: ignores own policy', $e::inherited('Marketing/Keep', $policies, '/')['from'], 'Marketing');
check('inherited: top-level has none', $e::inherited('Marketing', $policies, '/'), null);
check('effective: other delimiter', $e::effective('Marketing.Shops', ['Marketing' => $policies['Marketing']], '.')['from'], 'Marketing');

// resolve_action
$delete_trash = ['action' => 'delete', 'period' => '1m', 'delete_mode' => 'trash'];
$delete_perm = ['action' => 'delete', 'period' => '1m', 'delete_mode' => 'expunge'];
$archive = ['action' => 'archive', 'period' => '1y'];
check('resolve: delete to trash', $e::resolve_action($delete_trash, 'Marketing', 'Trash', 'Archive', '/'), 'trash');
check('resolve: delete permanently', $e::resolve_action($delete_perm, 'Marketing', 'Trash', 'Archive', '/'), 'expunge');
check('resolve: trash policy inside Trash is permanent', $e::resolve_action($delete_trash, 'Trash', 'Trash', 'Archive', '/'), 'expunge');
check('resolve: ...and in a Trash subfolder', $e::resolve_action($delete_trash, 'Trash/Old', 'Trash', 'Archive', '/'), 'expunge');
check('resolve: archive', $e::resolve_action($archive, 'Projects', 'Trash', 'Archive', '/'), 'archive');
check('resolve: archive inside archive is skipped', $e::resolve_action($archive, 'Archive/2020', 'Trash', 'Archive', '/'), null);
check('resolve: archive of the archive itself is skipped', $e::resolve_action($archive, 'Archive', 'Trash', 'Archive', '/'), null);
check('resolve: TrashCan is not Trash', $e::resolve_action($delete_trash, 'TrashCan', 'Trash', 'Archive', '/'), 'trash');

// date_basis
check('basis: Trash uses saved date', $e::date_basis('Trash', 'Trash', '/'), 'saved');
check('basis: Trash subfolder uses saved date', $e::date_basis('Trash/x', 'Trash', '/'), 'saved');
check('basis: elsewhere uses received date', $e::date_basis('Marketing', 'Trash', '/'), 'received');

// cutoff
$now = new DateTimeImmutable('2026-10-13 15:42:00');
check('cutoff: 1 week', $e::cutoff('1w', $now), '2026-10-06');
check('cutoff: 1 month', $e::cutoff('1m', $now), '2026-09-13');
check('cutoff: 2 months', $e::cutoff('2m', $now), '2026-08-13');
check('cutoff: 1 year', $e::cutoff('1y', $now), '2025-10-13');
check('cutoff: 10 years', $e::cutoff('10y', $now), '2016-10-13');
check('cutoff: never newer than the helper floor', $e::cutoff('1w', $now) <= $now->modify('-' . $e::MIN_AGE_DAYS . ' days')->format('Y-m-d'), true);

// archive_destination
$d = '2025-03-07 10:00:00';
check('archive: flat', $e::archive_destination('Archive', '', 'Projects', $d, '/'), 'Archive');
check('archive: year', $e::archive_destination('Archive', 'year', 'Projects', $d, '/'), 'Archive/2025');
check('archive: month', $e::archive_destination('Archive', 'month', 'Projects', $d, '/'), 'Archive/2025/03');
check('archive: tbmonth', $e::archive_destination('Archive', 'tbmonth', 'Projects', $d, '/'), 'Archive/2025/2025-03');
check('archive: folder', $e::archive_destination('Archive', 'folder', 'Projects/Old', $d, '/'), 'Archive/Projects/Old');
check('archive: folderyear', $e::archive_destination('Archive', 'folderyear', 'Projects', $d, '/'), 'Archive/2025/Projects');
check('archive: foldermonth', $e::archive_destination('Archive', 'foldermonth', 'Projects', $d, '/'), 'Archive/2025/03/Projects');
check('archive: sender falls back to flat', $e::archive_destination('Archive', 'sender', 'Projects', $d, '/'), 'Archive');

// uid_set
check('uid_set: ranges', $e::uid_set([5, 1, 2, 3, 9, 12, 13]), '1:3,5,9,12:13');
check('uid_set: single', $e::uid_set(['42']), '42');
check('uid_set: duplicates', $e::uid_set([1, 1, 2]), '1:2');
check('uid_set: empty', $e::uid_set([]), '');

// rename / remove
$renamed = $e::rename($policies, 'Marketing', 'Promotions', '/');
check('rename: folder moves', isset($renamed['Promotions']) && !isset($renamed['Marketing']), true);
check('rename: children move', isset($renamed['Promotions/Keep']) && !isset($renamed['Marketing/Keep']), true);
check('rename: unrelated untouched', isset($renamed['Projects']), true);
check('rename: prefix-only name untouched', array_keys($e::rename(['MarketingX' => $archive], 'Marketing', 'P', '/')), ['MarketingX']);
$removed = $e::remove($policies, 'Marketing', '/');
check('remove: folder and children gone', array_keys($removed), ['Projects', 'Broken']);

echo $failures ? "\n{$failures} failure(s)\n" : "\nall passed\n";
exit($failures ? 1 : 0);
