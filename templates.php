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
 * Manage saved email templates.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_emailstudents\output\ui;
use local_emailstudents\templates;

$courseid = optional_param('courseid', 0, PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$share = optional_param('share', -1, PARAM_INT);
$id = optional_param('id', 0, PARAM_INT);
if ($courseid == SITEID) {
    $courseid = 0;
}
$params = $courseid ? ['courseid' => $courseid] : [];
$url = new moodle_url('/local/emailstudents/templates.php', $params);

if ($courseid) {
    [$context, $course] = \local_emailstudents\helper::require_send_access($courseid);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->navbar->add(get_string('pluginname', 'local_emailstudents'), new moodle_url('/local/emailstudents/compose.php', $params));
    $PAGE->navbar->add(get_string('tab_templates', 'local_emailstudents'));
} else {
    require_login();
    require_capability('local/emailstudents:sendsite', context_system::instance());
    admin_externalpage_setup('local_emailstudents_templates', '', null, $url, ['pagelayout' => 'admin']);
}
$PAGE->set_title(get_string('tab_templates', 'local_emailstudents'));
$PAGE->add_body_class('lbe-page');
$canshare = has_capability('local/emailstudents:sendsite', context_system::instance());

if (($delete || ($id && $share >= 0)) && confirm_sesskey()) {
    $template = $DB->get_record('local_emailstudents_tpl', ['id' => $delete ?: $id], '*', MUST_EXIST);
    if (!templates::can_manage($template, $USER->id)) {
        throw new required_capability_exception(context_system::instance(), 'local/emailstudents:sendsite', 'nopermissions', '');
    }
    if ($delete) {
        templates::delete($template->id);
        redirect($url, get_string('templatedeleted', 'local_emailstudents'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($canshare && !$template->starter) {
        $DB->set_field('local_emailstudents_tpl', 'shared', $share ? 1 : 0, ['id' => $template->id]);
        redirect($url);
    }
}

$records = $DB->get_records_select('local_emailstudents_tpl', 'starter = 1 OR shared = 1 OR userid = ?', [$USER->id],
    'starter ASC, timemodified DESC');
$owners = [];
if ($records) {
    $ownerids = array_filter(array_unique(array_column($records, 'userid')));
    if ($ownerids) {
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        $owners = $DB->get_records_list('user', 'id', $ownerids, '', 'id, ' . $fields);
    }
}

echo $OUTPUT->header();
echo html_writer::start_div('lbe-app');
echo ui::hero(get_string('pluginname', 'local_emailstudents'), get_string('herosub_templates', 'local_emailstudents'),
    'templates', $courseid);

$cards = '';
foreach ($records as $t) {
    $use = html_writer::link(new moodle_url('/local/emailstudents/compose.php', $params + ['template' => $t->id]),
        ui::icon('pen-nib') . get_string('usetemplate', 'local_emailstudents'), ['class' => 'lbe-btn lbe-btn-primary lbe-btn-sm']);
    $actions = $use;
    if (templates::can_manage($t, $USER->id) && !$t->starter) {
        if ($canshare) {
            $actions .= html_writer::link(new moodle_url($url, ['id' => $t->id, 'share' => $t->shared ? 0 : 1,
                'sesskey' => sesskey()]), ui::icon($t->shared ? 'lock' : 'share-nodes') .
                get_string($t->shared ? 'unshare' : 'share', 'local_emailstudents'), ['class' => 'lbe-btn lbe-btn-sm']);
        }
        $actions .= html_writer::link(new moodle_url($url, ['delete' => $t->id, 'sesskey' => sesskey()]),
            ui::icon('trash-can'), ['class' => 'lbe-btn lbe-btn-sm lbe-btn-danger', 'title' => get_string('delete'),
            'aria-label' => get_string('delete')]);
    }
    if ($t->starter) {
        $badge = html_writer::span(ui::icon('wand-magic-sparkles') . get_string('tplgroup_starter', 'local_emailstudents'),
            'lbe-pill is-scheduled');
    } else if ($t->userid == $USER->id) {
        $badge = html_writer::span(ui::icon($t->shared ? 'share-nodes' : 'lock') .
            get_string($t->shared ? 'tplshared' : 'tplprivate', 'local_emailstudents'), 'lbe-pill is-cancelled');
    } else {
        $owner = isset($owners[$t->userid]) ? fullname($owners[$t->userid]) : '';
        $badge = html_writer::span(ui::icon('user') . s($owner), 'lbe-pill is-queued');
    }
    $excerpt = shorten_text(html_to_text(format_text($t->body, $t->bodyformat, ['filter' => false]), 0, false), 140);
    $cards .= html_writer::div(
        html_writer::div($badge, 'lbe-tpl-top') .
        html_writer::tag('h3', format_string($t->name), ['class' => 'lbe-tpl-name']) .
        html_writer::div(s($t->subject), 'lbe-tpl-subject') .
        html_writer::div(s($excerpt), 'lbe-tpl-excerpt') .
        html_writer::div($actions, 'lbe-tpl-actions'),
        'lbe-card lbe-tpl');
}
echo html_writer::div($cards, 'lbe-tpl-grid');

echo html_writer::end_div();
echo $OUTPUT->footer();
