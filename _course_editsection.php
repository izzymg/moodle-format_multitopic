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
 * Edit the section basic information and availability
 *
 * CHANGES:
 *  - Use section info instead of section number.
 *  - Ignore section return.
 *
 * @package   format_multitopic
 * @copyright 1999 Martin Dougiamas  http://dougiamas.com,
 *            2018 Otago Polytechnic
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace format_multitopic;                                                    // ADDED.

require_once("../../../config.php");                                            // CHANGED.
require_once("../../lib.php");                                                  // CHANGED.
require_once($CFG->libdir . '/formslib.php');

$id = required_param('id', PARAM_INT);    // Course_sections.id .
// REMOVED $sectionreturn .
$deletesection = optional_param('delete', 0, PARAM_BOOL);

$PAGE->set_url('/course/format/multitopic/_course_editsection.php', array('id' => $id)); // CHANGED: Ignore $sectionreturn.
// NOTE: Can't revert this without changing reference to $PAGE->url ?

$section = $DB->get_record('course_sections', array('id' => $id), '*', MUST_EXIST);
$course = $DB->get_record('course', array('id' => $section->course), '*', MUST_EXIST);
// REMOVED sectionnum .

require_login($course);
$context = \context_course::instance($course->id);
require_capability('moodle/course:update', $context);

// Get section_info object with all availability options.
$sectioninfo = course_get_format($course)->fmt_get_section($section);           // CHANGED: Use custom function, pass section info.

// Deleting the section.
if ($deletesection) {
    $cancelurl = course_get_url($course, $sectioninfo);                         // CHANGED: Ignore $sectionreturn.
    if (course_can_delete_section($course, $sectioninfo)) {
        $confirm = optional_param('confirm', false, PARAM_BOOL) && confirm_sesskey();
        if (!$confirm && optional_param('sesskey', null, PARAM_RAW) !== null &&
                empty($sectioninfo->summary) && empty($sectioninfo->sequence) && confirm_sesskey()) {
            // Do not ask for confirmation if section is empty and sesskey is already provided.
            $confirm = true;
        }
        if ($confirm) {
            course_delete_section($course, $sectioninfo, true, true);
            // ADDED.
            // If section was topic level, return to page, else return to previous section.
            $sectionreturn = new \stdClass();
            if ($sectioninfo->levelsan >= FMT_SECTION_LEVEL_TOPIC) {
                $sectionreturn->id = $sectioninfo->parentid;
            } else {
                $sectionreturn->id = $sectioninfo->prevupid;
            }
            // END ADDED.
            $courseurl = course_get_url($course, $sectionreturn);               // CHANGED: Use sectionreturn defined above.
            redirect($courseurl);
        } else {
            if (get_string_manager()->string_exists('deletesection', 'format_' . $course->format)) {
                $strdelete = get_string('deletesection', 'format_' . $course->format);
            } else {
                $strdelete = get_string('deletesection');
            }
            $PAGE->navbar->add($strdelete);
            $PAGE->set_title($strdelete);
            $PAGE->set_heading($course->fullname);
            echo $OUTPUT->header();
            echo $OUTPUT->box_start('noticebox');
            $optionsyes = array('id' => $id, 'confirm' => 1, 'delete' => 1, 'sesskey' => sesskey());
            $deleteurl = new \moodle_url('/course/format/multitopic/_course_editsection.php', $optionsyes); // CHANGED.
            $formcontinue = new \single_button($deleteurl, get_string('delete'));
            $formcancel = new \single_button($cancelurl, get_string('cancel'), 'get');
            echo $OUTPUT->confirm(get_string('confirmdeletesection', '',
                get_section_name($course, $sectioninfo)), $formcontinue, $formcancel);
            echo $OUTPUT->box_end();
            echo $OUTPUT->footer();
            exit;
        }
    } else {
        notice(get_string('nopermissions', 'error', get_string('deletesection')), $cancelurl);
    }
}

$editoroptions = array('context' => $context, 'maxfiles' => EDITOR_UNLIMITED_FILES, 'maxbytes' => $CFG->maxbytes,
                        'trusttext' => false, 'noclean' => true);

$courseformat = course_get_format($course);
$defaultsectionname = $courseformat->get_default_section_name($sectioninfo);    // CHANGED: Use custom section info.

$customdata = array(
    'cs' => $sectioninfo,
    'editoroptions' => $editoroptions,
    'defaultsectionname' => $defaultsectionname
);
$mform = $courseformat->editsection_form($PAGE->url, $customdata);

// Set current value, make an editable copy of section_info object.
// This will retrieve all format-specific options as well.
$initialdata = convert_to_array($sectioninfo);
if (!empty($CFG->enableavailability)) {
    $initialdata['availabilityconditionsjson'] = $sectioninfo->availability;
}
$mform->set_data($initialdata);

if ($mform->is_cancelled()) {
    // Form cancelled, return to course.
    redirect(course_get_url($course, $sectioninfo));                            // CHANGED: Ignore sectionreturn, pass sectioninfo.
} else if ($data = $mform->get_data()) {
    // Data submitted and validated, update and return to course.

    // For consistency, we set the availability field to 'null' if it is empty.
    if (!empty($CFG->enableavailability)) {
        // Renamed field.
        $data->availability = $data->availabilityconditionsjson;
        unset($data->availabilityconditionsjson);
        if ($data->availability === '') {
            $data->availability = null;
        }
    }
    course_update_section($course, $section, $data);
    // ADDED: Update section info for return URL.
    if (isset($data->level) && ($data->level != $sectioninfo->levelsan) && ($sectioninfo->section > 0)) {
        // If the level was changed, update the section info properties relevant to generating the URL.
        // (A hack to avoid recalculating section properties.)
        if (($sectioninfo->levelsan < FMT_SECTION_LEVEL_TOPIC) && ($data->level >= FMT_SECTION_LEVEL_TOPIC)) {
            // If the section was changed to Topic level, the former previous page will be the new parent page.
            $sectioninfo->parentid = $sectioninfo->prevpageid;
        }
        $sectioninfo->levelsan = $data->level;
    }
    // END ADDED.

    $PAGE->navigation->clear_cache();
    redirect(course_get_url($course, $sectioninfo));                            // CHANGED: Ignore sectionreturn, pass sectioninfo.
}

// The edit form is displayed for the first time or if there was validation error on the previous step.
$sectionname  = get_section_name($course, $sectioninfo);                        // CHANGED: Pass sectioninfo rather than number.
$stredit      = get_string('edita', '', " $sectionname");
$strsummaryof = get_string('summaryof', '', " $sectionname");

$PAGE->set_title($stredit);
$PAGE->set_heading($course->fullname);
$PAGE->navbar->add($stredit);
echo $OUTPUT->header();

echo $OUTPUT->heading($strsummaryof);

$mform->display();
echo $OUTPUT->footer();
