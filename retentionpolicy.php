<?php

require_once __DIR__ . '/retentionpolicy_engine.php';

/**
 * retentionpolicy
 *
 * Outlook-style per-folder retention policies for Roundcube: right-click a
 * folder (via the contextmenu plugin) or open it under Settings -> Folders
 * and choose to delete or archive messages once they're older than a set
 * period. Policies are inherited by subfolders unless a subfolder has its
 * own (including an explicit "keep forever").
 *
 * This plugin only stores the policy (in the user's Roundcube
 * preferences) and previews what it would affect. The actual expiry runs
 * nightly on the mail server (bin/retention-run.php), since Roundcube only
 * runs while someone is logged in -- see README.md.
 *
 * @author Gavin Taylor
 *
 * Copyright (C) Gavin Taylor
 *
 * This program is a Roundcube (https://roundcube.net) plugin.
 * For more information see README.md.
 * For configuration see config.inc.php.dist.
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
class retentionpolicy extends rcube_plugin
{
    public $task = 'mail|settings';

    private $rcube;

    #[\Override]
    public function init()
    {
        $this->rcube = rcmail::get_instance();
        $this->load_config('config.inc.php.dist');
        $this->load_config();

        if (!$this->user_permitted()) {
            return;
        }

        $this->add_texts('localization/', true);

        $this->register_action('plugin.retentionpolicy.dialog', [$this, 'dialog']);
        $this->register_action('plugin.retentionpolicy.save', [$this, 'save']);
        $this->register_action('plugin.retentionpolicy.preview', [$this, 'preview']);

        $this->add_hook('folder_form', [$this, 'folder_form']);
        $this->add_hook('folder_create', [$this, 'folder_save_hook']);
        $this->add_hook('folder_update', [$this, 'folder_save_hook']);
        $this->add_hook('folder_rename', [$this, 'folder_rename']);
        $this->add_hook('folder_delete', [$this, 'folder_delete']);

        if (!$this->rcube->output->ajax_call) {
            $this->include_script('retentionpolicy.js');
            $this->include_stylesheet($this->local_skin_path() . '/retentionpolicy.css');

            if ($this->rcube->task === 'mail') {
                // Folder-actions menu under the folder list; the contextmenu
                // plugin reuses this menu's entries for its right-click menu.
                $this->add_button([
                    'type' => 'link-menuitem',
                    'command' => 'plugin.retentionpolicy',
                    'label' => 'retentionpolicy.menuitem',
                    'class' => 'retentionpolicy disabled',
                    'classact' => 'retentionpolicy active',
                    'innerclass' => 'inner',
                ], 'mailboxoptions');
            }
        }
    }

    private function user_permitted(): bool
    {
        $admins = $this->rcube->config->get('retentionpolicy_admins');

        if (empty($admins)) {
            return true;
        }

        return in_array($this->rcube->get_user_name(), (array) $admins, true);
    }

    // ---------------------------------------------------------------------
    // Stored policies
    // ---------------------------------------------------------------------

    private function delimiter(): string
    {
        return (string) $this->rcube->get_storage()->get_hierarchy_delimiter();
    }

    private function policies(): array
    {
        return (array) $this->rcube->config->get(retentionpolicy_engine::PREF, []);
    }

    private function save_policies(array $policies): bool
    {
        ksort($policies);

        return (bool) $this->rcube->user->save_prefs([
            retentionpolicy_engine::PREF => $policies,
            retentionpolicy_engine::PREF_DELIMITER => $this->delimiter(),
        ]);
    }

    private function trash_folder(): string
    {
        return (string) $this->rcube->config->get('trash_mbox', '');
    }

    /**
     * The archive plugin's own folder setting if the user has one, so
     * retention files mail exactly where the Archive button would --
     * otherwise this plugin's own fallback.
     */
    private function archive_folder(): string
    {
        return (string) ($this->rcube->config->get('archive_mbox')
            ?: $this->rcube->config->get('retentionpolicy_archive_fallback', 'Archive'));
    }

    private function allowed_folders(): array
    {
        return $this->rcube->get_storage()->list_folders();
    }

    /**
     * Reads the policy fields posted by either the dialog or the Settings
     * -> Folders form. Returns ['inherit' => true] for "use parent / no
     * policy", or a normalized policy array; null means invalid input.
     */
    private function posted_policy(string $prefix): ?array
    {
        $action = rcube_utils::get_input_string($prefix . 'action', rcube_utils::INPUT_POST);

        if ($action === 'inherit') {
            return ['inherit' => true];
        }

        return retentionpolicy_engine::normalize([
            'action' => $action,
            'period' => rcube_utils::get_input_string($prefix . 'period', rcube_utils::INPUT_POST),
            'delete_mode' => rcube_utils::get_input_string($prefix . 'deletemode', rcube_utils::INPUT_POST),
            // A checkbox: the dialog always posts '1'/'0'; the Settings form
            // omits it entirely when unticked.
            'keep_flagged' => rcube_utils::get_input_string($prefix . 'keepflagged', rcube_utils::INPUT_POST) === '1',
            'keep_unread' => rcube_utils::get_input_string($prefix . 'keepunread', rcube_utils::INPUT_POST) === '1',
        ]);
    }

    /**
     * Applies a posted choice to the stored policies for one folder.
     * "Keep forever" on a folder with nothing to inherit just clears it,
     * so prefs only ever hold entries that actually change behaviour.
     */
    private function apply_choice(array $policies, string $folder, array $choice): array
    {
        if (!empty($choice['inherit'])) {
            unset($policies[$folder]);
        } elseif ($choice['action'] === 'never'
            && !retentionpolicy_engine::inherited($folder, $policies, $this->delimiter())
        ) {
            unset($policies[$folder]);
        } else {
            $policies[$folder] = $choice;
        }

        return $policies;
    }

    // ---------------------------------------------------------------------
    // Form (shared by the right-click dialog and Settings -> Folders)
    // ---------------------------------------------------------------------

    /**
     * Human-readable description of a policy, e.g. "Delete after 1 month
     * (move to Trash)".
     */
    private function describe(array $policy): string
    {
        if ($policy['action'] === 'never') {
            return $this->gettext('action_never');
        }

        $text = $this->gettext(['name' => 'describe_' . $policy['action'], 'vars' => [
            'period' => $this->gettext('period_' . $policy['period']),
        ]]);

        if ($policy['action'] === 'delete') {
            $text .= ' (' . $this->gettext('deletemode_' . $policy['delete_mode'] . '_short') . ')';
        }

        if (empty($policy['keep_flagged'])) {
            $text .= ', ' . $this->gettext('describe_flaggedtoo');
        }

        if (!empty($policy['keep_unread'])) {
            $text .= ', ' . $this->gettext('describe_keepsunread');
        }

        return $text;
    }

    /**
     * The policy fields as [id => [label, html]] rows. $prefix keeps
     * the dialog's and the settings form's field names apart.
     */
    private function form_fields(string $folder, string $prefix): array
    {
        $delimiter = $this->delimiter();
        $policies = $this->policies();
        $own = isset($policies[$folder]) ? retentionpolicy_engine::normalize($policies[$folder]) : null;
        $inherited = retentionpolicy_engine::inherited($folder, $policies, $delimiter);
        $in_trash = retentionpolicy_engine::is_within($folder, $this->trash_folder(), $delimiter);
        $in_archive = retentionpolicy_engine::is_within($folder, $this->archive_folder(), $delimiter);

        $action = new html_select(['name' => $prefix . 'action', 'id' => $prefix . 'action', 'class' => 'custom-select retentionpolicy-action']);
        $action->add($inherited
            ? $this->gettext(['name' => 'action_inherit', 'vars' => [
                'policy' => $this->describe($inherited['policy']),
                'folder' => rcmail_action::localize_foldername($inherited['from'], true),
            ]])
            : $this->gettext('action_none'), 'inherit');
        $action->add($this->gettext('action_delete'), 'delete');
        // Archiving from inside the archive is a no-op (the core archive
        // plugin skips it too), and archiving deleted mail out of Trash
        // would just resurrect it, so don't offer it in either.
        if (!$in_archive && !$in_trash) {
            $action->add($this->gettext('action_archive'), 'archive');
        }
        // "Keep forever" only differs from the first option when there's
        // a parent policy to override.
        if ($inherited || ($own && $own['action'] === 'never')) {
            $action->add($this->gettext('action_never'), 'never');
        }

        $period = new html_select(['name' => $prefix . 'period', 'id' => $prefix . 'period', 'class' => 'custom-select']);
        foreach (array_keys(retentionpolicy_engine::PERIODS) as $key) {
            $period->add($this->gettext('period_' . $key), $key);
        }

        if ($in_trash) {
            // Deleting "to Trash" from inside Trash can only mean permanent.
            $mode_html = html::span('retentionpolicy-fixed', rcube::Q($this->gettext('deletemode_trashfolder')))
                . html::tag('input', ['type' => 'hidden', 'name' => $prefix . 'deletemode', 'value' => 'expunge']);
        } else {
            $mode = new html_select(['name' => $prefix . 'deletemode', 'id' => $prefix . 'deletemode', 'class' => 'custom-select']);
            foreach (retentionpolicy_engine::DELETE_MODES as $key) {
                $mode->add($this->gettext('deletemode_' . $key), $key);
            }
            $mode_html = $mode->show($own['delete_mode'] ?? 'trash');
        }

        return [
            'action' => [$this->gettext('policy'), $action->show($own ? $own['action'] : 'inherit')],
            'period' => [$this->gettext('period'), $period->show($own['period'] ?? '1y')],
            'deletemode' => [$this->gettext('deletemode'), $mode_html],
            'keepflagged' => [$this->gettext('keepflagged'), html::label(['class' => 'retentionpolicy-check'],
                (new html_checkbox(['name' => $prefix . 'keepflagged', 'id' => $prefix . 'keepflagged', 'value' => '1']))
                    ->show(($own['keep_flagged'] ?? true) ? '1' : '')
                . ' ' . rcube::Q($this->gettext('keepflaggedhint')))],
            'keepunread' => [$this->gettext('keepunread'), html::label(['class' => 'retentionpolicy-check'],
                (new html_checkbox(['name' => $prefix . 'keepunread', 'id' => $prefix . 'keepunread', 'value' => '1']))
                    ->show(($own['keep_unread'] ?? false) ? '1' : '')
                . ' ' . rcube::Q($this->gettext('keepunreadhint')))],
        ];
    }

    /**
     * AJAX (mail task): returns the dialog body for one folder.
     */
    public function dialog()
    {
        $folder = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);

        if (!in_array($folder, $this->allowed_folders(), true)) {
            $this->rcube->output->show_message($this->gettext('errorfolder'), 'error');
            $this->rcube->output->send();
            return;
        }

        // Same markup as core Elastic's own List options dialog
        // (templates/mail.html, #listoptions-menu) -- a <table class=
        // "propform"> gets converted to Bootstrap .row elements whose
        // negative margins overflow a dialog and add a scrollbar.
        $rows = '';
        foreach ($this->form_fields($folder, '_rp_') as $id => [$label, $field]) {
            $rows .= html::div(['class' => 'form-group row retentionpolicy-row-' . $id],
                html::label(['for' => '_rp_' . $id, 'class' => 'col-form-label col-sm-4'], rcube::Q($label))
                . html::div('col-sm-8', $field));
        }

        $html = html::div(['class' => 'retentionpolicy-dialog', 'data-folder' => $folder],
            html::p('hint', rcube::Q($this->gettext('intro')))
            . html::div('propform', $rows)
            . html::div(['class' => 'retentionpolicy-preview'], ''));

        $this->rcube->output->command('plugin.retentionpolicy_dialog', [
            'folder' => $folder,
            'title' => $this->gettext(['name' => 'dialogtitle', 'vars' => [
                'folder' => rcmail_action::localize_foldername($folder, true),
            ]]),
            'html' => $html,
        ]);
        $this->rcube->output->send();
    }

    /**
     * AJAX (mail task): saves the dialog.
     */
    public function save()
    {
        $folder = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);
        $choice = $this->posted_policy('_rp_');

        if (!in_array($folder, $this->allowed_folders(), true)) {
            $this->rcube->output->show_message($this->gettext('errorfolder'), 'error');
        } elseif (!$choice) {
            $this->rcube->output->show_message($this->gettext('errorinvalid'), 'error');
        } elseif ($this->save_policies($this->apply_choice($this->policies(), $folder, $choice))) {
            $this->rcube->output->show_message($this->gettext('saved'), 'confirmation');
            $this->rcube->output->command('plugin.retentionpolicy_saved', ['folder' => $folder]);
        } else {
            $this->rcube->output->show_message('errorsaving', 'error');
        }

        $this->rcube->output->send();
    }

    /**
     * AJAX: how many messages the (unsaved) choice in the dialog would
     * affect if the nightly run happened now. Read-only.
     */
    public function preview()
    {
        $folder = rcube_utils::get_input_string('_mbox', rcube_utils::INPUT_POST, true);
        $choice = $this->posted_policy('_rp_');
        $delimiter = $this->delimiter();

        if (!in_array($folder, $this->allowed_folders(), true) || !$choice) {
            $this->rcube->output->send();
            return;
        }

        $policies = $this->apply_choice($this->policies(), $folder, $choice);
        $effective = retentionpolicy_engine::effective($folder, $policies, $delimiter);
        $action = $effective ? retentionpolicy_engine::resolve_action(
            $effective['policy'], $folder, $this->trash_folder(), $this->archive_folder(), $delimiter) : null;

        if (!$action) {
            $text = $this->gettext($effective ? 'previewinarchive' : 'previewnone');
            $this->rcube->output->command('plugin.retentionpolicy_preview', ['text' => $text]);
            $this->rcube->output->send();
            return;
        }

        $before = retentionpolicy_engine::cutoff($effective['policy']['period']);
        $basis = retentionpolicy_engine::date_basis($folder, $this->trash_folder(), $delimiter);
        $scan = [$this->rcube->get_user_name(), $this->utf8($folder), $before, $basis];
        $options = retentionpolicy_engine::helper_options($effective['policy']);
        $result = $this->helper(array_merge(['scan'], $scan, $options));
        $count_lines = fn ($output) => count(array_filter(explode("\n", $output), 'strlen'));

        if ($result['exit'] !== 0) {
            $text = $this->gettext('previewerror') . ' ' . $result['output'];
        } else {
            $count = $count_lines($result['output']);
            $text = $this->gettext(['name' => 'preview_' . $action . ($basis === 'saved' ? '_saved' : ''), 'vars' => [
                'count' => $count,
                'period' => $this->gettext('period_' . $effective['policy']['period']),
                'archive' => rcmail_action::localize_foldername($this->archive_folder(), true),
            ]]);

            // How many messages the exemptions are keeping, so it's visible
            // that they're doing their job.
            if ($options) {
                $all = $this->helper(array_merge(['scan'], $scan));
                if ($all['exit'] === 0 && ($kept = $count_lines($all['output']) - $count) > 0) {
                    $which = count($options) === 2 ? 'both' : ($options[0] === 'keep-flagged' ? 'flagged' : 'unread');
                    $text .= ' ' . $this->gettext(['name' => 'previewkept_' . $which, 'vars' => ['count' => $kept]]);
                }
            }
        }

        $this->rcube->output->command('plugin.retentionpolicy_preview', ['text' => $text, 'error' => $result['exit'] !== 0]);
        $this->rcube->output->send();
    }

    // ---------------------------------------------------------------------
    // Settings -> Folders integration
    // ---------------------------------------------------------------------

    public function folder_form($args)
    {
        // A folder being created has no name yet; a placeholder child of
        // its parent is enough for the form to show what it would inherit.
        $folder = (string) ($args['name'] ?? '');
        if ($folder === '' && !empty($args['parent_name'])) {
            $folder = $args['parent_name'] . $this->delimiter() . '*';
        }

        $content = [];
        foreach ($this->form_fields($folder, '_retention_') as $id => [$label, $field]) {
            $content['retention_' . $id] = [
                'label' => $label,
                'value' => $field,
            ];
        }

        // Under the Policy field rather than a row of its own -- core
        // renders an empty label as the raw "[key]" placeholder.
        $content['retention_action']['value'] .= html::div('hint', rcube::Q($this->gettext('settingshint')));

        $args['form']['props']['fieldsets']['retentionpolicy'] = [
            'name' => $this->gettext('title'),
            'content' => $content,
        ];

        return $args;
    }

    /**
     * folder_create / folder_update: save the policy fields from the
     * Settings -> Folders form, following a rename if there was one.
     * Runs before core performs the create/rename, so a failed rename
     * leaves the policy keyed to the new name -- the same trade-off core
     * makes for its own per-folder message_threading setting.
     */
    public function folder_save_hook($args)
    {
        $record = $args['record'] ?? [];
        $name = (string) ($record['name'] ?? '');
        $choice = $this->posted_policy('_retention_');

        if ($name === '' || !isset($_POST['_retention_action'])) {
            return $args;
        }

        $policies = $this->policies();

        if (!empty($record['oldname']) && $record['oldname'] !== $name) {
            $policies = retentionpolicy_engine::rename($policies, $record['oldname'], $name, $this->delimiter());
        }

        if ($choice) {
            $policies = $this->apply_choice($policies, $name, $choice);
        }

        $this->save_policies($policies);

        return $args;
    }

    public function folder_rename($args)
    {
        $policies = retentionpolicy_engine::rename($this->policies(), $args['oldname'], $args['newname'], $this->delimiter());
        $this->save_policies($policies);

        return $args;
    }

    public function folder_delete($args)
    {
        $policies = retentionpolicy_engine::remove($this->policies(), $args['name'], $this->delimiter());
        $this->save_policies($policies);

        return $args;
    }

    // ---------------------------------------------------------------------
    // Mail-server helper
    // ---------------------------------------------------------------------

    /**
     * Roundcube's folder names are IMAP's modified UTF-7; doveadm (and so
     * the helper) wants plain UTF-8 -- the same conversion sievereplay
     * needed for sieve-filter.
     */
    private function utf8(string $folder): string
    {
        return rcube_charset::convert($folder, 'UTF7-IMAP', 'UTF-8');
    }

    /**
     * Shells out to the configured privileged helper (helper/rc-retention).
     * Only the session's own username ever crosses the trust boundary.
     */
    private function helper(array $args): array
    {
        $helper = $this->rcube->config->get('retentionpolicy_helper');

        if (empty($helper)) {
            return ['exit' => -1, 'output' => $this->gettext('errornohelper')];
        }

        exec($helper . ' ' . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1', $output, $exit);

        return ['exit' => $exit, 'output' => implode("\n", $output)];
    }
}
