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
 * Sent email history.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_emailstudents\helper;
use local_emailstudents\output\ui;

$courseid = optional_param('courseid', 0, PARAM_INT);
$page = optional_param('page', 0, PARAM_INT);
$search = trim(optional_param('search', '', PARAM_TEXT));
$perpage = 20;
if ($courseid == SITEID) {
    $courseid = 0;
}

$params = $courseid ? ['courseid' => $courseid] : [];
$url = new moodle_url('/local/emailstudents/history.php', $params);
$syscontext = context_system::instance();
$cancompose = false;

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($courseid);
    $cancompose = has_capability('local/emailstudents:sendcourse', $context) || has_capability('local/emailstudents:sendsite', $syscontext);
    if (!$cancompose) {
        require_capability('local/emailstudents:sendcourse', $context);
    }
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('tab_history', 'local_emailstudents'));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->navbar->add(get_string('pluginname', 'local_emailstudents'), new moodle_url('/local/emailstudents/compose.php', $params));
    $PAGE->navbar->add(get_string('tab_history', 'local_emailstudents'));
} else {
    require_login();
    $cancompose = has_capability('local/emailstudents:sendsite', $syscontext);
    if (!$cancompose) {
        require_capability('local/emailstudents:viewallhistory', $syscontext);
    }
    admin_externalpage_setup('local_emailstudents_history', '', null, $url, ['pagelayout' => 'admin']);
    $PAGE->set_title(get_string('tab_history', 'local_emailstudents'));
}
$PAGE->add_body_class('lbe-page');

// Which messages can this person see?
$where = [];
$sqlparams = [];
if ($courseid) {
    $where[] = 'm.courseid = :courseid';
    $sqlparams['courseid'] = $courseid;
    if (!has_capability('local/emailstudents:viewallhistory', $syscontext)
            && !has_capability('moodle/course:viewparticipants', $context)) {
        $where[] = 'm.senderid = :me';
        $sqlparams['me'] = $USER->id;
    }
} else if (!has_capability('local/emailstudents:viewallhistory', $syscontext)) {
    $where[] = 'm.senderid = :me';
    $sqlparams['me'] = $USER->id;
}
if ($search !== '') {
    $where[] = $DB->sql_like('m.subject', ':search', false);
    $sqlparams['search'] = '%' . $DB->sql_like_escape($search) . '%';
}
$wheresql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total = $DB->count_records_sql("SELECT COUNT(1) FROM {local_emailstudents_msg} m $wheresql", $sqlparams);
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$messages = $DB->get_records_sql("SELECT m.*, $userfields, c.shortname AS courseshortname
                                    FROM {local_emailstudents_msg} m
                               LEFT JOIN {user} u ON u.id = m.senderid
                               LEFT JOIN {course} c ON c.id = m.courseid
                                  $wheresql
                                ORDER BY m.timecreated DESC, m.id DESC", $sqlparams, $page * $perpage, $perpage);

// Totals for the stat row.
$stats = $DB->get_record_sql("SELECT COUNT(1) AS messages, COALESCE(SUM(m.sentcount), 0) AS sent,
                                     COALESCE(SUM(m.failcount), 0) AS failed,
                                     COALESCE(SUM(CASE WHEN m.status IN ('queued', 'sending') THEN 1 ELSE 0 END), 0) AS active
                                FROM {local_emailstudents_msg} m $wheresql", $sqlparams);

echo $OUTPUT->header();
echo html_writer::start_div('lbe-app');
echo ui::hero(get_string('pluginname', 'local_emailstudents'), get_string('herosub_history', 'local_emailstudents'),
    'history', $courseid, $cancompose);

echo html_writer::div(
    ui::stat('envelope', get_string('stat_messages', 'local_emailstudents'), number_format($stats->messages), 'accent') .
    ui::stat('circle-check', get_string('stat_delivered', 'local_emailstudents'), number_format($stats->sent), 'good') .
    ui::stat('triangle-exclamation', get_string('stat_failed', 'local_emailstudents'), number_format($stats->failed), 'warn') .
    ui::stat('hourglass-half', get_string('stat_inprogress', 'local_emailstudents'), number_format($stats->active), 'neutral'),
    'lbe-stats');

// Toolbar: search + new message.
$searchform = html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out_omit_querystring(), 'class' => 'lbe-search']);
if ($courseid) {
    $searchform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
}
$searchform .= ui::icon('magnifying-glass', 'lbe-search-icon');
$searchform .= html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'value' => $search,
    'placeholder' => get_string('searchsubjects', 'local_emailstudents'), 'class' => 'lbe-input']);
$searchform .= html_writer::end_tag('form');
$newbutton = $cancompose ? html_writer::link(new moodle_url('/local/emailstudents/compose.php', $params),
    ui::icon('plus') . get_string('newmessage', 'local_emailstudents'), ['class' => 'lbe-btn lbe-btn-primary']) : '';
echo html_writer::div($searchform . $newbutton, 'lbe-toolbar');

if (!$messages) {
    echo html_writer::div(
        html_writer::div(ui::icon('inbox'), 'lbe-empty-icon') .
        html_writer::tag('h3', get_string('nohistory', 'local_emailstudents')) .
        html_writer::tag('p', get_string('nohistory_desc', 'local_emailstudents'), ['class' => 'lbe-muted']) .
        $newbutton,
        'lbe-card lbe-empty');
} else {
    $rows = '';
    foreach ($messages as $m) {
        $status = helper::display_status($m);
        $viewurl = new moodle_url('/local/emailstudents/view.php', ['id' => $m->id]);
        $wherelabel = $m->courseid ? s($m->courseshortname) : get_string('sitewide', 'local_emailstudents');
        $when = $m->timescheduled > time() ? $m->timescheduled : $m->timecreated;
        $rows .= html_writer::tag('a',
            html_writer::div(
                html_writer::div(s($m->subject), 'lbe-row-title') .
                html_writer::div(ui::icon('user', 'lbe-dim') . s(fullname($m)) . html_writer::span('·', 'lbe-dot') .
                    ui::icon($m->courseid ? 'graduation-cap' : 'globe', 'lbe-dim') . $wherelabel . html_writer::span('·', 'lbe-dot') .
                    userdate($when, get_string('strftimedatetimeshort', 'core_langconfig')), 'lbe-row-meta'),
                'lbe-row-main') .
            html_writer::div(
                html_writer::div(get_string('sentof', 'local_emailstudents', (object) [
                    'sent' => number_format($m->sentcount), 'total' => number_format($m->total)]), 'lbe-row-count') .
                ui::progress((int) $m->sentcount, (int) $m->failcount, (int) $m->total), 'lbe-row-progress') .
            html_writer::div(ui::pill($status), 'lbe-row-status') .
            html_writer::div(ui::icon('chevron-right'), 'lbe-row-go'),
            ['href' => $viewurl, 'class' => 'lbe-row']);
    }
    echo html_writer::div($rows, 'lbe-card lbe-list');
    echo $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url($url, ['search' => $search]));
}

echo html_writer::end_div();
echo $OUTPUT->footer();
