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
 * Fills in personalisation tags for each recipient.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents;

use stdClass;

/**
 * Replaces {tags} with each recipient's own details.
 *
 * Only the tags that actually appear in the email are looked up, so plain emails cost
 * nothing extra. Course tags refer to the course the email was sent from, or for a
 * site-wide email the matching course with the recipient's newest enrolment.
 */
class personaliser {
    /** @var string[] Tags used in this email. */
    protected $used = [];

    /** @var stdClass|null Sender. */
    protected $sender;

    /** @var stdClass[] Course cache. */
    protected $courses = [];

    /** @var array Custom profile data cache by user id. */
    protected $profiles = [];

    /**
     * Constructor.
     *
     * @param string $text Everything that may contain tags (subject, body, footer).
     * @param stdClass|null $sender
     */
    public function __construct(string $text, ?stdClass $sender) {
        preg_match_all('/\{([a-z0-9_]+)\}/i', $text, $m);
        $this->used = array_unique($m[1]);
        $this->sender = $sender;
    }

    /**
     * Is this tag used?
     *
     * @param string $tag Without braces.
     * @return bool
     */
    protected function uses(string $tag): bool {
        return in_array($tag, $this->used, true);
    }

    /**
     * Get a course record (cached).
     *
     * @param int $courseid
     * @return stdClass|null
     */
    protected function course(int $courseid): ?stdClass {
        global $DB;
        if (!$courseid || $courseid == SITEID) {
            return null;
        }
        if (!array_key_exists($courseid, $this->courses)) {
            $this->courses[$courseid] = $DB->get_record('course', ['id' => $courseid]) ?: null;
        }
        return $this->courses[$courseid];
    }

    /**
     * Work out tag values for one recipient.
     *
     * @param stdClass $user Full user record.
     * @param int $courseid Course for course tags, 0 if none.
     * @return array tag => plain text value
     */
    public function values(stdClass $user, int $courseid): array {
        global $CFG, $DB, $SITE;
        $course = $this->course($courseid);
        $date = get_string('strftimedate', 'langconfig');
        $v = [
            'firstname' => $user->firstname ?? '',
            'lastname' => $user->lastname ?? '',
            'fullname' => fullname($user),
            'email' => $user->email ?? '',
            'username' => $user->username ?? '',
            'idnumber' => $user->idnumber ?? '',
            'city' => $user->city ?? '',
            'institution' => $user->institution ?? '',
            'department' => $user->department ?? '',
            'sitename' => format_string($SITE->fullname, true,
                ['context' => \context_system::instance(), 'escape' => false]),
            'siteurl' => $CFG->wwwroot,
            'loginurl' => (new \moodle_url('/login/index.php'))->out(false),
            'sendername' => $this->sender ? fullname($this->sender) : '',
            'senderemail' => $this->sender->email ?? '',
            'coursename' => '',
            'courseurl' => $CFG->wwwroot,
            'coursestartdate' => '',
            'courseenddate' => '',
            'enrolstart' => '',
            'enrolend' => '',
            'progress' => '',
            'coursegrade' => '',
            'completiondate' => '',
            'groupname' => '',
        ];

        if ($course) {
            $v['coursename'] = format_string($course->fullname, true, ['escape' => false]);
            $v['courseurl'] = (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
            $v['coursestartdate'] = $course->startdate ? userdate($course->startdate, $date) : '';
            $v['courseenddate'] = $course->enddate ? userdate($course->enddate, $date) : '';

            if ($this->uses('enrolstart') || $this->uses('enrolend')) {
                $ue = $DB->get_record_sql('SELECT MIN(CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) AS ts,
                                                  MAX(ue.timeend) AS te, MIN(ue.timeend) AS temin
                                             FROM {user_enrolments} ue
                                             JOIN {enrol} e ON e.id = ue.enrolid
                                            WHERE ue.userid = ? AND e.courseid = ?', [$user->id, $course->id]);
                $v['enrolstart'] = !empty($ue->ts) ? userdate($ue->ts, $date) : '';
                $v['enrolend'] = (!empty($ue->te) && !empty($ue->temin))
                    ? userdate($ue->te, $date) : get_string('noenrolend', 'local_emailstudents');
            }
            if ($this->uses('progress')) {
                $pct = \core_completion\progress::get_course_progress_percentage($course, $user->id);
                $v['progress'] = $pct === null ? '-' : round($pct) . '%';
            }
            if ($this->uses('coursegrade')) {
                // Course total as a percentage, the same measure the grade filter uses.
                $g = $DB->get_record_sql("SELECT gg.finalgrade, gi.grademin, gi.grademax
                                            FROM {grade_items} gi
                                            JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = ?
                                           WHERE gi.courseid = ? AND gi.itemtype = 'course'", [$user->id, $course->id]);
                $v['coursegrade'] = ($g && $g->finalgrade !== null && $g->grademax > $g->grademin)
                    ? round(($g->finalgrade - $g->grademin) * 100 / ($g->grademax - $g->grademin)) . '%' : '-';
            }
            if ($this->uses('completiondate')) {
                $done = $DB->get_field('course_completions', 'timecompleted',
                    ['userid' => $user->id, 'course' => $course->id]);
                $v['completiondate'] = $done ? userdate($done, $date) : '';
            }
            if ($this->uses('groupname')) {
                $groups = groups_get_all_groups($course->id, $user->id);
                $v['groupname'] = implode(', ', array_map(fn($g) => format_string($g->name), $groups));
            }
        }

        if ($this->uses('lastaccess') || $this->uses('daysinactive')) {
            $last = (int) ($user->lastaccess ?? 0);
            if ($course) {
                $last = (int) $DB->get_field('user_lastaccess', 'timeaccess',
                    ['userid' => $user->id, 'courseid' => $course->id]);
            }
            $v['lastaccess'] = $last ? userdate($last, $date) : get_string('never');
            $v['daysinactive'] = $last ? (string) floor((time() - $last) / DAYSECS) : get_string('never');
        }

        // Custom profile fields: {profile_shortname}.
        $profiletags = array_filter($this->used, fn($t) => strpos($t, 'profile_') === 0);
        if ($profiletags) {
            if (!isset($this->profiles[$user->id])) {
                require_once($CFG->dirroot . '/user/profile/lib.php');
                $this->profiles[$user->id] = (array) profile_user_record($user->id, false);
            }
            foreach ($profiletags as $tag) {
                $short = substr($tag, 8);
                $value = $this->profiles[$user->id][$short] ?? '';
                if (is_array($value)) {
                    $value = $value['text'] ?? '';
                }
                $v[$tag] = trim(html_to_text((string) $value, 0, false));
            }
        }
        return $v;
    }

    /**
     * Replace tags in text.
     *
     * @param string $text
     * @param array $values tag => value
     * @param bool $html Escape values for HTML.
     * @return string
     */
    public static function apply(string $text, array $values, bool $html): string {
        return preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($values, $html) {
            if (!array_key_exists($m[1], $values)) {
                return $m[0];
            }
            return $html ? s($values[$m[1]]) : $values[$m[1]];
        }, $text);
    }
}
