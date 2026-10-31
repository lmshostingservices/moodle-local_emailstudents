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
 * Builds the recipient list for a student email from a set of filters.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents;

use core_course_category;

/**
 * Recipient filter logic.
 *
 * Filters combine with AND between types and OR within a type. For example, choosing two
 * courses and one cohort selects users enrolled in either course who are also in the cohort.
 */
class recipients {
    /** Users enrolled in matching courses with a matching role. */
    const AUDIENCE_ENROLLED = 'enrolled';

    /** Every active user on the site (cohort and last-access filters still apply). */
    const AUDIENCE_ALL = 'all';

    /** Last access filter: users who have never logged in. */
    const LASTACCESS_NEVER = -1;

    /**
     * Turn submitted form data into a clean, restricted filter array.
     *
     * When a course id is given (a teacher sending from a course) the course is forced,
     * category filters are dropped and groups are limited to what the sender can see.
     *
     * @param \stdClass|array $data Submitted form data.
     * @param int $courseid Course the sender is restricted to, or 0 for site level.
     * @param int|null $userid Sender, defaults to current user.
     * @return array
     */
    public static function normalise($data, int $courseid = 0, ?int $userid = null): array {
        global $USER;
        $data = (object) $data;
        $userid = $userid ?? (int) $USER->id;

        $ints = function ($value): array {
            if ($value === null || $value === '' || $value === false) {
                return [];
            }
            $value = is_array($value) ? $value : [$value];
            $value = array_map('intval', $value);
            return array_values(array_unique(array_filter($value, fn($v) => $v > 0)));
        };

        $filters = [
            'audience' => ($data->audience ?? self::AUDIENCE_ENROLLED) === self::AUDIENCE_ALL
                ? self::AUDIENCE_ALL : self::AUDIENCE_ENROLLED,
            'categories' => $ints($data->categories ?? []),
            'subcats' => !empty($data->subcats),
            'courses' => $ints($data->courses ?? []),
            'groups' => $ints($data->groups ?? []),
            'cohorts' => $ints($data->cohorts ?? []),
            'roles' => $ints($data->roles ?? []),
            'lastaccess' => (int) ($data->lastaccess ?? 0),
            'completion' => in_array($data->completion ?? '', ['complete', 'incomplete']) ? $data->completion : '',
            'completedfrom' => self::date_value($data->completedfrom ?? 0),
            'completedto' => self::date_value($data->completedto ?? 0),
            'progressop' => in_array($data->progressop ?? '', ['below', 'atleast']) ? $data->progressop : '',
            'progress' => max(0, min(100, (int) ($data->progress ?? 0))),
            'gradeop' => in_array($data->gradeop ?? '', ['below', 'atleast']) ? $data->gradeop : '',
            'grade' => max(0, min(100, (float) ($data->grade ?? 0))),
            'courseinactive' => max(0, (int) ($data->courseinactive ?? 0)),
            'enrolwindow' => preg_match('/^(start|end)(\d{1,3})$/', (string) ($data->enrolwindow ?? ''))
                ? (string) $data->enrolwindow : '',
            'assignments' => $ints($data->assignments ?? []),
            'enrolmethods' => array_values(array_filter(array_map(fn($v) => clean_param($v, PARAM_PLUGIN),
                (array) ($data->enrolmethods ?? [])))),
            'profilefield' => preg_match('/^(core:(city|institution|department|idnumber|country)|custom:\d+)$/',
                (string) ($data->profilefield ?? '')) ? (string) $data->profilefield : '',
            'profileop' => ($data->profileop ?? '') === 'contains' ? 'contains' : 'is',
            'profilevalue' => trim((string) ($data->profilevalue ?? '')),
        ];
        if ($filters['completion'] !== 'complete') {
            $filters['completedfrom'] = $filters['completedto'] = 0;
        }
        if ($filters['profilefield'] === '' || $filters['profilevalue'] === '') {
            $filters['profilefield'] = '';
            $filters['profilevalue'] = '';
        }
        if (!$filters['progressop']) {
            $filters['progress'] = 0;
        }
        if (!$filters['gradeop']) {
            $filters['grade'] = 0;
        }

        // Only allow roles the administrator has made available.
        $allowed = array_keys(helper::get_allowed_roles());
        $filters['roles'] = array_values(array_intersect($filters['roles'], $allowed));
        if (empty($filters['roles'])) {
            $filters['roles'] = helper::get_default_role_ids();
        }

        if ($courseid) {
            $filters['audience'] = self::AUDIENCE_ENROLLED;
            $filters['categories'] = [];
            $filters['subcats'] = false;
            $filters['courses'] = [$courseid];
            $filters['groups'] = self::restrict_groups_to_course($courseid, $filters['groups'], $userid);
        }

        if ($filters['audience'] === self::AUDIENCE_ALL) {
            $filters['categories'] = [];
            $filters['courses'] = [];
            $filters['groups'] = [];
            $filters['roles'] = [];
            foreach (self::COURSE_ONLY as $key => $empty) {
                $filters[$key] = $empty;
            }
        }

        return $filters;
    }

    /** Filters that only make sense when emailing course participants, with their "off" value. */
    const COURSE_ONLY = [
        'completion' => '', 'completedfrom' => 0, 'completedto' => 0, 'progressop' => '', 'progress' => 0,
        'gradeop' => '', 'grade' => 0, 'courseinactive' => 0, 'enrolwindow' => '', 'assignments' => [],
        'enrolmethods' => [],
    ];

    /**
     * Read a date selector value (timestamp or day/month/year array).
     *
     * @param mixed $value
     * @return int
     */
    protected static function date_value($value): int {
        if (is_array($value)) {
            if (empty($value['enabled']) || empty($value['year'])) {
                return 0;
            }
            return make_timestamp((int) $value['year'], (int) $value['month'], (int) $value['day']);
        }
        return max(0, (int) $value);
    }

    /**
     * Limit chosen groups to ones in the course the sender is allowed to see.
     *
     * @param int $courseid
     * @param int[] $groups Requested group ids.
     * @param int $userid Sender.
     * @return int[] Group ids; [-1] means "nobody" (sender has no visible groups).
     */
    public static function restrict_groups_to_course(int $courseid, array $groups, int $userid): array {
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        $restricted = groups_get_course_groupmode($course) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $context, $userid);

        $available = $restricted
            ? groups_get_all_groups($courseid, $userid)
            : groups_get_all_groups($courseid);
        $availableids = array_map('intval', array_keys($available));

        if ($groups) {
            $groups = array_values(array_intersect($groups, $availableids));
            return $groups ?: [-1];
        }
        if ($restricted) {
            // Teacher in separate groups mode without a pick: only their own groups.
            return $availableids ?: [-1];
        }
        return [];
    }

    /**
     * Expand chosen categories to include their subcategories when requested.
     *
     * @param int[] $categoryids
     * @param bool $subcats
     * @return int[]
     */
    protected static function expand_categories(array $categoryids, bool $subcats): array {
        if (!$subcats) {
            return $categoryids;
        }
        $all = $categoryids;
        foreach ($categoryids as $id) {
            $category = core_course_category::get($id, IGNORE_MISSING, true);
            if ($category) {
                $all = array_merge($all, $category->get_all_children_ids());
            }
        }
        return array_values(array_unique(array_map('intval', $all)));
    }

    /**
     * Build the enrolment sub-query (joins + conditions) for one recipient, correlated on u.id.
     *
     * The same conditions are used to decide whether someone is a recipient and which course
     * their personalised course placeholders refer to, so the SQL is built twice with
     * different parameter names.
     *
     * @param array $filters
     * @param string $sfx Parameter name suffix.
     * @return array [string $sql, array $params] beginning "FROM {user_enrolments} ue ..."
     */
    protected static function enrol_sub(array $filters, string $sfx): array {
        global $DB;
        $now = time();
        $p = [
            "enrolenabled$sfx" => ENROL_INSTANCE_ENABLED,
            "ueactive$sfx" => ENROL_USER_ACTIVE,
            "ctxcourse$sfx" => CONTEXT_COURSE,
            "now1$sfx" => $now,
            "now2$sfx" => $now,
            "siteid$sfx" => SITEID,
        ];
        $sql = "FROM {user_enrolments} ue
                JOIN {enrol} e ON e.id = ue.enrolid AND e.status = :enrolenabled$sfx
                JOIN {course} c ON c.id = e.courseid
                JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = :ctxcourse$sfx
                JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = ue.userid
               WHERE ue.userid = u.id
                 AND ue.status = :ueactive$sfx
                 AND ue.timestart <= :now1$sfx
                 AND (ue.timeend = 0 OR ue.timeend > :now2$sfx)
                 AND c.id <> :siteid$sfx";

        $in = function (array $values, string $prefix) use ($DB, $sfx, &$p): string {
            [$insql, $inparams] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, $prefix . $sfx . '_');
            $p += $inparams;
            return $insql;
        };

        if ($filters['roles']) {
            $sql .= ' AND ra.roleid ' . $in($filters['roles'], 'role');
        }
        if ($filters['courses']) {
            $sql .= ' AND c.id ' . $in($filters['courses'], 'course');
        }
        $categories = self::expand_categories($filters['categories'], $filters['subcats']);
        if ($categories) {
            $sql .= ' AND c.category ' . $in($categories, 'cat');
        }
        if ($filters['groups']) {
            $sql .= ' AND EXISTS (SELECT 1 FROM {groups_members} gm JOIN {groups} g ON g.id = gm.groupid
                                   WHERE gm.userid = u.id AND g.courseid = c.id AND gm.groupid ' .
                $in($filters['groups'], 'grp') . ')';
        }
        if (!empty($filters['enrolmethods'])) {
            $sql .= ' AND e.enrol ' . $in($filters['enrolmethods'], 'em');
        }

        // Course completion.
        if (($filters['completion'] ?? '') === 'complete') {
            $cc = "SELECT 1 FROM {course_completions} cc
                    WHERE cc.userid = u.id AND cc.course = c.id AND cc.timecompleted > 0";
            if (!empty($filters['completedfrom'])) {
                $cc .= " AND cc.timecompleted >= :ccfrom$sfx";
                $p["ccfrom$sfx"] = $filters['completedfrom'];
            }
            if (!empty($filters['completedto'])) {
                $cc .= " AND cc.timecompleted < :ccto$sfx";
                $p["ccto$sfx"] = $filters['completedto'] + DAYSECS;
            }
            $sql .= " AND EXISTS ($cc)";
        } else if (($filters['completion'] ?? '') === 'incomplete') {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM {course_completions} cc
                                       WHERE cc.userid = u.id AND cc.course = c.id AND cc.timecompleted > 0)";
        }

        // Activity completion progress, as a percentage of tracked activities.
        if (!empty($filters['progressop'])) {
            $done = "(SELECT COUNT(1) FROM {course_modules_completion} cmc
                        JOIN {course_modules} cmp ON cmp.id = cmc.coursemoduleid
                       WHERE cmp.course = c.id AND cmp.completion > 0 AND cmp.deletioninprogress = 0
                         AND cmc.userid = u.id AND cmc.completionstate IN (1, 2))";
            $total = "(SELECT COUNT(1) FROM {course_modules} cmt
                        WHERE cmt.course = c.id AND cmt.completion > 0 AND cmt.deletioninprogress = 0)";
            $op = $filters['progressop'] === 'below' ? '<' : '>=';
            $sql .= " AND $total > 0 AND ($done * 100) $op (:prog$sfx * $total)";
            $p["prog$sfx"] = (int) $filters['progress'];
        }

        // Course total grade, as a percentage.
        if (!empty($filters['gradeop'])) {
            $op = $filters['gradeop'] === 'below' ? '<' : '>=';
            $sql .= " AND EXISTS (SELECT 1 FROM {grade_items} gi
                                    JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = u.id
                                   WHERE gi.courseid = c.id AND gi.itemtype = 'course'
                                     AND gg.finalgrade IS NOT NULL AND gi.grademax > gi.grademin
                                     AND ((gg.finalgrade - gi.grademin) * 100) $op (:grade$sfx * (gi.grademax - gi.grademin)))";
            $p["grade$sfx"] = (float) $filters['grade'];
        }

        // Not seen in this course for N days.
        if (!empty($filters['courseinactive'])) {
            $sql .= " AND NOT EXISTS (SELECT 1 FROM {user_lastaccess} ula
                                       WHERE ula.userid = u.id AND ula.courseid = c.id AND ula.timeaccess >= :ula$sfx)";
            $p["ula$sfx"] = time() - $filters['courseinactive'] * DAYSECS;
        }

        // Enrolment window: new starters or enrolments about to end.
        if (!empty($filters['enrolwindow']) && preg_match('/^(start|end)(\d+)$/', $filters['enrolwindow'], $m)) {
            $days = (int) $m[2] * DAYSECS;
            if ($m[1] === 'start') {
                $sql .= " AND (CASE WHEN ue.timestart > 0 THEN ue.timestart ELSE ue.timecreated END) >= :ews$sfx";
                $p["ews$sfx"] = time() - $days;
            } else {
                $sql .= " AND ue.timeend > 0 AND ue.timeend <= :ewe$sfx";
                $p["ewe$sfx"] = time() + $days;
            }
        }

        // Has not submitted one of the chosen assignments in this course.
        if (!empty($filters['assignments'])) {
            $sql .= " AND EXISTS (SELECT 1 FROM {assign} asg
                                   WHERE asg.course = c.id AND asg.id " . $in($filters['assignments'], 'asg') . "
                                     AND NOT EXISTS (SELECT 1 FROM {assign_submission} asub
                                                      WHERE asub.assignment = asg.id AND asub.userid = u.id
                                                        AND asub.latest = 1 AND asub.status = :submitted$sfx))";
            $p["submitted$sfx"] = 'submitted';
        }

        return [$sql, $p];
    }

    /**
     * Build the FROM/WHERE part of the recipient query.
     *
     * @param array $filters Normalised filters.
     * @return array [string $sql, array $params] where $sql starts with "FROM {user} u".
     */
    public static function get_sql(array $filters): array {
        global $CFG, $DB;
        $filters += self::COURSE_ONLY + ['profilefield' => '', 'profileop' => 'is', 'profilevalue' => ''];

        $params = [
            'guestid' => (int) $CFG->siteguest,
            'emptyemail' => '',
            'nologin' => 'nologin',
        ];
        $where = [
            'u.deleted = 0',
            'u.suspended = 0',
            'u.confirmed = 1',
            'u.id <> :guestid',
            'u.email <> :emptyemail',
            'u.auth <> :nologin',
        ];

        if ($filters['audience'] === self::AUDIENCE_ENROLLED) {
            [$sub, $subparams] = self::enrol_sub($filters, 'a');
            $where[] = "EXISTS (SELECT 1 $sub)";
            $params += $subparams;
        }

        if ($filters['cohorts']) {
            [$insql, $inparams] = $DB->get_in_or_equal($filters['cohorts'], SQL_PARAMS_NAMED, 'coh');
            $where[] = "EXISTS (SELECT 1 FROM {cohort_members} cm WHERE cm.userid = u.id AND cm.cohortid $insql)";
            $params += $inparams;
        }

        if ($filters['lastaccess'] == self::LASTACCESS_NEVER) {
            $where[] = 'u.lastaccess = 0';
        } else if ($filters['lastaccess'] > 0) {
            $where[] = 'u.lastaccess < :lastaccesscutoff';
            $params['lastaccesscutoff'] = time() - ($filters['lastaccess'] * DAYSECS);
        }

        if ($filters['profilefield'] !== '' && $filters['profilevalue'] !== '') {
            $value = $filters['profileop'] === 'contains'
                ? '%' . $DB->sql_like_escape($filters['profilevalue']) . '%'
                : $DB->sql_like_escape($filters['profilevalue']);
            [$type, $field] = explode(':', $filters['profilefield']);
            if ($type === 'core') {
                $where[] = $DB->sql_like("u.$field", ':pfvalue', false);
            } else {
                $where[] = 'EXISTS (SELECT 1 FROM {user_info_data} uid WHERE uid.userid = u.id AND uid.fieldid = :pfid AND ' .
                    $DB->sql_like('uid.data', ':pfvalue', false) . ')';
                $params['pfid'] = (int) $field;
            }
            $params['pfvalue'] = $value;
        }

        return ['FROM {user} u WHERE ' . implode(' AND ', $where), $params];
    }

    /**
     * Count matching recipients.
     *
     * @param array $filters Normalised filters.
     * @return int
     */
    public static function count(array $filters): int {
        global $DB;
        [$sql, $params] = self::get_sql($filters);
        return (int) $DB->count_records_sql("SELECT COUNT(u.id) $sql", $params);
    }

    /**
     * Get a sample of matching recipients for previewing.
     *
     * @param array $filters Normalised filters.
     * @param int $limit
     * @return \stdClass[]
     */
    public static function sample(array $filters, int $limit = 50): array {
        global $DB;
        [$sql, $params] = self::get_sql($filters);
        $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
        return $DB->get_records_sql("SELECT u.id, u.email, $fields $sql ORDER BY u.lastname, u.firstname, u.id",
            $params, 0, $limit);
    }

    /**
     * Get every matching recipient id as a recordset.
     *
     * @param array $filters Normalised filters.
     * @return \moodle_recordset
     */
    public static function recordset(array $filters): \moodle_recordset {
        global $DB;
        $filters += self::COURSE_ONLY;
        [$sql, $params] = self::get_sql($filters);
        $ctx = '0';
        if ($filters['audience'] === self::AUDIENCE_ENROLLED) {
            // The course their personalised course details come from: the matching course with the
            // newest enrolment (encoded as enrolment time * 1,000,000 + course id).
            [$sub, $subparams] = self::enrol_sub($filters, 'b');
            $ctx = "(SELECT MAX(ue.timecreated * 1000000 + c.id) $sub)";
            $params += $subparams;
        }
        return $DB->get_recordset_sql("SELECT u.id, $ctx AS ctxkey $sql ORDER BY u.id", $params);
    }

    /**
     * Human readable summary of filters, for the history page.
     *
     * @param array $filters
     * @return string[] Label => value pairs.
     */
    public static function describe(array $filters): array {
        global $DB;
        $out = [];
        $names = function (string $table, array $ids, string $field) use ($DB): string {
            if (!$ids) {
                return '';
            }
            $ids = array_filter($ids, fn($id) => $id > 0);
            if (!$ids) {
                return get_string('none');
            }
            $records = $DB->get_records_list($table, 'id', $ids, '', "id, $field");
            return implode(', ', array_map(fn($r) => format_string($r->$field), $records));
        };

        $out[get_string('audience', 'local_emailstudents')] = get_string('audience_' . $filters['audience'], 'local_emailstudents');
        if (!empty($filters['categories'])) {
            $value = $names('course_categories', $filters['categories'], 'name');
            if (!empty($filters['subcats'])) {
                $value .= ' (' . get_string('includesubcats', 'local_emailstudents') . ')';
            }
            $out[get_string('categories')] = $value;
        }
        if (!empty($filters['courses'])) {
            $out[get_string('courses')] = $names('course', $filters['courses'], 'fullname');
        }
        if (!empty($filters['groups'])) {
            $out[get_string('groups')] = $names('groups', $filters['groups'], 'name');
        }
        if (!empty($filters['cohorts'])) {
            $out[get_string('cohorts', 'cohort')] = $names('cohort', $filters['cohorts'], 'name');
        }
        if (!empty($filters['roles'])) {
            $roles = role_fix_names($DB->get_records_list('role', 'id', $filters['roles']));
            $out[get_string('roles')] = implode(', ', array_map(fn($r) => $r->localname, $roles));
        }
        if (!empty($filters['lastaccess'])) {
            $options = helper::lastaccess_options();
            $out[get_string('lastaccess')] = $options[$filters['lastaccess']] ?? '';
        }
        $str = fn($k, $a = null) => get_string($k, 'local_emailstudents', $a);
        if (!empty($filters['completion'])) {
            $value = $str('completion_' . $filters['completion']);
            if (!empty($filters['completedfrom'])) {
                $value .= ' · ' . $str('from_date', userdate($filters['completedfrom'], get_string('strftimedate', 'langconfig')));
            }
            if (!empty($filters['completedto'])) {
                $value .= ' · ' . $str('to_date', userdate($filters['completedto'], get_string('strftimedate', 'langconfig')));
            }
            $out[$str('completionfilter')] = $value;
        }
        if (!empty($filters['progressop'])) {
            $out[$str('progressfilter')] = $str('op_' . $filters['progressop']) . ' ' . $filters['progress'] . '%';
        }
        if (!empty($filters['gradeop'])) {
            $out[$str('gradefilter')] = $str('op_' . $filters['gradeop']) . ' ' . format_float($filters['grade'], -1) . '%';
        }
        if (!empty($filters['courseinactive'])) {
            $out[$str('courseinactivefilter')] = $str('courseinactive_days', $filters['courseinactive']);
        }
        if (!empty($filters['enrolwindow'])) {
            $options = helper::enrolwindow_options();
            $out[$str('enrolwindowfilter')] = $options[$filters['enrolwindow']] ?? '';
        }
        if (!empty($filters['assignments'])) {
            $out[$str('assignmentsfilter')] = $names('assign', $filters['assignments'], 'name');
        }
        if (!empty($filters['enrolmethods'])) {
            $out[$str('enrolmethodsfilter')] = implode(', ', array_map(
                fn($m) => get_string('pluginname', 'enrol_' . $m), $filters['enrolmethods']));
        }
        if (!empty($filters['profilefield'])) {
            $fields = helper::profile_field_options();
            $out[$fields[$filters['profilefield']] ?? $str('profilefilter')] =
                $str('op_' . $filters['profileop']) . ' "' . s($filters['profilevalue']) . '"';
        }
        return $out;
    }
}
