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
 * View one student email: preview, delivery stats and recipients.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_emailstudents\helper;
use local_emailstudents\recipients;
use local_emailstudents\output\ui;

$id = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$search = trim(optional_param('search', '', PARAM_TEXT));
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$message = $DB->get_record('local_emailstudents_msg', ['id' => $id], '*', MUST_EXIST);
$context = context::instance_by_id($message->contextid);
$url = new moodle_url('/local/emailstudents/view.php', ['id' => $id]);
$params = $message->courseid ? ['courseid' => $message->courseid] : [];
$historyurl = new moodle_url('/local/emailstudents/history.php', $params);

if ($message->courseid) {
    $course = get_course($message->courseid);
    require_login($course);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->navbar->add(get_string('pluginname', 'local_emailstudents'), $historyurl);
} else {
    require_login();
    admin_externalpage_setup('local_emailstudents_history', '', null, $url, ['pagelayout' => 'admin']);
}
if (!helper::can_view_message($message)) {
    throw new required_capability_exception(context_system::instance(), 'local/emailstudents:viewallhistory', 'nopermissions', '');
}
$PAGE->set_url($url);
$PAGE->set_title(format_string($message->subject));
$PAGE->navbar->add(format_string($message->subject));
$PAGE->add_body_class('lbe-page');

$canmanage = $message->senderid == $USER->id || has_capability('local/emailstudents:viewallhistory', context_system::instance());

// Actions.
if ($action && confirm_sesskey() && $canmanage) {
    if ($action === 'cancel' && in_array($message->status, ['queued', 'sending'])) {
        $message->status = 'cancelled';
        $message->timemodified = time();
        $DB->update_record('local_emailstudents_msg', $message);
        redirect($url, get_string('cancelledok', 'local_emailstudents'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'retry' && $message->failcount > 0 && in_array($message->status, ['complete', 'cancelled'])) {
        $DB->set_field_select('local_emailstudents_rcpt', 'status', helper::RCPT_PENDING,
            'messageid = :id AND status IN (:failed, :skipped)',
            ['id' => $id, 'failed' => helper::RCPT_FAILED, 'skipped' => helper::RCPT_SKIPPED]);
        $message->status = 'queued';
        $message->timescheduled = 0;
        $message->timecompleted = 0;
        $DB->update_record('local_emailstudents_msg', $message);
        helper::refresh_counts($id);
        helper::queue($message, time(), true);
        redirect($url, get_string('retryok', 'local_emailstudents'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($action === 'resume' && $message->status === 'cancelled'
            && $DB->record_exists('local_emailstudents_rcpt', ['messageid' => $id, 'status' => helper::RCPT_PENDING])) {
        $message->status = 'queued';
        $DB->update_record('local_emailstudents_msg', $message);
        helper::queue($message, time(), true);
        redirect($url, get_string('resumeok', 'local_emailstudents'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$status = helper::display_status($message);
$pending = $DB->count_records('local_emailstudents_rcpt', ['messageid' => $id, 'status' => helper::RCPT_PENDING]);
if (in_array($status, ['queued', 'sending'])) {
    // Keep the numbers moving while it sends.
    $PAGE->requires->js_init_code('setTimeout(function () { window.location.reload(); }, 15000);');
}

$sender = $DB->get_record('user', ['id' => $message->senderid]);
$fromuser = helper::get_from_user($message);

echo $OUTPUT->header();
echo html_writer::start_div('lbe-app');

// Header.
$actions = '';
$actions .= html_writer::link(new moodle_url('/local/emailstudents/compose.php', $params + ['copyfrom' => $id]),
    ui::icon('copy') . get_string('useastemplate', 'local_emailstudents'), ['class' => 'lbe-btn']);
if ($canmanage && in_array($message->status, ['queued', 'sending'])) {
    $actions .= html_writer::link(new moodle_url($url, ['action' => 'cancel', 'sesskey' => sesskey()]),
        ui::icon('ban') . get_string('cancelsend', 'local_emailstudents'), ['class' => 'lbe-btn lbe-btn-danger']);
}
if ($canmanage && $message->status === 'cancelled' && $pending) {
    $actions .= html_writer::link(new moodle_url($url, ['action' => 'resume', 'sesskey' => sesskey()]),
        ui::icon('play') . get_string('resume', 'local_emailstudents'), ['class' => 'lbe-btn']);
}
if ($canmanage && $message->failcount > 0 && in_array($message->status, ['complete', 'cancelled'])) {
    $actions .= html_writer::link(new moodle_url($url, ['action' => 'retry', 'sesskey' => sesskey()]),
        ui::icon('rotate-right') . get_string('retryfailed', 'local_emailstudents'), ['class' => 'lbe-btn lbe-btn-primary']);
}

echo html_writer::div(
    html_writer::link($historyurl, ui::icon('arrow-left') . get_string('backtohistory', 'local_emailstudents'),
        ['class' => 'lbe-back']) .
    html_writer::div(
        html_writer::div(
            html_writer::tag('h2', format_string($message->subject), ['class' => 'lbe-view-title']) .
            html_writer::div(ui::pill($status) . html_writer::span(
                get_string('createdby', 'local_emailstudents', (object) [
                    'name' => $sender ? fullname($sender) : '-',
                    'time' => userdate($message->timecreated),
                ]), 'lbe-muted'), 'lbe-view-meta'), 'lbe-view-heading') .
        html_writer::div($actions, 'lbe-actions'), 'lbe-view-head'),
    'lbe-view-top');

// Stats.
$delivered = $message->total ? round($message->sentcount / $message->total * 100) : 0;
echo html_writer::div(
    ui::stat('users', get_string('stat_recipients', 'local_emailstudents'), number_format($message->total), 'accent') .
    ui::stat('circle-check', get_string('stat_delivered', 'local_emailstudents'),
        number_format($message->sentcount) . html_writer::span(" {$delivered}%", 'lbe-stat-sub'), 'good') .
    ui::stat('triangle-exclamation', get_string('stat_failed', 'local_emailstudents'), number_format($message->failcount), 'warn') .
    ui::stat('hourglass-half', get_string('stat_pending', 'local_emailstudents'), number_format($pending), 'neutral'),
    'lbe-stats');
echo html_writer::div(ui::progress((int) $message->sentcount, (int) $message->failcount, (int) $message->total),
    'lbe-progress-wide');

echo html_writer::start_div('lbe-shell');
echo html_writer::start_div('lbe-main');

// Email preview, styled like a mail client.
$files = get_file_storage()->get_area_files($context->id, 'local_emailstudents', helper::AREA_ATTACH, $id, 'filename', false);
$attachhtml = '';
foreach ($files as $file) {
    $fileurl = moodle_url::make_pluginfile_url($context->id, 'local_emailstudents', helper::AREA_ATTACH, $id,
        $file->get_filepath(), $file->get_filename(), true);
    $attachhtml .= html_writer::link($fileurl,
        html_writer::span(ui::icon('paperclip'), 'lbe-file-icon') .
        html_writer::span(s($file->get_filename()), 'lbe-file-name') .
        html_writer::span(display_size($file->get_filesize()), 'lbe-file-size'), ['class' => 'lbe-file']);
}
$replyto = $message->replyto ?: ($message->fromtype !== 'support' && $sender ? $sender->email : '');
$headers = html_writer::div(html_writer::span(get_string('fromtype', 'local_emailstudents'), 'lbe-k') .
        html_writer::span(s(fullname($fromuser)) . ' &lt;' . s($fromuser->email) . '&gt;', 'lbe-v'), 'lbe-mailrow') .
    ($replyto ? html_writer::div(html_writer::span(get_string('replyto', 'local_emailstudents'), 'lbe-k') .
        html_writer::span(s($replyto), 'lbe-v'), 'lbe-mailrow') : '') .
    html_writer::div(html_writer::span(get_string('subject', 'local_emailstudents'), 'lbe-k') .
        html_writer::span(s($message->subject), 'lbe-v lbe-strong'), 'lbe-mailrow');
if ($message->timescheduled) {
    $headers .= html_writer::div(html_writer::span(get_string('schedule', 'local_emailstudents'), 'lbe-k') .
        html_writer::span(userdate($message->timescheduled), 'lbe-v'), 'lbe-mailrow');
}
echo html_writer::div(
    html_writer::div(ui::icon('envelope-open-text') . get_string('preview', 'local_emailstudents'), 'lbe-card-eyebrow') .
    html_writer::div($headers, 'lbe-mailhead') .
    html_writer::div(\local_emailstudents\sender::rewrite_body($message), 'lbe-mailbody') .
    ($attachhtml ? html_writer::div($attachhtml, 'lbe-files') : ''),
    'lbe-card lbe-mail');

// Recipients.
$filters = [
    'all' => null,
    'sent' => helper::RCPT_SENT,
    'failed' => helper::RCPT_FAILED,
    'pending' => helper::RCPT_PENDING,
    'skipped' => helper::RCPT_SKIPPED,
];
if (!array_key_exists($filter, $filters)) {
    $filter = 'all';
}
$counts = $DB->get_records_sql_menu('SELECT status, COUNT(1) FROM {local_emailstudents_rcpt} WHERE messageid = ? GROUP BY status',
    [$id]);
$segs = '';
foreach ($filters as $key => $code) {
    $n = $code === null ? array_sum($counts) : (int) ($counts[$code] ?? 0);
    if ($code !== null && !$n && $key !== $filter) {
        continue;
    }
    $segs .= html_writer::link(new moodle_url($url, ['filter' => $key, 'search' => $search]),
        get_string('filter_' . $key, 'local_emailstudents') . html_writer::span(number_format($n), 'lbe-seg-n'),
        ['class' => 'lbe-seg' . ($filter === $key ? ' is-active' : '')]);
}
$searchform = html_writer::start_tag('form', ['method' => 'get', 'class' => 'lbe-search lbe-search-sm']) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]) .
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'filter', 'value' => $filter]) .
    ui::icon('magnifying-glass', 'lbe-search-icon') .
    html_writer::empty_tag('input', ['type' => 'search', 'name' => 'search', 'value' => $search,
        'placeholder' => get_string('searchpeople', 'local_emailstudents'), 'class' => 'lbe-input']) .
    html_writer::end_tag('form');

$where = 'r.messageid = :id';
$sqlparams = ['id' => $id];
if ($filters[$filter] !== null) {
    $where .= ' AND r.status = :status';
    $sqlparams['status'] = $filters[$filter];
}
if ($search !== '') {
    $fullname = $DB->sql_fullname('u.firstname', 'u.lastname');
    $where .= ' AND (' . $DB->sql_like($fullname, ':s1', false) . ' OR ' . $DB->sql_like('u.email', ':s2', false) . ')';
    $sqlparams['s1'] = $sqlparams['s2'] = '%' . $DB->sql_like_escape($search) . '%';
}
$userfields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
$totalrows = $DB->count_records_sql("SELECT COUNT(1) FROM {local_emailstudents_rcpt} r JOIN {user} u ON u.id = r.userid
                                      WHERE $where", $sqlparams);
$rows = $DB->get_records_sql("SELECT r.*, u.email, $userfields
                                FROM {local_emailstudents_rcpt} r
                                JOIN {user} u ON u.id = r.userid
                               WHERE $where
                            ORDER BY u.lastname, u.firstname, r.id", $sqlparams, $page * $perpage, $perpage);
$statusnames = [
    helper::RCPT_PENDING => ['pending', 'hourglass-half'],
    helper::RCPT_SENT => ['sent', 'circle-check'],
    helper::RCPT_FAILED => ['failed', 'circle-xmark'],
    helper::RCPT_SKIPPED => ['skipped', 'circle-minus'],
];
$list = '';
foreach ($rows as $r) {
    $r->id = $r->userid;
    [$name, $icon] = $statusnames[$r->status] ?? ['pending', 'circle'];
    $list .= html_writer::div(
        ui::avatar($r) .
        html_writer::div(html_writer::div(s(fullname($r)), 'lbe-person-name') .
            html_writer::div(s($r->email), 'lbe-person-email'), 'lbe-person') .
        html_writer::div($r->timesent ? userdate($r->timesent, get_string('strftimedatetimeshort', 'core_langconfig')) : '',
            'lbe-muted lbe-when') .
        html_writer::span(ui::icon($icon) . get_string('filter_' . $name, 'local_emailstudents'), "lbe-rstatus is-$name"),
        'lbe-recipient');
}
if (!$list) {
    $list = html_writer::div(get_string('nomatches', 'local_emailstudents'), 'lbe-muted lbe-pad');
}
echo html_writer::div(
    html_writer::div(
        html_writer::div(ui::icon('users') . get_string('recipients', 'local_emailstudents'), 'lbe-card-eyebrow') .
        $searchform, 'lbe-card-head') .
    html_writer::div($segs, 'lbe-segments') .
    html_writer::div($list, 'lbe-recipients') .
    $OUTPUT->paging_bar($totalrows, $page, $perpage, new moodle_url($url, ['filter' => $filter, 'search' => $search])),
    'lbe-card');

echo html_writer::end_div();

// Side: audience filters used.
$chips = '';
foreach (recipients::describe(json_decode($message->filters ?? '', true) ?: []) as $label => $value) {
    $chips .= html_writer::div(html_writer::div($label, 'lbe-k') . html_writer::div($value, 'lbe-v'), 'lbe-def');
}
$side = html_writer::div(
    html_writer::div(ui::icon('filter') . get_string('audiencefilters', 'local_emailstudents'), 'lbe-card-eyebrow') .
    html_writer::div($chips, 'lbe-defs'), 'lbe-card');
$timeline = '';
$steps = [
    ['plus', get_string('tl_created', 'local_emailstudents'), $message->timecreated],
    ['calendar-days', get_string('tl_scheduled', 'local_emailstudents'), $message->timescheduled],
    ['paper-plane', get_string('tl_started', 'local_emailstudents'), $message->timestarted],
    ['flag-checkered', get_string('tl_completed', 'local_emailstudents'), $message->timecompleted],
];
foreach ($steps as [$icon, $label, $time]) {
    if (!$time) {
        continue;
    }
    $timeline .= html_writer::div(html_writer::span(ui::icon($icon), 'lbe-tl-dot') .
        html_writer::div(html_writer::div($label, 'lbe-tl-label') .
            html_writer::div(userdate($time), 'lbe-muted'), 'lbe-tl-text'), 'lbe-tl-item');
}
$side .= html_writer::div(
    html_writer::div(ui::icon('timeline') . get_string('timeline', 'local_emailstudents'), 'lbe-card-eyebrow') .
    html_writer::div($timeline, 'lbe-tl'), 'lbe-card');
echo html_writer::tag('aside', $side, ['class' => 'lbe-aside']);

echo html_writer::end_div();
echo html_writer::end_div();
echo $OUTPUT->footer();
