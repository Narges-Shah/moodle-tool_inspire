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
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_inspire\local\analyser;

defined('MOODLE_INTERNAL') || die();

/**
 *
 * @package   tool_inspire
 * @copyright 2016 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class sitewide extends base {

    protected function get_site() {
        return new \tool_inspire\site();
    }

    public function get_analysable_data($includetarget) {

        // Here there is a single analysable and it is the system.
        $analysable = $this->get_site();

        $return = array();

        list($status, $files, $message) = $this->process_analysable($analysable, $includetarget);

        // Needs to be an array of arrays to match the same interface we have when we deal with multiple analysables per site.
        $return = array(
            'status' => array($analysable->get_id() => $status),
            'files' => array(),
            'messages' => array($analysable->get_id() => $message)
        );

        // Copy to range files as there is just one analysable.
        // TODO Not abstracted as we should ideally directly store it as range-scope file.
        foreach ($files as $rangeprocessorcodename => $file) {

            if ($this->options['evaluation'] === true) {
                // Delete the previous copy. Only when evaluating.
                \tool_inspire\dataset_manager::delete_evaluation_range_file($this->modelid, $rangeprocessorcodename);
            }

            // We use merge but it is just a copy
            // TODO use copy or move if there are performance issues.
            $files[$rangeprocessorcodename] = \tool_inspire\dataset_manager::merge_datasets(array($file), $this->modelid,
                $rangeprocessorcodename, $this->options['evaluation'], $includetarget);
        }

        if ($status === \tool_inspire\model::OK) {
            $return['files'] = $files;
        }

        return $return;
    }
}
