// retentionpolicy: folder "Retention Policy" dialog (mail screen) and the
// matching section of Settings -> Folders.
//
// The dialog is opened from the folder-actions menu under the folder list
// (for the open folder) and, when the contextmenu plugin is installed,
// from right-clicking any folder. Its body is rendered server-side so it
// shares one implementation with the Settings form.

if (window.rcmail) {
    // Show the period/delete-mode rows only when they mean something for
    // the chosen action. Works for both the dialog ('_rp_', div.form-group
    // rows) and the Settings -> Folders form ('_retention_', <tr> rows).
    function retentionpolicyToggle(prefix, $scope) {
        var action = $('#' + prefix + 'action', $scope).val(),
            rowOf = function (name) {
                return $('[name="' + prefix + name + '"]', $scope).first().closest('.form-group, tr');
            };

        rowOf('period').toggle(action === 'delete' || action === 'archive');
        rowOf('deletemode').toggle(action === 'delete');
        rowOf('keepflagged').toggle(action === 'delete' || action === 'archive');

        retentionpolicyFitDialog($scope);
    }

    // jQuery UI fixes a dialog's height when it opens; showing/hiding rows
    // (or a longer preview message) afterwards would otherwise push the
    // preview into a scrolling strip at the bottom.
    function retentionpolicyFitDialog($scope) {
        var $dialog = $($scope).closest('.ui-dialog-content');

        if ($dialog.length && $dialog.dialog('instance')) {
            $dialog.dialog('option', 'height', 'auto');
        }
    }

    function retentionpolicyFields($scope) {
        var data = {};

        $('[name^="_rp_"]', $scope).each(function () {
            // .val() on a checkbox is its value whether ticked or not.
            data[this.name] = this.type === 'checkbox' ? (this.checked ? '1' : '0') : $(this).val();
        });

        return data;
    }

    var retentionpolicyPreviewTimer = null;

    function retentionpolicyPreview(folder, $scope) {
        clearTimeout(retentionpolicyPreviewTimer);
        retentionpolicyPreviewTimer = setTimeout(function () {
            var $box = $('.retentionpolicy-preview', $scope).removeClass('retentionpolicy-error').empty();
            var $row = $('<div class="retentionpolicy-busy">').appendTo($box);
            $('<div class="spinner-border spinner-border-sm">').appendTo($row);
            $('<span>').text(rcmail.gettext('retentionpolicy.checking')).appendTo($row);

            rcmail.http_post('plugin.retentionpolicy.preview', $.extend({_mbox: folder}, retentionpolicyFields($scope)));
        }, 300);
    }

    function retentionpolicyOpen(folder) {
        if (folder) {
            rcmail.http_post('plugin.retentionpolicy.dialog', {_mbox: folder}, rcmail.set_busy(true, 'loading'));
        }
    }

    rcmail.addEventListener('plugin.retentionpolicy_dialog', function (data) {
        var $content = $(data.html),
            dialog = rcmail.simple_dialog($content, data.title, function () {
                rcmail.http_post('plugin.retentionpolicy.save', $.extend({_mbox: data.folder}, retentionpolicyFields($content)),
                    rcmail.set_busy(true, 'saving'));
                return true;
            }, {button: 'save'});

        retentionpolicyToggle('_rp_', $content);
        retentionpolicyPreview(data.folder, $content);

        $('select, input', $content).on('change', function () {
            retentionpolicyToggle('_rp_', $content);
            retentionpolicyPreview(data.folder, $content);
        });

        return dialog;
    });

    rcmail.addEventListener('plugin.retentionpolicy_preview', function (data) {
        var $box = $('.retentionpolicy-dialog .retentionpolicy-preview')
            .toggleClass('retentionpolicy-error', !!data.error)
            .empty()
            .text(data.text);

        retentionpolicyFitDialog($box);
    });

    // The contextmenu plugin builds its folder menu from the folder-actions
    // menu (#mailboxoptions-menu), so the button this plugin adds there
    // shows up on right-click too -- but would act on the *open* folder.
    // Redirect it to the folder that was actually right-clicked, the same
    // way contextmenu itself handles Compact/Empty.
    rcmail.addEventListener('contextmenu_init', function (menu) {
        if (menu.menu_name !== 'folderlist') {
            return;
        }

        menu.addEventListener('activate', function (p) {
            if (p.command === 'plugin.retentionpolicy') {
                return true;
            }
        });

        menu.addEventListener('beforecommand', function (p) {
            if (p.command === 'plugin.retentionpolicy') {
                retentionpolicyOpen(rcmail.env.context_menu_source_id);
                return {abort: true, result: true};
            }
        });
    });

    rcmail.addEventListener('init', function () {
        if (rcmail.env.task === 'mail') {
            rcmail.register_command('plugin.retentionpolicy', function () {
                retentionpolicyOpen(rcmail.env.mailbox);
            }, true);
        }

        // Settings -> Folders edit form (runs inside its own iframe page).
        if (rcmail.env.task === 'settings' && $('#_retention_action').length) {
            retentionpolicyToggle('_retention_', document);
            $('#_retention_action').on('change', function () {
                retentionpolicyToggle('_retention_', document);
            });
        }
    });
}
