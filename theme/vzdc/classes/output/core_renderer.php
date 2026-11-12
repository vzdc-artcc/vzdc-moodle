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

namespace theme_vzdc\output;

/**
 * Core renderer for theme_vzdc.
 *
 * The logos ship inside the theme (pix/), taken from the vZDC website
 * repository, so they are versioned with the image instead of being uploaded
 * through the admin UI. The favicon needs no override: core looks for
 * pix/favicon.ico in the active theme first.
 *
 * @package    theme_vzdc
 * @copyright  2026 Virtual Washington ARTCC
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class core_renderer extends \theme_boost_union\output\core_renderer {
    /**
     * The full logo, shown on the login page and elsewhere core uses the main logo.
     *
     * @param int|null $maxwidth Unused; the image is served as is.
     * @param int $maxheight Unused; the image is served as is.
     * @return \core\url
     */
    public function get_logo_url($maxwidth = null, $maxheight = 200) {
        return $this->image_url('logo_large', 'theme_vzdc');
    }

    /**
     * The compact logo, shown in the navbar.
     *
     * @param int $maxwidth Unused; the image is served as is.
     * @param int $maxheight Unused; the image is served as is.
     * @return \core\url
     */
    public function get_compact_logo_url($maxwidth = 300, $maxheight = 300) {
        return $this->image_url('logo', 'theme_vzdc');
    }
}
