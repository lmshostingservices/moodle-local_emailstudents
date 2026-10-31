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
 * Compose and send a student email.
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

$courseid = optional_param('courseid', 0, PARAM_INT);
$copyfrom = optional_param('copyfrom', 0, PARAM_INT);
$templateid = optional_param('template', 0, PARAM_INT);
if ($courseid == SITEID) {
    $courseid = 0;
}

$url = new moodle_url('/local/emailstudents/compose.php', $courseid ? ['courseid' => $courseid] : []);
if ($courseid) {
    [$context, $course] = helper::require_send_access($courseid);
    $PAGE->set_url($url);
    $PAGE->set_pagelayout('incourse');
    $PAGE->set_title(get_string('pluginname', 'local_emailstudents') . ': ' . format_string($course->shortname));
    $PAGE->set_heading(format_string($course->fullname));
    $PAGE->navbar->add(get_string('pluginname', 'local_emailstudents'), $url);
} else {
    require_login();
    $context = context_system::instance();
    require_capability('local/emailstudents:sendsite', $context);
    $course = null;
    admin_externalpage_setup('local_emailstudents_compose', '', null, $url, ['pagelayout' => 'admin']);
    $PAGE->set_title(get_string('pluginname', 'local_emailstudents'));
}
$PAGE->add_body_class('lbe-page');

$editoroptions = helper::editor_options($context);
$attachoptions = helper::attachment_options();

// Start from scratch, or from a previous message ("Use as template").
$data = new stdClass();
$data->courseid = $courseid;
$data->body = '';
$data->bodyformat = FORMAT_HTML;
$selectedgroups = [];
$selectedassignments = [];
if ($copyfrom) {
    $source = $DB->get_record('local_emailstudents_msg', ['id' => $copyfrom], '*', MUST_EXIST);
    if (!helper::can_view_message($source)) {
        throw new moodle_exception('nopermissions', 'error', '', get_string('tab_history', 'local_emailstudents'));
    }
    $sourcecontext = context::instance_by_id($source->contextid);
    $data->subject = $source->subject;
    $data->body = $source->body;
    $data->bodyformat = $source->bodyformat;
    $data->replyto = $source->replyto;
    $data->replytoname = $source->replytoname;
    $data->fromtype = $source->fromtype;
    $data->attachmode = $source->attachmode;
    $data->copytosender = $source->copytosender;
    $filters = json_decode($source->filters ?? '', true) ?: [];
    foreach ($filters as $key => $value) {
        $data->$key = $value;
    }
    $selectedgroups = $filters['groups'] ?? [];
    $selectedassignments = $filters['assignments'] ?? [];
    $data = file_prepare_standard_editor($data, 'body', $editoroptions, $sourcecontext, 'local_emailstudents',
        helper::AREA_BODY, $source->id);
    $draftid = file_get_submitted_draft_itemid('attachments');
    file_prepare_draft_area($draftid, $sourcecontext->id, 'local_emailstudents', helper::AREA_ATTACH, $source->id,
        $attachoptions);
    $data->attachments = $draftid;
} else if ($templateid && ($tpl = $DB->get_record('local_emailstudents_tpl', ['id' => $templateid]))
        && ($tpl->starter || $tpl->shared || $tpl->userid == $USER->id)) {
    $data->subject = $tpl->subject;
    $data->body = $tpl->body;
    $data->bodyformat = $tpl->bodyformat;
    $data = file_prepare_standard_editor($data, 'body', $editoroptions, context_system::instance(), 'local_emailstudents',
        'template', $tpl->id);
} else {
    $data = file_prepare_standard_editor($data, 'body', $editoroptions, $context, 'local_emailstudents',
        helper::AREA_BODY, null);
}
$submittedgroups = optional_param_array('groups', [], PARAM_INT);
$selectedgroups = array_unique(array_merge($selectedgroups, $submittedgroups));
$selectedassignments = array_unique(array_merge($selectedassignments, optional_param_array('assignments', [], PARAM_INT)));

$canchoosefrom = has_capability('local/emailstudents:choosefrom', context_system::instance());
$mform = new \local_emailstudents\form\compose_form($url, [
    'courseid' => $courseid,
    'context' => $context,
    'userid' => $USER->id,
    'canchoosefrom' => $canchoosefrom,
    'editoroptions' => $editoroptions,
    'attachoptions' => $attachoptions,
    'selectedgroups' => $selectedgroups,
    'selectedassignments' => $selectedassignments,
    'templates' => \local_emailstudents\templates::options($USER->id),
], 'post', '', ['class' => 'lbe-form', 'data-lbe-form' => 1]);
$mform->set_data($data);

$historyurl = new moodle_url('/local/emailstudents/history.php', $courseid ? ['courseid' => $courseid] : []);
if ($mform->is_cancelled()) {
    redirect($historyurl);
}

$notice = '';
if ($mform->no_submit_button_pressed() && optional_param('savetemplate', '', PARAM_TEXT) !== '') {
    require_sesskey();
    $sub = $mform->get_submitted_data();
    $name = trim($sub->templatename ?? '');
    $editor = (array) ($sub->body_editor ?? []);
    if (trim($sub->subject ?? '') === '' && trim(strip_tags($editor['text'] ?? '')) === '') {
        $notice = $OUTPUT->notification(get_string('templateempty', 'local_emailstudents'), 'warning');
    } else {
        $shared = !empty($sub->templateshared) && has_capability('local/emailstudents:sendsite', context_system::instance());
        \local_emailstudents\templates::save($USER->id, $name, (string) ($sub->subject ?? ''), $editor, $shared);
        $notice = $OUTPUT->notification(get_string('templatesaved', 'local_emailstudents', s($name ?: $sub->subject)),
            'success');
    }
} else if ($mform->no_submit_button_pressed() && optional_param('testbutton', '', PARAM_TEXT) !== '') {
    // Send a test copy to the sender.
    require_sesskey();
    $sub = $mform->get_submitted_data();
    $test = (object) [
        'id' => 0,
        'senderid' => $USER->id,
        'contextid' => $context->id,
        'courseid' => $courseid,
        'subject' => '[' . get_string('test', 'local_emailstudents') . '] ' . ($sub->subject ?? ''),
        'body' => $sub->body_editor['text'] ?? '',
        'bodyformat' => $sub->body_editor['format'] ?? FORMAT_HTML,
        'fromtype' => $canchoosefrom ? ($sub->fromtype ?? 'noreply') : (get_config('local_emailstudents', 'defaultfrom') ?: 'noreply'),
        'replyto' => $sub->replyto ?? '',
        'replytoname' => $sub->replytoname ?? '',
        'attachmode' => 'attach',
    ];
    $files = [];
    if (!empty($sub->attachments)) {
        $files = get_file_storage()->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft',
            $sub->attachments, 'filename', false);
    }
    $html = \local_emailstudents\sender::format_body($test->body, (int) $test->bodyformat, $context);
    $sender = new \local_emailstudents\sender($test, $files, $html);
    $me = $DB->get_record('user', ['id' => $USER->id]);
    // Fill course tags from a course the sender picked, so the test shows real values.
    $testcourse = $courseid ?: (int) (optional_param_array('courses', [], PARAM_INT)[0] ?? 0);
    $ok = $sender->send_to($me, $testcourse);
    $sender->cleanup();
    $notice = $OUTPUT->notification(get_string($ok ? 'testsent' : 'testfailed', 'local_emailstudents', s($USER->email)),
        $ok ? 'success' : 'error');
} else if ($formdata = $mform->get_data()) {
    $filters = recipients::normalise($formdata, $courseid);
    $now = time();
    $message = (object) [
        'senderid' => $USER->id,
        'contextid' => $context->id,
        'courseid' => $courseid,
        'subject' => trim($formdata->subject),
        'body' => '',
        'bodyformat' => FORMAT_HTML,
        'fromtype' => $canchoosefrom ? $formdata->fromtype : (get_config('local_emailstudents', 'defaultfrom') ?: 'noreply'),
        'replyto' => trim($formdata->replyto ?? ''),
        'replytoname' => trim($formdata->replytoname ?? ''),
        'filters' => json_encode($filters),
        'attachmode' => $formdata->attachmode === 'links' ? 'links' : 'attach',
        'copytosender' => empty($formdata->copytosender) ? 0 : 1,
        'status' => 'queued',
        'timescheduled' => empty($formdata->timescheduled) ? 0 : (int) $formdata->timescheduled,
        'timecreated' => $now,
        'timemodified' => $now,
    ];
    $transaction = $DB->start_delegated_transaction();
    $message->id = $DB->insert_record('local_emailstudents_msg', $message);

    $formdata = file_postupdate_standard_editor($formdata, 'body', $editoroptions, $context, 'local_emailstudents',
        helper::AREA_BODY, $message->id);
    file_save_draft_area_files($formdata->attachments, $context->id, 'local_emailstudents', helper::AREA_ATTACH,
        $message->id, $attachoptions);
    $message->body = $formdata->body;
    $message->bodyformat = $formdata->bodyformat;

    // Snapshot the recipient list now, so later enrolment changes don't alter who gets it.
    $total = 0;
    $batch = [];
    $rs = recipients::recordset($filters);
    foreach ($rs as $row) {
        $batch[] = ['messageid' => $message->id, 'userid' => $row->id, 'courseid' => $courseid ?: ((int) $row->ctxkey % 1000000),
            'status' => helper::RCPT_PENDING, 'timesent' => 0];
        $total++;
        if (count($batch) >= 1000) {
            $DB->insert_records('local_emailstudents_rcpt', $batch);
            $batch = [];
        }
    }
    $rs->close();
    if ($batch) {
        $DB->insert_records('local_emailstudents_rcpt', $batch);
    }
    $message->total = $total;
    $DB->update_record('local_emailstudents_msg', $message);
    $transaction->allow_commit();

    helper::queue($message);
    \local_emailstudents\event\message_queued::create([
        'objectid' => $message->id,
        'context' => $context,
        'other' => ['total' => $total],
    ])->trigger();

    $key = $message->timescheduled > time() ? 'scheduledok' : 'queuedok';
    redirect(new moodle_url('/local/emailstudents/view.php', ['id' => $message->id]),
        get_string($key, 'local_emailstudents', (object) [
            'count' => number_format($total),
            'time' => userdate($message->timescheduled ?: time()),
        ]), null, \core\output\notification::NOTIFY_SUCCESS);
}

$PAGE->requires->js_call_amd('local_emailstudents/compose', 'init', [[
    'courseid' => $courseid,
    'canshare' => has_capability('local/emailstudents:sendsite', context_system::instance()),
]]);
// Template bodies can be long, so they travel as JSON in the page rather than as call arguments.
$templatejson = json_encode(\local_emailstudents\templates::payload($USER->id), JSON_HEX_TAG | JSON_HEX_AMP);

echo $OUTPUT->header();
echo html_writer::start_div('lbe-app');
echo ui::hero(get_string('pluginname', 'local_emailstudents'),
    $courseid ? get_string('herosub_course', 'local_emailstudents', format_string($course->shortname))
        : get_string('herosub_site', 'local_emailstudents'), 'compose', $courseid);
echo $notice;

echo html_writer::start_div('lbe-shell');
echo html_writer::start_div('lbe-main');
$mform->display();
echo html_writer::end_div();

// Live summary panel.
$chips = '';
foreach (helper::placeholder_groups() as $label => $tokens) {
    $groupchips = '';
    foreach ($tokens as $p) {
        $groupchips .= html_writer::tag('button', s($p), ['type' => 'button', 'class' => 'lbe-chip',
            'data-lbe-placeholder' => $p, 'title' => get_string('insertplaceholder', 'local_emailstudents')]);
    }
    $chips .= html_writer::div(html_writer::div(s($label), 'lbe-chipgroup-label') .
        html_writer::div($groupchips, 'lbe-chips'), 'lbe-chipgroup');
}
$aside = html_writer::div(
    html_writer::div(ui::icon('users') . get_string('summary_audience', 'local_emailstudents'), 'lbe-card-eyebrow') .
    html_writer::div(
        html_writer::span('—', 'lbe-count-number', ['data-lbe-count' => 1]) .
        html_writer::span(get_string('summary_recipients', 'local_emailstudents'), 'lbe-count-label'), 'lbe-count') .
    html_writer::div('', 'lbe-count-warning', ['data-lbe-warning' => 1, 'hidden' => 'hidden']) .
    html_writer::tag('ul', '', ['class' => 'lbe-people', 'data-lbe-people' => 1]) .
    html_writer::div('', 'lbe-more', ['data-lbe-more' => 1]),
    'lbe-card lbe-summary');
$aside .= html_writer::div(
    html_writer::div(ui::icon('wand-magic-sparkles') . get_string('summary_personalise', 'local_emailstudents'),
        'lbe-card-eyebrow') .
    html_writer::tag('p', get_string('summary_personalise_desc', 'local_emailstudents'), ['class' => 'lbe-muted']) .
    $chips,
    'lbe-card');
$aside .= html_writer::div(
    html_writer::div(ui::icon('server') . get_string('summary_delivery', 'local_emailstudents'), 'lbe-card-eyebrow') .
    helper::mail_status(),
    'lbe-card lbe-delivery');
echo html_writer::tag('aside', $aside, ['class' => 'lbe-aside']);

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::tag('script', $templatejson, ['type' => 'application/json', 'id' => 'lbe-templates']);
echo $OUTPUT->footer();
