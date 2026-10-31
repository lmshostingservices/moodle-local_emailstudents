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
 * Adhoc task that sends one batch of a student email and re-queues itself until done.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\task;

use local_emailstudents\helper;
use local_emailstudents\sender;

/**
 * Sends a batch of recipients.
 */
class send_message extends \core\task\adhoc_task {
    /**
     * Name for the task log.
     *
     * @return string
     */
    public function get_name() {
        return get_string('tasksend', 'local_emailstudents');
    }

    /**
     * Send the next batch.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $messageid = (int) ($data->messageid ?? 0);
        $message = $DB->get_record('local_emailstudents_msg', ['id' => $messageid]);
        if (!$message || in_array($message->status, ['cancelled', 'complete'])) {
            mtrace("Student email $messageid: nothing to do.");
            return;
        }
        if ($message->timescheduled > time()) {
            helper::queue($message, (int) $message->timescheduled, false);
            mtrace("Student email $messageid: scheduled for " . userdate($message->timescheduled) . '.');
            return;
        }

        if ($message->status !== 'sending') {
            $message->status = 'sending';
            $message->timestarted = $message->timestarted ?: time();
            $message->timemodified = time();
            $DB->update_record('local_emailstudents_msg', $message);
        }

        $batchsize = max(1, (int) (get_config('local_emailstudents', 'batchsize') ?: 100));
        $rows = $DB->get_records('local_emailstudents_rcpt',
            ['messageid' => $messageid, 'status' => helper::RCPT_PENDING], 'id', '*', 0, $batchsize);

        if ($rows) {
            $context = \context::instance_by_id($message->contextid);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'local_emailstudents', helper::AREA_ATTACH, $messageid,
                'filename', false);
            $sender = new sender($message, $files);
            $users = $DB->get_records_list('user', 'id', array_map(fn($r) => $r->userid, $rows));

            $sent = $failed = 0;
            $i = 0;
            foreach ($rows as $row) {
                // Stop promptly if someone cancels part way through a big batch.
                if (++$i % 25 === 0 && $DB->get_field('local_emailstudents_msg', 'status', ['id' => $messageid]) === 'cancelled') {
                    break;
                }
                $user = $users[$row->userid] ?? null;
                if (!$user || $user->deleted || $user->suspended || !validate_email($user->email)) {
                    $row->status = helper::RCPT_SKIPPED;
                } else if ($sender->send_to($user, (int) $row->courseid)) {
                    $row->status = helper::RCPT_SENT;
                    $sent++;
                } else {
                    $row->status = helper::RCPT_FAILED;
                    $failed++;
                }
                $row->timesent = time();
                $DB->update_record('local_emailstudents_rcpt', $row);
            }
            $sender->cleanup();
            mtrace("Student email $messageid: sent $sent, failed $failed.");
        }

        $message = helper::refresh_counts($messageid);
        if ($message->status === 'cancelled') {
            return;
        }

        $remaining = $DB->count_records('local_emailstudents_rcpt',
            ['messageid' => $messageid, 'status' => helper::RCPT_PENDING]);
        if ($remaining > 0) {
            $delay = max(0, (int) get_config('local_emailstudents', 'batchdelay'));
            helper::queue($message, time() + $delay, false);
            mtrace("Student email $messageid: $remaining left, next batch queued.");
            return;
        }

        $message->status = 'complete';
        $message->timecompleted = time();
        $message->timemodified = time();
        $DB->update_record('local_emailstudents_msg', $message);

        if ($message->copytosender) {
            $this->send_copy_to_sender($message);
        }

        \local_emailstudents\event\message_sent::create([
            'objectid' => $message->id,
            'context' => \context::instance_by_id($message->contextid),
            'userid' => $message->senderid,
            'other' => ['sent' => (int) $message->sentcount, 'failed' => (int) $message->failcount],
        ])->trigger();
        mtrace("Student email $messageid: complete.");
    }

    /**
     * Email the sender a copy with a delivery summary.
     *
     * @param \stdClass $message
     */
    protected function send_copy_to_sender(\stdClass $message): void {
        global $DB;
        $senderuser = $DB->get_record('user', ['id' => $message->senderid, 'deleted' => 0]);
        if (!$senderuser) {
            return;
        }
        $context = \context::instance_by_id($message->contextid);
        $files = get_file_storage()->get_area_files($context->id, 'local_emailstudents', helper::AREA_ATTACH,
            $message->id, 'filename', false);
        $copy = clone $message;
        $copy->subject = get_string('copysubject', 'local_emailstudents', $message->subject);
        // Show the copy exactly as one learner received it.
        $sample = $DB->get_record_sql('SELECT r.userid, r.courseid FROM {local_emailstudents_rcpt} r
                                        WHERE r.messageid = ? AND r.status = ? ORDER BY r.id',
            [$message->id, helper::RCPT_SENT], IGNORE_MULTIPLE);
        $sampleuser = $sample ? $DB->get_record('user', ['id' => $sample->userid]) : null;
        $summary = get_string('copysummary', 'local_emailstudents', (object) [
            'sent' => $message->sentcount,
            'failed' => $message->failcount,
            'url' => (new \moodle_url('/local/emailstudents/view.php', ['id' => $message->id]))->out(false),
        ]);
        if ($sampleuser) {
            $summary .= ' ' . get_string('copyexample', 'local_emailstudents', s(fullname($sampleuser)));
        }
        $html = '<p style="background:#f2f7fd;padding:12px;border-radius:8px;">' . $summary . '</p>' .
            sender::rewrite_body($message);
        $sender = new sender($copy, $files, $html);
        $sender->send_to($senderuser, $sample ? (int) $sample->courseid : null, $sampleuser ?: null);
        $sender->cleanup();
    }
}
