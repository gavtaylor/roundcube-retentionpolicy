<?php

$labels = [];

$labels['title'] = 'Retention policy';
$labels['menuitem'] = 'Retention policy';
$labels['dialogtitle'] = 'Retention policy: $folder';
$labels['intro'] = 'Automatically delete or archive messages in this folder once they are older than a set period. Subfolders follow this policy unless they have their own. Policies are applied overnight.';
$labels['settingshint'] = 'Applied overnight. Subfolders follow this policy unless they have their own.';

$labels['policy'] = 'Policy';
$labels['period'] = 'Older than';
$labels['deletemode'] = 'Deleted messages';

$labels['action_inherit'] = 'Use parent folder\'s policy ($policy, from $folder)';
$labels['action_none'] = 'None (keep forever)';
$labels['action_delete'] = 'Delete';
$labels['action_archive'] = 'Archive';
$labels['action_never'] = 'Keep forever';

$labels['period_1w'] = '1 week';
$labels['period_2w'] = '2 weeks';
$labels['period_1m'] = '1 month';
$labels['period_2m'] = '2 months';
$labels['period_3m'] = '3 months';
$labels['period_6m'] = '6 months';
$labels['period_1y'] = '1 year';
$labels['period_2y'] = '2 years';
$labels['period_5y'] = '5 years';
$labels['period_10y'] = '10 years';

$labels['deletemode_trash'] = 'Move to Trash (recoverable)';
$labels['deletemode_expunge'] = 'Delete permanently';
$labels['deletemode_trash_short'] = 'to Trash';
$labels['deletemode_expunge_short'] = 'permanently';
$labels['deletemode_trashfolder'] = 'Deleted permanently, counted from when each message was moved to Trash';

$labels['describe_delete'] = 'Delete after $period';
$labels['describe_archive'] = 'Archive after $period';

$labels['preview_trash'] = '$count message(s) here are older than $period and would be moved to Trash tonight.';
$labels['preview_expunge'] = '$count message(s) here are older than $period and would be permanently deleted tonight.';
$labels['preview_archive'] = '$count message(s) here are older than $period and would be moved to $archive tonight.';
$labels['preview_expunge_saved'] = '$count message(s) here were deleted more than $period ago and would be permanently deleted tonight.';
$labels['previewnone'] = 'No policy applies to this folder, so nothing will be removed.';
$labels['previewinarchive'] = 'This folder is inside the archive, so archiving has no effect here.';
$labels['previewerror'] = 'Could not check this folder:';
$labels['checking'] = 'Checking…';

$labels['saved'] = 'Retention policy saved.';
$labels['errorfolder'] = 'That folder is not in your own mailbox.';
$labels['errorinvalid'] = 'That retention policy is not valid.';
$labels['errornohelper'] = 'The retentionpolicy_helper option is not configured -- see config.inc.php.dist.';
