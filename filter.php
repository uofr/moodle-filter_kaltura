<?php

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
 * Automatic media embedding filter class.
 *
 * @package    Filter
 * @subpackage Kaltura
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

//require_once($CFG->libdir.'/filelib.php');
require_once($CFG->dirroot . '/local/kaltura/locallib.php');

class filter_kaltura extends moodle_text_filter {

    // Static class variables are used to generate the same
    // user session string for all videos displayed on the page
    public static $videos    = array();
    public static $k_session = '';

    public static $player = '';
    public static $courseid = 0; 

    function filter($text, array $options = array()) {
        
        global $CFG, $PAGE, $DB;

        // Clear video list
        self::$videos = array();

        if (!is_string($text) or empty($text)) {
            // non string data can not be filtered anyway
            return $text;
        }
        if (stripos($text, '</a>') === false) {
            // performance shortcut - all regexes bellow end with the </a> tag,
            // if not present nothing can match
            return $text;
        }

        $newtext = $text; // we need to return the original value if regex fails!

        if (!empty($CFG->filter_kaltura_enable)) {

            // Get the filter player ui conf id
            self::$player = get_player_uiconf('player_filter');
        
            // Get the course id of the current context
            self::$courseid = get_courseid_from_context($PAGE->context);

            if (has_mobile_flavor_enabled() && get_enable_html5()) {
                $uiconf_id = get_player_uiconf('player_filter');
                $url = new moodle_url(htm5_javascript_url($uiconf_id));
                $PAGE->requires->js($url, false);
                $url = new moodle_url('/local/kaltura/js/frameapi.js');
                $PAGE->requires->js($url, false);

            }

            $uri = get_host();
            $uri = rtrim($uri, '/');
            $uri = str_replace(array('.', '/', 'https'), array('\.', '\/', 'https?'), $uri);

            $search = '/<a\s[^>]*href="('.$uri.')\/index\.php\/kwidget\/wid\/_([0-9]+)\/uiconf_id\/([0-9]+)\/entry_id\/([\d]+_([a-z0-9]+))\/v\/flash"[^>]*>([^>]*)<\/a>/is';

            // Update the static array of videos
            preg_replace_callback($search, 'update_video_list', $newtext);

            try {
                
                // Create the the session for viewing of each video detected
                self::$k_session = generate_kaltura_session(self::$videos);
    
                $kaltura = new kaltura_connection();
                $connection = $kaltura->get_connection(true, 86400);

                // Check if the repository plug-in exists.  Add Kaltura video to 
                // the Kaltura category
                $enabled  = kaltura_repository_enabled();
                $category = false;

                if ($enabled) {
                    require_once($CFG->dirroot.'/repository/kaltura/locallib.php');

                   // Create the course category
                   add_video_course_reference($connection, self::$courseid, self::$videos);

                }

                $newtext = preg_replace_callback($search, 'filter_kaltura_callback', $newtext);

            } catch (Exception $exp) {
                add_to_log(self::$courseid, 'filter_kaltura', 'Error embedding video', '', $exp->getMessage());
            }
        }

        if (empty($newtext) or $newtext === $text) {
            // error or not filtered
            unset($newtext);
            return $text;
        }

        return $newtext;

    }
}

/**
 * This functions adds the video entry id to a static array
 */
function update_video_list($link) {

    filter_kaltura::$videos[] = $link[4];
}

/**
 * Change links to YouTube into embedded YouTube videos
 *
 * Note: resizing via url is not supported, user can click the fullscreen button instead
 *
 * @param  $link
 * @return string
 */
function filter_kaltura_callback($link) {
    global $CFG, $PAGE;

    $entry_obj = get_ready_entry_object($link[4], false);

    if (empty($entry_obj)) {
        return get_string('unable', 'filter_kaltura');
    }

    $config = get_config(KALTURA_PLUGIN_NAME);

    $width  = isset($config->filter_player_width) ? $config->filter_player_width : 0;
    $height = isset($config->filter_player_height) ? $config->filter_player_height : 0;

    // Set the embedded player width and height
    $entry_obj->width  = empty($width) ? $entry_obj->width : $width;
    $entry_obj->height = empty($height) ? $entry_obj->height : $height;

    // Generate player markup
    $markup  = get_kdp_code($entry_obj, filter_kaltura::$player, filter_kaltura::$courseid, filter_kaltura::$k_session);

return <<<OET
$markup
OET;
}