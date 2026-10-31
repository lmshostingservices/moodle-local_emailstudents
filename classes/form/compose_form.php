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
 * Compose form for local_emailstudents.
 *
 * @package    local_emailstudents
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_emailstudents\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use local_emailstudents\helper;
use local_emailstudents\recipients;

/**
 * The compose form: recipients, message and delivery.
 */
class compose_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition() {
        global $DB;
        $mform = $this->_form;
        $courseid = (int) $this->_customdata['courseid'];
        /** @var \context $context */
        $context = $this->_customdata['context'];
        $sitelevel = !$courseid;

        $mform->addElement('hidden', 'courseid', $courseid);
        $mform->setType('courseid', PARAM_INT);

        // Recipients.
        $mform->addElement('header', 'hdrrecipients', get_string('step_recipients', 'local_emailstudents'));
        $mform->setExpanded('hdrrecipients', true);

        if ($sitelevel) {
            $mform->addElement('select', 'audience', get_string('audience', 'local_emailstudents'), [
                recipients::AUDIENCE_ENROLLED => get_string('audience_enrolled', 'local_emailstudents'),
                recipients::AUDIENCE_ALL => get_string('audience_all', 'local_emailstudents'),
            ]);
            $mform->addHelpButton('audience', 'audience', 'local_emailstudents');

            $categories = \core_course_category::make_categories_list();
            $mform->addElement('autocomplete', 'categories', get_string('categories'), $categories, [
                'multiple' => true,
                'noselectionstring' => get_string('anycategory', 'local_emailstudents'),
                'placeholder' => get_string('searchcategories', 'local_emailstudents'),
            ]);
            $mform->hideIf('categories', 'audience', 'eq', recipients::AUDIENCE_ALL);

            $mform->addElement('advcheckbox', 'subcats', '', get_string('includesubcats', 'local_emailstudents'));
            $mform->setDefault('subcats', 1);
            $mform->hideIf('subcats', 'audience', 'eq', recipients::AUDIENCE_ALL);

            $mform->addElement('course', 'courses', get_string('courses'), [
                'multiple' => true,
                'includefrontpage' => false,
                'noselectionstring' => get_string('anycourse', 'local_emailstudents'),
                'placeholder' => get_string('searchcourses', 'local_emailstudents'),
            ]);
            $mform->hideIf('courses', 'audience', 'eq', recipients::AUDIENCE_ALL);
        } else {
            $mform->addElement('hidden', 'audience', recipients::AUDIENCE_ENROLLED);
            $mform->setType('audience', PARAM_ALPHA);
        }

        // Groups (ajax search; selected ones need labels for redisplay).
        $selectedgroups = $this->_customdata['selectedgroups'] ?? [];
        $groupoptions = [];
        if ($courseid) {
            $allowed = recipients::restrict_groups_to_course($courseid, [], (int) $this->_customdata['userid']);
            $groups = groups_get_all_groups($courseid);
            if ($allowed) {
                $groups = array_intersect_key($groups, array_flip($allowed));
            }
            foreach ($groups as $g) {
                $groupoptions[$g->id] = format_string($g->name, true, ['context' => $context]);
            }
        } else if ($selectedgroups) {
            $records = $DB->get_records_sql('SELECT g.id, g.name, c.shortname
                                               FROM {groups} g JOIN {course} c ON c.id = g.courseid
                                              WHERE g.id IN (' . implode(',', array_map('intval', $selectedgroups)) . ')');
            foreach ($records as $r) {
                $groupoptions[$r->id] = format_string($r->shortname) . ': ' . format_string($r->name);
            }
        }
        $groupattrs = [
            'multiple' => true,
            'noselectionstring' => get_string('anygroup', 'local_emailstudents'),
            'placeholder' => get_string('searchgroups', 'local_emailstudents'),
        ];
        if (!$courseid) {
            $groupattrs['ajax'] = 'local_emailstudents/group_selector';
        }
        $mform->addElement('autocomplete', 'groups', get_string('groups'), $groupoptions, $groupattrs);
        $mform->getElement('groups')->updateAttributes(['data-courseid' => $courseid]);
        if ($sitelevel) {
            $mform->hideIf('groups', 'audience', 'eq', recipients::AUDIENCE_ALL);
        }
        if ($courseid && !$groupoptions) {
            $mform->removeElement('groups');
        }

        $roles = helper::get_allowed_roles();
        $mform->addElement('autocomplete', 'roles', get_string('recipientroles', 'local_emailstudents'), $roles, [
            'multiple' => true,
            'noselectionstring' => get_string('choose'),
        ]);
        $mform->setDefault('roles', helper::get_default_role_ids());
        $mform->addHelpButton('roles', 'recipientroles', 'local_emailstudents');
        if ($sitelevel) {
            $mform->hideIf('roles', 'audience', 'eq', recipients::AUDIENCE_ALL);
        }

        $mform->addElement('cohort', 'cohorts', get_string('cohorts', 'cohort'), [
            'multiple' => true,
            'contextid' => $context->id,
            'includes' => 'all',
            'noselectionstring' => get_string('anycohort', 'local_emailstudents'),
        ]);

        $mform->addElement('select', 'lastaccess', get_string('lastaccessfilter', 'local_emailstudents'),
            helper::lastaccess_options());
        $mform->addHelpButton('lastaccess', 'lastaccessfilter', 'local_emailstudents');

        // Refine: progress, completion, grades, activity and profile.
        $mform->addElement('header', 'hdrrefine', get_string('step_refine', 'local_emailstudents'));
        $mform->setExpanded('hdrrefine', true);
        $str = fn($k, $a = null) => get_string($k, 'local_emailstudents', $a);

        $mform->addElement('select', 'completion', $str('completionfilter'), [
            '' => $str('completion_any'),
            'incomplete' => $str('completion_incomplete'),
            'complete' => $str('completion_complete'),
        ]);
        $mform->addHelpButton('completion', 'completionfilter', 'local_emailstudents');
        $mform->addElement('date_selector', 'completedfrom', $str('completedfrom'), ['optional' => true]);
        $mform->addElement('date_selector', 'completedto', $str('completedto'), ['optional' => true]);
        $mform->hideIf('completedfrom', 'completion', 'neq', 'complete');
        $mform->hideIf('completedto', 'completion', 'neq', 'complete');

        $ops = ['' => $str('op_any'), 'below' => $str('op_below'), 'atleast' => $str('op_atleast')];
        $progress = [
            $mform->createElement('select', 'progressop', '', $ops),
            $mform->createElement('text', 'progress', '', ['size' => 4, 'inputmode' => 'numeric', 'placeholder' => '50']),
            $mform->createElement('static', 'progresspct', '', '%'),
        ];
        $mform->addGroup($progress, 'progressgroup', $str('progressfilter'), '', false);
        $mform->setType('progress', PARAM_INT);
        $mform->hideIf('progress', 'progressop', 'eq', '');
        $mform->addHelpButton('progressgroup', 'progressfilter', 'local_emailstudents');

        $grade = [
            $mform->createElement('select', 'gradeop', '', $ops),
            $mform->createElement('text', 'grade', '', ['size' => 4, 'inputmode' => 'decimal', 'placeholder' => '50']),
            $mform->createElement('static', 'gradepct', '', '%'),
        ];
        $mform->addGroup($grade, 'gradegroup', $str('gradefilter'), '', false);
        $mform->setType('grade', PARAM_FLOAT);
        $mform->hideIf('grade', 'gradeop', 'eq', '');
        $mform->addHelpButton('gradegroup', 'gradefilter', 'local_emailstudents');

        $mform->addElement('select', 'courseinactive', $str('courseinactivefilter'), helper::courseinactive_options());
        $mform->addHelpButton('courseinactive', 'courseinactivefilter', 'local_emailstudents');

        $mform->addElement('select', 'enrolwindow', $str('enrolwindowfilter'), helper::enrolwindow_options());

        // Assignments not submitted (ajax search; selected ones need labels for redisplay).
        $assignoptions = [];
        $selectedassign = $this->_customdata['selectedassignments'] ?? [];
        if ($courseid) {
            foreach ($DB->get_records('assign', ['course' => $courseid], 'name', 'id, name') as $a) {
                $assignoptions[$a->id] = format_string($a->name, true, ['context' => $context]);
            }
        } else if ($selectedassign) {
            [$insql, $inparams] = $DB->get_in_or_equal($selectedassign);
            foreach ($DB->get_records_sql("SELECT a.id, a.name, c.shortname FROM {assign} a JOIN {course} c ON c.id = a.course
                                            WHERE a.id $insql", $inparams) as $a) {
                $assignoptions[$a->id] = format_string($a->shortname) . ': ' . format_string($a->name);
            }
        }
        $assignattrs = [
            'multiple' => true,
            'noselectionstring' => $str('anyassignment'),
            'placeholder' => $str('searchassignments'),
        ];
        if (!$courseid) {
            $assignattrs['ajax'] = 'local_emailstudents/assign_selector';
        }
        $mform->addElement('autocomplete', 'assignments', $str('assignmentsfilter'), $assignoptions, $assignattrs);
        $mform->addHelpButton('assignments', 'assignmentsfilter', 'local_emailstudents');
        if ($courseid && !$assignoptions) {
            $mform->removeElement('assignments');
        }

        $mform->addElement('autocomplete', 'enrolmethods', $str('enrolmethodsfilter'), helper::enrol_method_options(), [
            'multiple' => true,
            'noselectionstring' => $str('anyenrolmethod'),
        ]);

        $profile = [
            $mform->createElement('select', 'profilefield', '', helper::profile_field_options()),
            $mform->createElement('select', 'profileop', '', ['is' => $str('op_is'), 'contains' => $str('op_contains')]),
            $mform->createElement('text', 'profilevalue', '', ['size' => 18, 'placeholder' => $str('profilevalue')]),
        ];
        $mform->addGroup($profile, 'profilegroup', $str('profilefilter'), '', false);
        $mform->setType('profilevalue', PARAM_TEXT);
        $mform->hideIf('profileop', 'profilefield', 'eq', '');
        $mform->hideIf('profilevalue', 'profilefield', 'eq', '');
        $mform->addHelpButton('profilegroup', 'profilefilter', 'local_emailstudents');

        if ($sitelevel) {
            foreach (['completion', 'completedfrom', 'completedto', 'progressgroup', 'gradegroup', 'courseinactive',
                    'enrolwindow', 'assignments', 'enrolmethods'] as $el) {
                if ($mform->elementExists($el)) {
                    $mform->hideIf($el, 'audience', 'eq', recipients::AUDIENCE_ALL);
                }
            }
        }

        // Message.
        $mform->addElement('header', 'hdrmessage', get_string('step_message', 'local_emailstudents'));
        $mform->setExpanded('hdrmessage', true);

        // Templates.
        $templates = $this->_customdata['templates'] ?? [];
        if ($templates) {
            // A plain select: Moodle's selectgroups drops its "Choose..." option in the Boost template.
            $options = ['' => get_string('starttemplate_none', 'local_emailstudents')];
            foreach ($templates as $group => $items) {
                foreach ($items as $id => $name) {
                    $options[$id] = $group . ' · ' . $name;
                }
            }
            $mform->addElement('select', 'templateid', get_string('starttemplate', 'local_emailstudents'), $options,
                ['data-lbe-template' => 1]);
            $mform->addHelpButton('templateid', 'starttemplate', 'local_emailstudents');
        }

        if (!empty($this->_customdata['canchoosefrom'])) {
            $mform->addElement('select', 'fromtype', get_string('fromtype', 'local_emailstudents'), helper::from_options());
            $mform->setDefault('fromtype', get_config('local_emailstudents', 'defaultfrom') ?: 'noreply');
            $mform->addHelpButton('fromtype', 'fromtype', 'local_emailstudents');
        } else {
            $mform->addElement('hidden', 'fromtype', get_config('local_emailstudents', 'defaultfrom') ?: 'noreply');
            $mform->setType('fromtype', PARAM_ALPHA);
        }

        $replygroup = [];
        $replygroup[] = $mform->createElement('text', 'replyto', '', [
            'placeholder' => get_string('replytoemail', 'local_emailstudents'), 'size' => 30]);
        $replygroup[] = $mform->createElement('text', 'replytoname', '', [
            'placeholder' => get_string('replytoname', 'local_emailstudents'), 'size' => 24]);
        $mform->addGroup($replygroup, 'replygroup', get_string('replyto', 'local_emailstudents'), '', false);
        $mform->setType('replyto', PARAM_EMAIL);
        $mform->setType('replytoname', PARAM_TEXT);
        $mform->addHelpButton('replygroup', 'replyto', 'local_emailstudents');

        $mform->addElement('text', 'subject', get_string('subject', 'local_emailstudents'), [
            'size' => 70, 'maxlength' => 255, 'placeholder' => get_string('subjectplaceholder', 'local_emailstudents'),
            'data-lbe-field' => 'subject']);
        $mform->setType('subject', PARAM_TEXT);
        $mform->addRule('subject', null, 'required', null, 'client');
        $mform->addRule('subject', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('editor', 'body_editor', get_string('body', 'local_emailstudents'), ['rows' => 16],
            $this->_customdata['editoroptions']);
        $mform->setType('body_editor', PARAM_CLEANHTML);
        $mform->addRule('body_editor', null, 'required', null, 'client');

        $mform->addElement('filemanager', 'attachments', get_string('attachments', 'local_emailstudents'), null,
            $this->_customdata['attachoptions']);
        $mform->addHelpButton('attachments', 'attachments', 'local_emailstudents');

        $mform->addElement('select', 'attachmode', get_string('attachmode', 'local_emailstudents'), [
            'attach' => get_string('attachmode_attach', 'local_emailstudents'),
            'links' => get_string('attachmode_links', 'local_emailstudents'),
        ]);
        $mform->setDefault('attachmode', get_config('local_emailstudents', 'defaultattachmode') ?: 'attach');
        $mform->addHelpButton('attachmode', 'attachmode', 'local_emailstudents');

        // Delivery.
        $mform->addElement('header', 'hdrdelivery', get_string('step_delivery', 'local_emailstudents'));
        $mform->setExpanded('hdrdelivery', true);

        $mform->addElement('date_time_selector', 'timescheduled', get_string('schedule', 'local_emailstudents'),
            ['optional' => true, 'step' => 5]);
        $mform->addHelpButton('timescheduled', 'schedule', 'local_emailstudents');

        $mform->addElement('advcheckbox', 'copytosender', get_string('copytosender', 'local_emailstudents'),
            get_string('copytosender_desc', 'local_emailstudents'));
        $mform->setDefault('copytosender', 1);

        $mform->addElement('hidden', 'confirmed', 0);
        $mform->setType('confirmed', PARAM_INT);

        $buttons = [];
        $buttons[] = $mform->createElement('submit', 'submitbutton', get_string('sendnow', 'local_emailstudents'),
            ['data-lbe-send' => 1]);
        $buttons[] = $mform->createElement('submit', 'testbutton', get_string('sendtest', 'local_emailstudents'),
            ['data-lbe-test' => 1], false);
        $buttons[] = $mform->createElement('submit', 'savetemplate', get_string('saveastemplate', 'local_emailstudents'),
            ['data-lbe-savetemplate' => 1], false);
        $buttons[] = $mform->createElement('cancel');
        $mform->addGroup($buttons, 'buttonar', '', [' '], false);
        $mform->registerNoSubmitButton('testbutton');
        $mform->registerNoSubmitButton('savetemplate');
        $mform->addElement('hidden', 'templatename', '');
        $mform->setType('templatename', PARAM_TEXT);
        $mform->addElement('hidden', 'templateshared', 0);
        $mform->setType('templateshared', PARAM_INT);
        $mform->closeHeaderBefore('buttonar');
    }

    /**
     * Validation.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        global $USER;
        $errors = parent::validation($data, $files);

        if (trim($data['subject'] ?? '') === '') {
            $errors['subject'] = get_string('required');
        }
        $body = $data['body_editor']['text'] ?? '';
        if (trim(strip_tags($body, '<img>')) === '') {
            $errors['body_editor'] = get_string('required');
        }
        if (!empty($data['replyto']) && !validate_email($data['replyto'])) {
            $errors['replygroup'] = get_string('invalidemail');
        }

        // Attachment size limit across all files.
        if (!empty($data['attachments'])) {
            $info = file_get_draft_area_info($data['attachments']);
            if ($info['filesize'] > helper::max_attachment_bytes()) {
                $errors['attachments'] = get_string('attachtoobig', 'local_emailstudents',
                    display_size(helper::max_attachment_bytes()));
            }
        }

        $filters = recipients::normalise($data, (int) $this->_customdata['courseid'], (int) $USER->id);
        $count = recipients::count($filters);
        $max = (int) get_config('local_emailstudents', 'maxrecipients');
        if ($count === 0) {
            $errors['lastaccess'] = get_string('norecipients', 'local_emailstudents');
        } else if ($max > 0 && $count > $max) {
            $errors['lastaccess'] = get_string('toomanyrecipients', 'local_emailstudents',
                (object) ['count' => $count, 'max' => $max]);
        }

        foreach (['progress' => 'progressgroup', 'grade' => 'gradegroup'] as $field => $group) {
            if (!empty($data[$field . 'op']) && (!is_numeric($data[$field] ?? '') || $data[$field] < 0 || $data[$field] > 100)) {
                $errors[$group] = get_string('percentrange', 'local_emailstudents');
            }
        }
        if (!empty($data['timescheduled']) && $data['timescheduled'] < time() - 300) {
            $errors['timescheduled'] = get_string('scheduleinpast', 'local_emailstudents');
        }
        return $errors;
    }
}
