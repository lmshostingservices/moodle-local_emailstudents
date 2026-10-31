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
 * Saved email templates.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents;

use context_system;
use stdClass;

/**
 * Template storage, starters and access.
 */
class templates {
    /** Starter templates shipped with the plugin. */
    const STARTERS = ['welcome', 'duesoon', 'overdue', 'inactive', 'progress', 'expiring', 'results', 'completed',
        'change', 'survey', 'usi', 'urgent'];

    /**
     * Templates a user may use, grouped for a select.
     *
     * @param int $userid
     * @return array group => [id => name]
     */
    public static function options(int $userid): array {
        global $DB;
        $records = $DB->get_records_select('local_emailstudents_tpl', 'starter = 1 OR shared = 1 OR userid = ?', [$userid],
            'starter DESC, name', 'id, name, starter, shared, userid');
        $groups = [];
        foreach ($records as $r) {
            if ($r->starter) {
                $group = get_string('tplgroup_starter', 'local_emailstudents');
            } else if ($r->userid == $userid) {
                $group = get_string('tplgroup_mine', 'local_emailstudents');
            } else {
                $group = get_string('tplgroup_shared', 'local_emailstudents');
            }
            $groups[$group][$r->id] = format_string($r->name);
        }
        return $groups;
    }

    /**
     * Subject and body of every template a user may use, for the compose page script.
     *
     * @param int $userid
     * @return array id => [subject, body]
     */
    public static function payload(int $userid): array {
        global $DB;
        $syscontext = context_system::instance();
        $out = [];
        foreach ($DB->get_records_select('local_emailstudents_tpl', 'starter = 1 OR shared = 1 OR userid = ?', [$userid]) as $r) {
            $body = file_rewrite_pluginfile_urls($r->body, 'pluginfile.php', $syscontext->id, 'local_emailstudents',
                'template', $r->id);
            $out[$r->id] = ['subject' => $r->subject, 'body' => $body];
        }
        return $out;
    }

    /**
     * Can the user change or delete this template?
     *
     * @param stdClass $template
     * @param int $userid
     * @return bool
     */
    public static function can_manage(stdClass $template, int $userid): bool {
        if ($template->userid == $userid && !$template->starter) {
            return true;
        }
        return has_capability('local/emailstudents:sendsite', context_system::instance(), $userid);
    }

    /**
     * Save a new template from compose form data.
     *
     * @param int $userid
     * @param string $name
     * @param string $subject
     * @param array $editor The body_editor value (text, format, itemid).
     * @param bool $shared
     * @return int New id.
     */
    public static function save(int $userid, string $name, string $subject, array $editor, bool $shared): int {
        global $DB;
        $now = time();
        $record = (object) [
            'userid' => $userid,
            'shared' => $shared ? 1 : 0,
            'starter' => 0,
            'name' => \core_text::substr(trim($name) ?: trim($subject) ?: get_string('untitled', 'local_emailstudents'), 0, 255),
            'subject' => \core_text::substr($subject, 0, 255),
            'body' => '',
            'bodyformat' => (int) ($editor['format'] ?? FORMAT_HTML),
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('local_emailstudents_tpl', $record);
        $context = context_system::instance();
        $record->body = file_save_draft_area_files((int) ($editor['itemid'] ?? 0), $context->id, 'local_emailstudents',
            'template', $record->id, ['subdirs' => false, 'maxfiles' => 50], $editor['text'] ?? '');
        $DB->set_field('local_emailstudents_tpl', 'body', $record->body, ['id' => $record->id]);
        return $record->id;
    }

    /**
     * Delete a template and its images.
     *
     * @param int $id
     */
    public static function delete(int $id): void {
        global $DB;
        get_file_storage()->delete_area_files(context_system::instance()->id, 'local_emailstudents', 'template', $id);
        $DB->delete_records('local_emailstudents_tpl', ['id' => $id]);
    }

    /**
     * Add the starter templates (install and upgrade).
     */
    public static function install_starters(): void {
        global $DB;
        $now = time();
        foreach (self::STARTERS as $key) {
            $name = get_string('tpl_' . $key . '_name', 'local_emailstudents');
            if ($DB->record_exists('local_emailstudents_tpl', ['starter' => 1, 'name' => $name])) {
                continue;
            }
            $DB->insert_record('local_emailstudents_tpl', (object) [
                'userid' => 0,
                'shared' => 1,
                'starter' => 1,
                'name' => $name,
                'subject' => get_string('tpl_' . $key . '_subject', 'local_emailstudents'),
                'body' => get_string('tpl_' . $key . '_body', 'local_emailstudents'),
                'bodyformat' => FORMAT_HTML,
                'timecreated' => $now,
                'timemodified' => $now,
            ]);
        }
    }
}
