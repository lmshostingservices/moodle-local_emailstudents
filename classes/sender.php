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
 * Sends student email messages through Moodle's own mail settings.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents;

use context;
use stdClass;
use stored_file;

/**
 * Renders and sends one message to one recipient using email_to_user().
 *
 * email_to_user() uses the site's Outgoing mail configuration (SMTP host, security, auth,
 * no-reply address, allowed domains, DKIM and the divert/no-email debugging switches),
 * so this plugin never needs its own mail settings.
 */
class sender {
    /** @var stdClass The message record. */
    protected $message;

    /** @var stdClass From user. */
    protected $from;

    /** @var stdClass|null Course the message was sent from. */
    protected $course;

    /** @var string Body HTML with file URLs rewritten, before personalisation. */
    protected $bodyhtml;

    /** @var string|null Attachment path for email_to_user(). */
    protected $attachpath = null;

    /** @var string|null Attachment name for email_to_user(). */
    protected $attachname = null;

    /** @var string|null Temp dir to remove when done. */
    protected $tempdir = null;

    /** @var personaliser */
    protected $personaliser;

    /** @var string Footer HTML. */
    protected $footer = '';

    /**
     * Constructor.
     *
     * @param stdClass $message Message record (or an unsaved one for a test send).
     * @param stored_file[] $files Attachment files.
     * @param string|null $bodyhtml Body HTML ready to send; built from the message when null.
     */
    public function __construct(stdClass $message, array $files = [], ?string $bodyhtml = null) {
        global $DB;
        $this->message = $message;
        $this->from = helper::get_from_user($message);
        $this->course = !empty($message->courseid) ? $DB->get_record('course', ['id' => $message->courseid]) : null;
        $this->bodyhtml = $bodyhtml ?? self::rewrite_body($message);
        $this->prepare_attachments($files);

        $footer = (string) get_config('local_emailstudents', 'footer');
        if (trim(strip_tags($footer, '<img>')) !== '') {
            $this->footer = format_text($footer, FORMAT_HTML, ['filter' => false, 'para' => false]);
        }
        $senderuser = !empty($message->senderid) ? $DB->get_record('user', ['id' => $message->senderid]) : null;
        $this->personaliser = new personaliser($message->subject . ' ' . $this->bodyhtml . ' ' . $this->footer,
            $senderuser ?: null);
    }

    /**
     * Rewrite @@PLUGINFILE@@ links in a saved message body into public URLs.
     *
     * @param stdClass $message
     * @return string
     */
    public static function rewrite_body(stdClass $message): string {
        $html = file_rewrite_pluginfile_urls($message->body, 'pluginfile.php', $message->contextid,
            'local_emailstudents', helper::AREA_BODY, $message->id);
        return self::format_body($html, (int) $message->bodyformat, \context::instance_by_id($message->contextid));
    }

    /**
     * Format the editor content as email-safe HTML.
     *
     * @param string $text
     * @param int $format
     * @param context $context
     * @return string
     */
    public static function format_body(string $text, int $format, context $context): string {
        return format_text($text, $format, [
            'context' => $context,
            'para' => false,
            'filter' => false,
            'noclean' => false,
            'overflowdiv' => false,
            'allowid' => false,
        ]);
    }

    /**
     * Prepare attachments for sending: one file is attached as is, several are zipped,
     * or in links mode a list of download links is added to the body.
     *
     * @param stored_file[] $files
     */
    protected function prepare_attachments(array $files): void {
        global $CFG;
        $files = array_values(array_filter($files, fn($f) => !$f->is_directory()));
        if (!$files) {
            return;
        }

        if (($this->message->attachmode ?? 'attach') === 'links') {
            $items = [];
            foreach ($files as $file) {
                $url = \moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
                    $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename(), true);
                $items[] = \html_writer::tag('li', \html_writer::link($url, s($file->get_filename())) .
                    ' (' . display_size($file->get_filesize()) . ')');
            }
            $this->bodyhtml .= \html_writer::tag('p', \html_writer::tag('strong',
                get_string('attachments', 'local_emailstudents'))) . \html_writer::tag('ul', implode('', $items));
            return;
        }

        $this->tempdir = make_temp_directory('local_emailstudents/' . uniqid('m' . ($this->message->id ?? 0) . '_', true));
        if (count($files) === 1) {
            $file = reset($files);
            $this->attachname = clean_filename($file->get_filename());
            $this->attachpath = $this->tempdir . '/' . $this->attachname;
            $file->copy_content_to($this->attachpath);
            return;
        }

        $packer = get_file_packer('application/zip');
        $list = [];
        foreach ($files as $file) {
            $list[clean_filename($file->get_filename())] = $file;
        }
        $this->attachname = clean_filename(get_string('attachmentszip', 'local_emailstudents')) . '.zip';
        $this->attachpath = $this->tempdir . '/' . $this->attachname;
        $packer->archive_to_pathname($list, $this->attachpath);
    }

    /**
     * Send to one user.
     *
     * @param stdClass $user Full user record.
     * @param int|null $courseid Course for course tags; defaults to the course the email was sent from.
     * @param stdClass|null $as Fill the tags with this person's details instead (sender copies).
     * @return bool
     */
    public function send_to(stdClass $user, ?int $courseid = null, ?stdClass $as = null): bool {
        global $DB;
        $courseid = $courseid ?: (int) ($this->message->courseid ?? 0);
        $values = $this->personaliser->values($as ?? $user, $courseid);
        $subject = personaliser::apply($this->message->subject, $values, false);
        $html = personaliser::apply($this->bodyhtml, $values, true);
        if ($this->footer !== '') {
            $html .= '<hr style="border:0;border-top:1px solid #e5e7eb;margin:24px 0 12px;">' .
                '<div style="color:#6b7280;font-size:13px;">' . personaliser::apply($this->footer, $values, true) . '</div>';
        }
        $html = '<div style="font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.5; color: #111827;">' .
            $html . '</div>';
        $text = html_to_text($html);

        $replyto = trim((string) ($this->message->replyto ?? ''));
        $replytoname = trim((string) ($this->message->replytoname ?? ''));
        if ($replyto === '' && ($this->message->fromtype ?? '') !== 'support') {
            // Replies go to the person who wrote the email, never into the no-reply void.
            $sender = $DB->get_record('user', ['id' => $this->message->senderid], '*', IGNORE_MISSING);
            if ($sender && validate_email($sender->email)) {
                $replyto = $sender->email;
                $replytoname = fullname($sender);
            }
        }

        return (bool) email_to_user($user, $this->from, $subject, $text, $html,
            $this->attachpath ?? '', $this->attachname ?? '', true, $replyto, $replytoname);
    }

    /**
     * Remove temporary files.
     */
    public function cleanup(): void {
        if ($this->tempdir && is_dir($this->tempdir)) {
            remove_dir($this->tempdir);
        }
        $this->tempdir = null;
    }

    /**
     * Destructor.
     */
    public function __destruct() {
        $this->cleanup();
    }
}
