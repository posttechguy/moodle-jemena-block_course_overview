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
 * Course overview block
 *
 * Currently, just a copy-and-paste from the old My Moodle.
 *
 * @package   blocks
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once($CFG->dirroot.'/lib/weblib.php');
require_once($CFG->dirroot . '/lib/formslib.php');

class block_course_overview extends block_base {
    /**
     * block initializations
     */
    public function init() {
        $this->title   = get_string('pluginname', 'block_course_overview');
    }

    /**
     * block contents
     *
     * @return object
     */
    public function get_content() {
        global $USER, $CFG, $DB;
        if($this->content !== NULL) {
            return $this->content;
        }

        $this->content = new stdClass();
        $this->content->text = '';
        $this->content->footer = '';

        $content = array();

        // limits the number of courses showing up
        $courses_limit = 21;
        // FIXME: this should be a block setting, rather than a global setting
        if (isset($CFG->mycoursesperpage)) {
            $courses_limit = $CFG->mycoursesperpage;
        }

        $morecourses = false;
        if ($courses_limit > 0) {
            $courses_limit = $courses_limit + 1;
        }

        // $courses = enrol_get_my_courses('id, idnumber, shortname, ismandatory', 'visible DESC, sortorder ASC', $courses_limit);
        $params = array();
        $sql    = "
            SELECT
                c.id,c.category,c.sortorder,c.shortname,c.fullname,c.idnumber,c.ismandatory,
                c.startdate,c.visible,c.groupmode,c.groupmodeforce,c.cacherev,
                ctx.id AS ctxid, ctx.path AS ctxpath, ctx.depth AS ctxdepth,
                ctx.contextlevel AS ctxlevel, ctx.instanceid AS ctxinstance,
                timecomp.timecompleted
            FROM {course} AS c
            JOIN (
                SELECT DISTINCT e.courseid
                FROM {enrol} AS e
                JOIN {user_enrolments} AS ue ON (ue.enrolid = e.id AND ue.userid = :userid1)
                WHERE ue.status = :active
                AND e.status = :enabled
                AND ue.timestart < :now1
                AND (ue.timeend = 0 OR ue.timeend > :now2)
            ) AS en ON (en.courseid = c.id)
            LEFT JOIN {context} ctx ON (ctx.instanceid = c.id AND ctx.contextlevel = :contextlevel)
            LEFT JOIN (
                SELECT ccc.id, MAX(cc.timecompleted) as timecompleted
                FROM {course} AS ccc
                JOIN {course_completions} AS cc ON cc.course = ccc.id
                WHERE cc.userid = :userid2
                GROUP BY 1
            ) AS timecomp ON timecomp.id = c.id
            WHERE c.id <> :siteid
            ORDER BY timecomp.timecompleted, c.visible DESC, c.sortorder ASC
        ";
        $params['siteid']       = SITEID;
        $params['contextlevel'] = CONTEXT_COURSE;
        $params['userid1']      = $USER->id;
        $params['userid2']      = $USER->id;
        $params['active']       = ENROL_USER_ACTIVE;
        $params['enabled']      = ENROL_INSTANCE_ENABLED;
        $params['now1']         = round(time(), -2); // improves db caching
        $params['now2']         = $params['now1'];

        $courses = $DB->get_records_sql($sql, $params, 0, $courses_limit);

        $site = get_site();
        $course = $site; //just in case we need the old global $course hack

        if (is_enabled_auth('mnet')) {
            $remote_courses = get_my_remotecourses();
        }
        if (empty($remote_courses)) {
            $remote_courses = array();
        }

        if (($courses_limit > 0) && (count($courses)+count($remote_courses) >= $courses_limit)) {
            // get rid of any remote courses that are above the limit
            $remote_courses = array_slice($remote_courses, 0, $courses_limit - count($courses), true);
            if (count($courses) >= $courses_limit) {
                //remove the 'marker' course that we retrieve just to see if we have more than $courses_limit
                array_pop($courses);
            }
            $morecourses = true;
        }


        if (array_key_exists($site->id,$courses)) {
            unset($courses[$site->id]);
        }

        foreach ($courses as $c) {
            if (isset($USER->lastcourseaccess[$c->id])) {
                $courses[$c->id]->lastaccess = $USER->lastcourseaccess[$c->id];
            } else {
                $courses[$c->id]->lastaccess = 0;
            }
        }

        if (empty($courses) && empty($remote_courses)) {
            $content[] = get_string('nocourses','my');
        } else {
            ob_start();

            require_once $CFG->dirroot."/course/lib.php";
            $this->print_my_overview($courses);

            $content[] = ob_get_contents();
            ob_end_clean();
        }

        // if more than 20 courses
        if ($morecourses) {
            $content[] = '<br />...';
        }

        $this->content->text = implode($content);

        return $this->content;
    }




    public function print_my_overview($courses) {
        global $CFG, $USER, $DB, $OUTPUT;

        require_once($CFG->dirroot.'/grade/lib.php');
        require_once($CFG->dirroot.'/grade/querylib.php');

        $htmlarray = array();
        if ($modules = $DB->get_records('modules')) {
            foreach ($modules as $mod) {
                if (file_exists(dirname(dirname(__FILE__)).'/mod/'.$mod->name.'/lib.php')) {
                    include_once(dirname(dirname(__FILE__)).'/mod/'.$mod->name.'/lib.php');
                    $fname = $mod->name.'_print_overview';
                    if (function_exists($fname)) {
                        $fname($courses,$htmlarray);
                    }
                }
            }
        }

        // Create the table that shows the courses
        if ($courses) {
            // Array to that stores yes or no
            $arryesno = array();
            $arryesno[0] = get_string('opt', 'block_course_overview');
            $arryesno[1] = get_string('man', 'block_course_overview');

            $table = new html_table();
            $table->width = '100%';
            $table->align = array('left', 'left', 'center', 'center', 'left');
            // Create the row headings
            $row = new html_table_row();
            // Create heading cells
            $cell = new html_table_cell();
            $cell->header = true;
            $cell->text = get_string('course');
            $row->cells[] = $cell;
            // Create heading cells
            $cell = new html_table_cell();
            $cell->header = true;
            $cell->text = get_string('iltorelearning', 'block_course_overview');
            $row->cells[] = $cell;
            // Create heading cell
            $cell = new html_table_cell();
            $cell->header = true;
            $cell->text = get_string('mandatory', 'block_course_overview');
            $row->cells[] = $cell;
            // Create heading cell
            $cell = new html_table_cell();
            $cell->header = true;
            $cell->text = get_string('completionstatus', 'block_course_overview');
            $row->cells[] = $cell;
            // Create heading cell
            $cell = new html_table_cell();
            $cell->header = true;
            $cell->text = get_string('dateofcompletion', 'block_course_overview');
            $row->cells[] = $cell;
            // Add this row to the table
            $table->data[] = $row;

            foreach ($courses as $course) {
                $ismandatory = (!is_null($course->ismandatory)) ? $arryesno[$course->ismandatory] : '-';
                // Assign as red ticket and blank as default

                $dateofcompletion = '-';

                if (empty($course->timecompleted)) {
                    $coursecompletion = $OUTPUT->pix_icon('i/grade_incorrect', get_string('notcompleted', 'block_course_overview')); // Red tick;
                } else {
                    $coursecompletion = $OUTPUT->pix_icon('i/grade_correct',   get_string('completed', 'block_course_overview')); // Green tick;
                    $dateofcompletion = userdate($course->timecompleted);
                }


                // Get the completion status
                /*************************************************************************************
                 *
                 * Get the course completion status rather than the activity grade
                 *
                 **********************************************************************************
                 $sql = "SELECT MAX(cc.timecompleted) as timecompleted
                            FROM {course_completions} cc
                            WHERE cc.course = :courseid
                            AND cc.userid = :userid";

                $timecompleted = $DB->get_record_sql($sql, array('courseid' => $course->id, 'userid' => $USER->id));
                if ($timecompleted->timecompleted) {
                    $coursecompletion = $OUTPUT->pix_icon('i/grade_correct',
                        get_string('completed', 'block_course_overview')); // Green tick
                    $dateofcompletion = userdate($timecompleted->timecompleted);
                }
***/
                /*
                if ($course_item = grade_item::fetch_course_item($course->id)) {
                    $grade = new grade_grade(array('itemid'=>$course_item->id, 'userid'=>$USER->id));
                    $coursegrade->percentage = grade_format_gradevalue($grade->finalgrade, $course_item, true,
                            GRADE_DISPLAY_TYPE_PERCENTAGE, 0);
                    if (substr($coursegrade->percentage, 0, 3) == '100') {
                        $coursecompletion = $OUTPUT->pix_icon('i/tick_green_big',
                                get_string('completed', 'block_course_overview')); // Green tick
                        // Get the last module that was marked
                        $sql = "SELECT MAX(g.timemodified) as timemodified
                            FROM {grade_grades} g
                            INNER JOIN {grade_items} gi
                            ON g.itemid = gi.id
                            AND gi.courseid = :courseid
                            AND g.userid = :userid";
                        if ($timemodified = $DB->get_record_sql($sql, array('courseid' => $course->id, 'userid' => $USER->id))) {
                            $dateofcompletion = userdate($timemodified->timemodified);
                        }
                    }
                }
                */
                /*************************************************************************************/


                // Create the row
                $row = new html_table_row();
                $row->attributes['class'] = 'mycoursecomp';
                // Create attributes
                $attributes = array('title' => s($course->fullname));
                if (empty($course->visible)) {
                    $attributes['class'] = 'dimmed';
                }
                // Create a new cell
                $cell = new html_table_cell();
                $cell->text = html_writer::link(new moodle_url('/course/view.php',
                        array('id' => $course->id)), format_string($course->fullname), $attributes);
                $row->cells[] = $cell;
                // ID number
                $cell = new html_table_cell();
                $cell->text = $course->idnumber;
                $row->cells[] = $cell;
                // Mandatory
                $cell = new html_table_cell();
                $cell->text = $ismandatory;
                $row->cells[] = $cell;
                // Completion status
                $cell = new html_table_cell();
                $cell->text = $coursecompletion;
                $row->cells[] = $cell;
                // Date of completion
                $cell = new html_table_cell();
                $cell->text = $dateofcompletion;
                $row->cells[] = $cell;
                // Add to the table
                $table->data[] = $row;
                if (array_key_exists($course->id, $htmlarray)) {
                    foreach ($htmlarray[$course->id] as $modname => $html) {
                        // Create the row
                        $row = new html_table_row();
                        $cell = new html_table_cell();
                        $cell->colspan = '5';
                        $cell->text = $html;
                        $row->cells[] = $cell;
                        $table->data[] = $row;
                    }
                }
            }

            echo html_writer::table($table);
        }
    }

    /**
     * allow the block to have a configuration page
     *
     * @return boolean
     */
    public function has_config() {
        return false;
    }

    /**
     * locations where block can be displayed
     *
     * @return array
     */
    public function applicable_formats() {
        return array('my-index'=>true);
    }
}
?>
