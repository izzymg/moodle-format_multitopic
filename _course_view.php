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
 * Display the course home page.
 *
 * CHANGES: This file is for editing course sections, not just viewing them.
 * Section IDs are passed on to the appropriate function.
 *
 * @package   format_multitopic
 * @copyright 1999 Martin Dougiamas  http://dougiamas.com,
 *            2018 Otago Polytechnic
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace format_multitopic;

    require_once('../../../config.php');                                        // CHANGED.
    require_once('locallib.php');                                               // CHANGED.
    require_once($CFG->libdir . '/completionlib.php');

if (true) {                                                                     // ADDED: To pass indentation style check.

    $id          = optional_param('id', 0, PARAM_INT);
    $name        = optional_param('name', '', PARAM_TEXT);
    $edit        = optional_param('edit', -1, PARAM_BOOL);
    $disableajax = optional_param('onetopic_da', -1, PARAM_INT);
    // INCLUDED LINE ABOVE from /course/format/onetopic/format.php $disableajax .
    $hideid      = optional_param('hideid', null, PARAM_INT);                   // CHANGED.
    $showid      = optional_param('showid', null, PARAM_INT);                   // CHANGED.
    $idnumber    = optional_param('idnumber', '', PARAM_RAW);
    $sectionid   = optional_param('sectionid', 0, PARAM_INT);
    $section     = optional_param('section', 0, PARAM_INT);
    // REMOVED: move parameter.
    // ADDED.
    $destparentid = optional_param('destparentid', null, PARAM_INT);
    $destprevupid = optional_param('destprevupid', null, PARAM_INT);
    $destnextupid = optional_param('destnextupid', null, PARAM_INT);
    $destlevel    = optional_param('destlevel', null, PARAM_INT);
    // END ADDED.
    $marker      = optional_param('marker', -1, PARAM_INT);
    $switchrole  = optional_param('switchrole', -1, PARAM_INT); // Deprecated, use course/switchrole.php instead.
    $return      = optional_param('return', 0, PARAM_LOCALURL);

    // ADDED.
    $hide = null;
    if ($hideid) {
        $hide = new \stdClass();
        $hide->id = $hideid;
    }
    $show = null;
    if ($showid) {
        $show = new \stdClass();
        $show->id = $showid;
    }
    $dest = null;
    if ($destparentid || $destprevupid || $destnextupid) {
        $dest = new \stdClass();
        if ($destparentid) {
            $dest->parentid = $destparentid;
        }
        if ($destprevupid) {
            $dest->prevupid = $destprevupid;
        }
        if ($destnextupid) {
            $dest->nextupid = $destnextupid;
        }
        if ($destlevel !== null) {
            $dest->level = $destlevel;
        }
    }
    // END ADDED.

    $params = array();
    if (!empty($name)) {
        $params = array('shortname' => $name);
    } else if (!empty($idnumber)) {
        $params = array('idnumber' => $idnumber);
    } else if (!empty($id)) {
        $params = array('id' => $id);
    } else {
        print_error('unspecifycourseid', 'error');
    }

    $course = $DB->get_record('course', $params, '*', MUST_EXIST);

    $urlparams = array('id' => $course->id);

    // Sectionid should get priority over section number.
    // CHANGED.
    if ($sectionid) {
        $section = $DB->get_record('course_sections', array('id' => $sectionid, 'course' => $course->id), '*', MUST_EXIST);
    } else {
        $section = $DB->get_record('course_sections', array('section' => $section, 'course' => $course->id), '*', MUST_EXIST);
    }
    if ($section->section) {
        // This is changed in renderer.php for view pages, and here for edit pages.
        $urlparams['sectionid'] = $section->id;
    }
    // END CHANGED.

    $PAGE->set_url('/course/view.php', $urlparams); // Defined here to avoid notices on errors etc.
    // TODO: Change?
    // NOTE: Can't change this without changing references to $PAGE->url ?

    // Prevent caching of this page to stop confusion when changing page after making AJAX changes.
    $PAGE->set_cacheable(false);

    \context_helper::preload_course($course->id);
    $context = \context_course::instance($course->id, MUST_EXIST);

    // Remove any switched roles before checking login.
    if ($switchrole == 0 && confirm_sesskey()) {
        role_switch($switchrole, $context);
    }

    require_login($course);

    // Switchrole - sanity check in cost-order...
    $resetuserallowedediting = false;
    if ($switchrole > 0 && confirm_sesskey() &&
        has_capability('moodle/role:switchroles', $context)) {
        // Is this role assignable in this context?
        // Inquiring minds want to know...
        $aroles = get_switchable_roles($context);
        if (is_array($aroles) && isset($aroles[$switchrole])) {
            role_switch($switchrole, $context);
            // Double check that this role is allowed here.
            require_login($course);
        }
        // Reset course page state - this prevents some weird problems ;-) .
        $USER->activitycopy = false;
        $USER->activitycopycourse = null;
        unset($USER->activitycopyname);
        unset($SESSION->modform);
        $USER->editing = 0;
        $resetuserallowedediting = true;
    }

    // If course is hosted on an external server, redirect to corresponding
    // url with appropriate authentication attached as parameter.
    if (file_exists($CFG->dirroot . '/course/externservercourse.php')) {
        include($CFG->dirroot . '/course/externservercourse.php');
        if (function_exists('extern_server_course')) {
            if ($externurl = extern_server_course($course)) {
                redirect($externurl);
            }
        }
    }


    require_once($CFG->dirroot . '/calendar/lib.php');    // This is after login because it needs $USER.

    // Must set layout before gettting section info. See MDL-47555.
    $PAGE->set_pagelayout('course');

    if ($section and $section->section > 0) {                                   // CHANGED.

        // Get section details and check it exists.
        $modinfo = get_fast_modinfo($course);
        $coursesections = $modinfo->get_section_info($section->section, MUST_EXIST); // CHANGED.

        // Check user is allowed to see it.
        if (!$coursesections->uservisible) {
            // Check if coursesection has conditions affecting availability and if
            // so, output availability info.
            if ($coursesections->visible && $coursesections->availableinfo) {
                $sectionname     = get_section_name($course, $coursesections);
                $message = get_string('notavailablecourse', '', $sectionname);
                redirect(course_get_url($course), $message, null, \core\output\notification::NOTIFY_ERROR);
            } else {
                // Note: We actually already know they don't have this capability
                // or uservisible would have been true; this is just to get the
                // correct error message shown.
                require_capability('moodle/course:viewhiddensections', $context);
            }
        }
    }

    // Fix course format if it is no longer installed.
    $course->format = course_get_format($course)->get_format();

    $PAGE->set_pagetype('course-view-' . $course->format);
    $PAGE->set_other_editing_capability('moodle/course:update');
    $PAGE->set_other_editing_capability('moodle/course:manageactivities');
    $PAGE->set_other_editing_capability('moodle/course:activityvisibility');
    if (course_format_uses_sections($course->format)) {
        $PAGE->set_other_editing_capability('moodle/course:sectionvisibility');
        $PAGE->set_other_editing_capability('moodle/course:movesections');
    }

    // Preload course format renderer before output starts.
    // This is a little hacky but necessary since
    // format.php is not included until after output starts.
    if (file_exists($CFG->dirroot . '/course/format/' . $course->format . '/renderer.php')) {
        require_once($CFG->dirroot . '/course/format/' . $course->format . '/renderer.php');
        if (class_exists('format_' . $course->format . '_renderer')) {
            // Call get_renderer only if renderer is defined in format plugin
            // otherwise an exception would be thrown.
            $PAGE->get_renderer('format_' . $course->format);
        }
    }

    if ($resetuserallowedediting) {
        // Ugly hack.
        unset($PAGE->_user_allowed_editing);
    }

    if (!isset($USER->editing)) {
        $USER->editing = 0;
    }
    if ($PAGE->user_allowed_editing()) {
        if (($edit == 1) and confirm_sesskey()) {
            $USER->editing = 1;
            // Redirect to site root if Editing is toggled on frontpage.
            if ($course->id == SITEID) {
                redirect($CFG->wwwroot . '/?redirect=0');
            } else if (!empty($return)) {
                redirect($CFG->wwwroot . $return);
            } else {
                $url = new \moodle_url($PAGE->url, array('notifyeditingon' => 1));
                redirect($url);
            }
        } else if (($edit == 0) and confirm_sesskey()) {
            $USER->editing = 0;
            if (!empty($USER->activitycopy) && $USER->activitycopycourse == $course->id) {
                $USER->activitycopy       = false;
                $USER->activitycopycourse = null;
            }
            // Redirect to site root if Editing is toggled on frontpage.
            if ($course->id == SITEID) {
                redirect($CFG->wwwroot . '/?redirect=0');
            } else if (!empty($return)) {
                redirect($CFG->wwwroot . $return);
            } else {
                redirect($PAGE->url);
            }
        }

        // INCLUDED /course/format/onetopic/format.php $disableajax .
        if (!isset($USER->onetopic_da)) {
            $USER->onetopic_da = array();
        }
        if ($disableajax !== -1) {
            $USER->onetopic_da[$course->id] = $disableajax ? true : false;
            redirect($PAGE->url);
        }
        // END INCLUDED.

        if (has_capability('moodle/course:sectionvisibility', $context)) {
            // CHANGED.
            if ($hide && confirm_sesskey()) {
                format_multitopic_set_section_visible($course->id, $hide, 0);
                redirect($PAGE->url);
            }

            if ($show && confirm_sesskey()) {
                format_multitopic_set_section_visible($course->id, $show, 1);
                redirect($PAGE->url);
            }
            // END CHANGED.
        }

        if (!empty($section) && !empty($dest) &&
                has_capability('moodle/course:movesections', $context) &&
                (has_capability('moodle/course:update', $context) || !isset($destlevel)) &&
                confirm_sesskey()) {                                            // CHANGED.
            $destsection = $dest;                                               // CHANGED.
            try {
                format_multitopic_move_section_to($course, $section, $destsection, false);
                if ($course->id == SITEID) {
                    redirect($CFG->wwwroot . '/?redirect=0');
                } else {
                    redirect(course_get_url($course, $section));                // CHANGED.
                }
            } catch (moodle_exception $e) {  // CHANGED.
                echo $OUTPUT->notification($e->getMessage());
            }
        }
    } else {
        $USER->editing = 0;
    }

    $SESSION->fromdiscussion = $PAGE->url->out(false);


    if ($course->id == SITEID) {
        // This course is not a real course.
        redirect($CFG->wwwroot . '/');
    }

    $completion = new \completion_info($course);
    if ($completion->is_enabled()) {
        $PAGE->requires->string_for_js('completion-alt-manual-y', 'completion');
        $PAGE->requires->string_for_js('completion-alt-manual-n', 'completion');

        $PAGE->requires->js_init_call('M.core_completion.init');
    }

    // We are currently keeping the button here from 1.x to help new teachers figure out
    // what to do, even though the link also appears in the course admin block.  It also
    // means you can back out of a situation where you removed the admin block. :) .
    if ($PAGE->user_allowed_editing()) {
        $buttons = $OUTPUT->edit_button($PAGE->url);
        $PAGE->set_button($buttons);
    }

    // If viewing a section, make the title more specific.
    if ($section and $section->section > 0 and course_format_uses_sections($course->format)) {
        $sectionname = get_string('sectionname');
        $sectiontitle = get_section_name($course, $section);
        $PAGE->set_title(get_string('coursesectiontitle', 'moodle',
            array('course' => $course->fullname, 'sectiontitle' => $sectiontitle, 'sectionname' => $sectionname)));
    } else {
        $PAGE->set_title(get_string('coursetitle', 'moodle', array('course' => $course->fullname)));
    }

    $PAGE->set_heading($course->fullname);
    echo $OUTPUT->header();

    if ($completion->is_enabled()) {
        // This value tracks whether there has been a dynamic change to the page.
        // It is used so that if a user does this - (a) set some tickmarks, (b)
        // go to another page, (c) clicks Back button - the page will
        // automatically reload. Otherwise it would start with the wrong tick
        // values.
        echo \html_writer::start_tag('form', array('action' => '.', 'method' => 'get'));
        echo \html_writer::start_tag('div');
        echo \html_writer::empty_tag('input',
            array('type' => 'hidden', 'id' => 'completion_dynamic_change', 'name' => 'completion_dynamic_change', 'value' => '0'));
        echo \html_writer::end_tag('div');
        echo \html_writer::end_tag('form');
    }

    // Course wrapper start.
    echo \html_writer::start_tag('div', array('class' => 'course-content'));

    // Make sure that section 0 exists (this function will create one if it is missing).
    course_create_sections_if_missing($course, 0);

    // Get information about course modules and existing module types.
    // format.php in course formats may rely on presence of these variables.
    $modinfo = get_fast_modinfo($course);
    $modnames = get_module_types_names();
    $modnamesplural = get_module_types_names(true);
    $modnamesused = $modinfo->get_used_module_names();
    $mods = $modinfo->get_cms();
    $sections = $modinfo->get_section_info_all();

    // CAUTION, hacky fundamental variable defintion to follow!
    // Note that because of the way course fromats are constructed though
    // inclusion we pass parameters around this way..
    $displaysection = $section;

    // Include the actual course format.
    require($CFG->dirroot . '/course/format/' . $course->format . '/format.php');
    // Content wrapper end.

    echo \html_writer::end_tag('div');

    // Trigger course viewed event.
    // We don't trust $context here. Course format inclusion above executes in the global space. We can't assume
    // anything after that point.
    course_view(\context_course::instance($course->id), $section->section);      // CHANGED.

    // Include course AJAX.
    include_course_ajax($course, $modnamesused);

    echo $OUTPUT->footer();

}
