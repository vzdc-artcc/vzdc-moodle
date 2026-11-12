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
 * Theme vZDC - theme configuration.
 *
 * A grandchild of Boost, built the way the Boost Union Child boilerplate
 * (moodle-an-hochschulen/moodle-theme_boost_union_child) builds one: take Boost
 * Union's configuration whole, then rename it and add our own SCSS on top.
 *
 * @package    theme_vzdc
 * @copyright  2026 Virtual Washington ARTCC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Inherit layouts, renderer factory and every other setting from Boost Union.
require($CFG->dirroot . '/theme/boost_union/config.php');

require_once($CFG->dirroot . '/theme/vzdc/lib.php');

$THEME->name = 'vzdc';
$THEME->parents = ['boost_union', 'boost'];
$THEME->scss = function ($theme) {
    return theme_vzdc_get_main_scss_content($theme);
};
$THEME->prescsscallback = 'theme_vzdc_get_pre_scss';
$THEME->extrascsscallback = 'theme_vzdc_get_extra_scss';
$THEME->rendererfactory = 'theme_overridden_renderer_factory';

// Boost Union's own admin settings for blocks and custom SCSS live under
// theme_boost_union, so hand them to this theme as well (as Boost Union Child does).
foreach (['unaddableblocks', 'scss', 'scsspre'] as $vzdcsetting) {
    $vzdcvalue = get_config('theme_boost_union', $vzdcsetting);
    if (!empty($vzdcvalue)) {
        $THEME->settings->{$vzdcsetting} = $vzdcvalue;
    }
}
unset($vzdcsetting, $vzdcvalue);
