<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Drop out course target.
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_inspire\local\target;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/grade/grade_item.php');
require_once($CFG->dirroot . '/lib/grade/grade_grade.php');
require_once($CFG->dirroot . '/lib/grade/constants.php');
require_once($CFG->dirroot . '/lib/completionlib.php');
require_once($CFG->dirroot . '/completion/completion_completion.php');

/**
 * Drop out course target.
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_dropout extends binary {

    protected static $coursegradeitems = array();

    public static function get_name() {
        return get_string('target:coursedropout', 'tool_inspire');
    }

    public function is_linear() {
        return false;
    }

    /**
     * Returns the predicted classes that will be ignored.
     *
     * Overwriten because we are also interested in knowing when the student is far from the risk of dropping out.
     *
     * @return array
     */
    protected function ignored_predicted_classes() {
        return array();
    }

    public function get_analyser_class() {
        return '\\tool_inspire\\local\\analyser\\enrolments';
    }

    public function is_valid_analysable(\tool_inspire\analysable $analysable) {
        global $DB;

        // Ongoing courses data can not be used to train.
        if ($analysable->get_end() > time()) {
            return 'Course is not yet finished';
        }

        // Courses that last more than 1 year may not have a regular usage.
        if ($analysable->get_end() - $analysable->get_start() > YEARSECS) {
            return 'Duration is more than 1 year';
        }

        // Not a valid target if there are not enough course accesses.
        // Using anonymous to use the db index, not filtering by timecreated to speed it up.
        $params = array('courseid' => $analysable->get_id(), 'anonymous' => 0, 'start' => $analysable->get_start(),
            'end' => $analysable->get_end());
        list($studentssql, $studentparams) = $DB->get_in_or_equal(array_keys($analysable->get_students()), SQL_PARAMS_NAMED);
        $select = 'courseid = :courseid AND anonymous = :anonymous AND timecreated > :start AND timecreated < :end ' .
            'AND userid ' . $studentssql;
        $nlogs = $DB->count_records_select('logstore_standard_log', $select, array_merge($params, $studentparams));

        // Say 5 logs per week by half of the course students.
        $nweeks = $this->get_time_range_weeks_number($analysable->get_start(), $analysable->get_end());
        $nstudents = count($analysable->get_students());
        if ($nlogs < ($nweeks * ($nstudents / 2) * 5)) {
            return 'Not enough logs';
        }

        // Now we check that we can analyse the course through course completion, course competencies or grades.
        $nogradeitem = false;
        $nogradevalue = false;
        $nocompletion = false;
        $nocompetencies = false;

        $completion = new \completion_info($analysable->get_id());
        if (!$completion->is_enabled() && $completion->has_criteria()) {
            $nocompletion = true;
        }

        if (\core_competency\api::count_competencies_in_course($analysable->get_id()) === 0) {
            $nocompetencies = true;
        }

        // Not a valid target if there is no course grade item.
        self::$coursegradeitems[$analysable->get_id()] = \grade_item::fetch(array('itemtype' => 'course', 'courseid' => $analysable->get_id()));
        if (empty(self::$coursegradeitems[$analysable->get_id()])) {
            $nogradeitem = true;
        }

        if (self::$coursegradeitems[$analysable->get_id()]->grade_type !== GRADE_TYPE_VALUE) {
            $nogradevalue = true;
        }

        if ($nocompletion === true && $nocompetencies === true && ($nogradeitem || $nogradevalue)) {
            return 'No course pass method available (no completion nor competencies or course grades';
        }

        return true;
    }

    /**
     * calculate_sample
     *
     * The meaning of a drop out changes depending on the settings enabled in the course. Following these priorities order:
     * 1.- Course completion
     * 2.- All course competencies completion
     * 3.- Course final grade over grade pass
     * 4.- Course final grade below 50% of the course maximum grade
     *
     * @param int $sampleid
     * @param string $tablename
     * @param \tool_inspire\analysable $analysable
     * @param array $data
     * @return void
     */
    public function calculate_sample($sampleid, $tablename, \tool_inspire\analysable $analysable, $data) {

        // We use completion as a success metric only when it is enabled.
        $completion = new \completion_info($analysable->get_id());
        if ($completion->is_enabled() && $completion->has_criteria()) {
            $ccompletion = new \completion_completion(array('userid' => $sampleid, 'course' => $analysable->get_id()));
            if ($ccompletion->is_complete()) {
                return 0;
            } else {
                return 1;
            }
        }

        // Same with competencies.
        $ncoursecompetencies = \core_competency\api::count_competencies_in_course($analysable->get_id());
        if ($ncoursecompetencies > 0) {
            $nusercompetencies = \core_competency\api::count_proficient_competencies_in_course_for_user(
                $analysable->get_id(), $sampleid);
            if ($ncoursecompetencies > $nusercompetencies) {
                return 1;
            } else {
                return 0;
            }
        }

        // Falling back to the course grades.
        $params = array('userid' => $sampleid, 'itemid' => self::$coursegradeitems[$data['course']->id]->id);
        $grade = \grade_grade::fetch($params);
        if (!$grade || !$grade->finalgrade) {
            // We checked that the course is suitable for being analysed in is_valid_analysable so if the
            // student do not have a final grade it is because there are no grades for that student, which is bad.
            return 1;
        }

        $passed = $grade->is_passed();
        // is_passed returns null if there is no pass grade or can't be calculated.
        if ($passed !== null) {
            // Returning the opposite as 1 means dropout user.
            return !$passed;
        }

        // Pass if gets more than 50% of the course grade.
        list($mingrade, $maxgrade) = $grade->get_grade_min_and_max();
        $weightedgrade = ($grade->finalgrade - $mingrade) / ($maxgrade - $mingrade);

        if ($weightedgrade >= 0.5) {
            return 0;
        }

        return 1;
    }

    public function callback($sampleid, $prediction, $predictionscore) {
        var_dump('AAAAAAAAAAAAAAA: ' . $sampleid . '-' . $prediction . '-' . $predictionscore);
    }
}