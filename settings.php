<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin settings and pages for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/emailstudents:sendsite', context_system::instance())
        || has_capability('local/emailstudents:viewallhistory', context_system::instance())) {

    $ADMIN->add('accounts', new admin_category('local_emailstudents_cat', new lang_string('pluginname', 'local_emailstudents')));
    $ADMIN->add('local_emailstudents_cat', new admin_externalpage('local_emailstudents_compose',
        new lang_string('composenav', 'local_emailstudents'),
        new moodle_url('/local/emailstudents/compose.php'), 'local/emailstudents:sendsite'));
    $ADMIN->add('local_emailstudents_cat', new admin_externalpage('local_emailstudents_templates',
        new lang_string('tab_templates', 'local_emailstudents'),
        new moodle_url('/local/emailstudents/templates.php'), 'local/emailstudents:sendsite'));
    $ADMIN->add('local_emailstudents_cat', new admin_externalpage('local_emailstudents_history',
        new lang_string('tab_history', 'local_emailstudents'),
        new moodle_url('/local/emailstudents/history.php'),
        ['local/emailstudents:sendsite', 'local/emailstudents:viewallhistory']));
}

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_emailstudents', new lang_string('settings', 'local_emailstudents'));
    $ADMIN->add('local_emailstudents_cat', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading('local_emailstudents/mailinfo', '',
            new lang_string('settings_mailinfo', 'local_emailstudents',
                (new moodle_url('/admin/settings.php', ['section' => 'outgoingmailconfig']))->out())));

        $settings->add(new admin_setting_heading('local_emailstudents/hdrsending',
            new lang_string('settings_sending', 'local_emailstudents'), ''));
        $settings->add(new admin_setting_configtext('local_emailstudents/batchsize',
            new lang_string('batchsize', 'local_emailstudents'),
            new lang_string('batchsize_desc', 'local_emailstudents'), 100, PARAM_INT));
        $settings->add(new admin_setting_configduration('local_emailstudents/batchdelay',
            new lang_string('batchdelay', 'local_emailstudents'),
            new lang_string('batchdelay_desc', 'local_emailstudents'), 0, 1));
        $settings->add(new admin_setting_configtext('local_emailstudents/maxrecipients',
            new lang_string('maxrecipients', 'local_emailstudents'),
            new lang_string('maxrecipients_desc', 'local_emailstudents'), 0, PARAM_INT));

        $settings->add(new admin_setting_heading('local_emailstudents/hdrdefaults',
            new lang_string('settings_defaults', 'local_emailstudents'), ''));
        $fromoptions = [
            'noreply' => new lang_string('from_noreply_short', 'local_emailstudents'),
            'support' => new lang_string('from_support_short', 'local_emailstudents'),
            'sender' => new lang_string('from_sender', 'local_emailstudents'),
        ];
        $settings->add(new admin_setting_configselect('local_emailstudents/defaultfrom',
            new lang_string('defaultfrom', 'local_emailstudents'),
            new lang_string('defaultfrom_desc', 'local_emailstudents'), 'noreply', $fromoptions));

        if (!during_initial_install() && !empty($CFG->siteguest)) {
            $roles = [];
            foreach (get_roles_for_contextlevels(CONTEXT_COURSE) as $roleid) {
                $roles[$roleid] = $roleid;
            }
            $allroles = role_fix_names(get_all_roles(), null, ROLENAME_ORIGINAL, true);
            $choices = array_intersect_key($allroles, $roles);
            $defaults = [];
            foreach (['student', 'teacher', 'editingteacher'] as $archetype) {
                foreach (get_archetype_roles($archetype) as $role) {
                    $defaults[$role->id] = 1;
                }
            }
            $settings->add(new admin_setting_configmultiselect('local_emailstudents/allowedroles',
                new lang_string('allowedroles', 'local_emailstudents'),
                new lang_string('allowedroles_desc', 'local_emailstudents'), array_keys($defaults), $choices));
        }

        $settings->add(new admin_setting_heading('local_emailstudents/hdrattach',
            new lang_string('attachments', 'local_emailstudents'), ''));
        $settings->add(new admin_setting_configselect('local_emailstudents/maxattachbytes',
            new lang_string('maxattachbytes', 'local_emailstudents'),
            new lang_string('maxattachbytes_desc', 'local_emailstudents'), 10 * 1024 * 1024, [
                2 * 1024 * 1024 => '2 MB',
                5 * 1024 * 1024 => '5 MB',
                10 * 1024 * 1024 => '10 MB',
                15 * 1024 * 1024 => '15 MB',
                20 * 1024 * 1024 => '20 MB',
                25 * 1024 * 1024 => '25 MB',
            ]));
        $settings->add(new admin_setting_configtext('local_emailstudents/maxattachments',
            new lang_string('maxattachments', 'local_emailstudents'), '', 5, PARAM_INT));
        $settings->add(new admin_setting_configselect('local_emailstudents/defaultattachmode',
            new lang_string('attachmode', 'local_emailstudents'),
            new lang_string('attachmode_help', 'local_emailstudents'), 'attach', [
                'attach' => new lang_string('attachmode_attach', 'local_emailstudents'),
                'links' => new lang_string('attachmode_links', 'local_emailstudents'),
            ]));

        $settings->add(new admin_setting_heading('local_emailstudents/hdrfooter',
            new lang_string('settings_footer', 'local_emailstudents'), ''));
        $settings->add(new admin_setting_confightmleditor('local_emailstudents/footer',
            new lang_string('footer', 'local_emailstudents'),
            new lang_string('footer_desc', 'local_emailstudents'), ''));
        $settings->add(new admin_setting_configtext('local_emailstudents/retentiondays',
            new lang_string('retentiondays', 'local_emailstudents'),
            new lang_string('retentiondays_desc', 'local_emailstudents'), 365, PARAM_INT));
    }
}
