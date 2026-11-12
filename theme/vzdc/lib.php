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
 * Theme vZDC - library functions.
 *
 * @package    theme_vzdc
 * @copyright  2026 Virtual Washington ARTCC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Returns the main SCSS: Boost Union's, followed by ours.
 *
 * @param \core\output\theme_config $theme The theme config object.
 * @return string
 */
function theme_vzdc_get_main_scss_content($theme) {
    global $CFG;
    require_once($CFG->dirroot . '/theme/boost_union/lib.php');

    $scss = theme_boost_union_get_main_scss_content(\core\output\theme_config::load('boost_union'));
    $scss .= file_get_contents($CFG->dirroot . '/theme/vzdc/scss/post.scss');
    return $scss;
}

/**
 * Returns the pre SCSS (variables). Core also calls the parents' callbacks,
 * so Boost and Boost Union variables are already in the stack.
 *
 * @param \core\output\theme_config $theme The theme config object.
 * @return string
 */
function theme_vzdc_get_pre_scss($theme) {
    global $CFG;
    return file_get_contents($CFG->dirroot . '/theme/vzdc/scss/pre.scss');
}

/**
 * Returns the extra SCSS. Nothing of our own; the parents' callbacks still run.
 *
 * @param \core\output\theme_config $theme The theme config object.
 * @return string
 */
function theme_vzdc_get_extra_scss($theme) {
    return '';
}

/**
 * Lets Boost Union's flavours change the CSS URL for this theme too.
 *
 * @param array $urls The CSS URLs.
 */
function theme_vzdc_alter_css_urls(&$urls) {
    global $CFG;
    require_once($CFG->dirroot . '/theme/boost_union/lib.php');
    theme_boost_union_alter_css_urls($urls);
}
