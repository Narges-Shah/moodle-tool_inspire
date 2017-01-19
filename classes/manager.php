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
 * Inspire tool manager.
 *
 * @package   tool_inspire
 * @copyright 2017 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_inspire;

defined('MOODLE_INTERNAL') || die();

/**
 * Inspire tool manager.
 *
 * @package   tool_inspire
 * @copyright 2017 David Monllao {@link http://www.davidmonllao.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class manager {

    /**
     * @var \tool_inspire\predictor[]
     */
    protected static $predictionprocessors = null;

    /**
     * @var \tool_inspire\local\indicator\base[]
     */
    protected static $allindicators = null;

    /**
     * @var \tool_inspire\local\range_processor\base[]
     */
    protected static $allrangeprocessors = null;

    /**
     * Returns the site selected predictions processor.
     *
     * @return \tool_inspire\predictor
     */
    public static function get_predictions_processor($predictionclass = false) {

        if ($predictionclass === false) {
            $predictionclass = get_config('tool_inspire', 'predictionprocessor');
        }

        if (empty($predictionclass)) {
            // Use the default one if nothing set.
            $predictionclass = '\predict_php\processor';
        }

        // Return it from the cached list.
        if (isset(self::$predictionprocessors[$predictionclass])) {
            return self::$predictionprocessors[$predictionclass];
        }

        if (!class_exists($predictionclass)) {
            throw new \coding_exception('Invalid predictions processor ' . $predictionclass . '.');
        }

        $interfaces = class_implements($predictionclass);
        if (empty($interfaces['tool_inspire\predictor'])) {
            throw new \coding_exception($predictionclass . ' should implement \tool_inspire\predictor.');
        }

        self::$predictionprocessors[$predictionclass] = new $predictionclass();

        return self::$predictionprocessors[$predictionclass];
    }

    public static function get_all_prediction_processors() {
        $subplugins = \core_component::get_subplugins('tool_inspire');

        $predictionprocessors = array();
        if (!empty($subplugins['predict'])) {
            foreach ($subplugins['predict'] as $subplugin) {
                $classfullpath = '\\predict_' . $subplugin . '\\processor';
                $predictionprocessors[$classfullpath] = self::get_predictions_processor($classfullpath);
            }
        }
        return $predictionprocessors;
    }

    /**
     * Get all available range processors.
     *
     * @return \tool_inspire\range_processor\base[]
     */
    public static function get_all_range_processors() {
        if (self::$allrangeprocessors !== null) {
            return self::$allrangeprocessors;
        }

        // TODO: It should be able to search range processors in other plugins.
        $classes = \core_component::get_component_classes_in_namespace('tool_inspire', 'local\\range_processor');

        self::$allrangeprocessors = [];
        foreach ($classes as $fullclassname => $classpath) {
            $instance = self::get_range_processor($fullclassname);
            if ($instance) {
                self::$allrangeprocessors[$instance->get_codename()] = $instance;
            }
        }

        return self::$allrangeprocessors;
    }

    /**
     * Returns a range processor by its classname.
     *
     * @param string $fullclassname
     * @return \tool_inspire\local\range_processor\base|false False if it is not valid.
     */
    public static function get_range_processor($fullclassname) {
        if (!self::is_valid($fullclassname, '\tool_inspire\local\range_processor\base')) {
            return false;
        }
        return new $fullclassname();
    }

    /**
     * Return all system indicators.
     *
     * @return \tool_inspire\local\indicator\base[]
     */
    public static function get_all_indicators() {
        if (self::$allindicators !== null) {
            return self::$allindicators;
        }

        self::$allindicators = [];

        $classes = \core_component::get_component_classes_in_namespace('tool_inspire', 'local\\indicator');
        foreach ($classes as $fullclassname => $classpath) {
            $instance = self::get_indicator($fullclassname);
            if ($instance) {
                self::$allindicators[$fullclassname] = $instance;
            }
        }

        return self::$allindicators;
    }

    /**
     * Returns an instance of the provided indicator.
     *
     * @param string $fullclassname
     * @return \tool_inspire\local\indicator\base|false False if it is not valid.
     */
    public static function get_indicator($fullclassname) {
        if (!self::is_valid($fullclassname, 'tool_inspire\local\indicator\base')) {
            return false;
        }
        return new $fullclassname;
    }

    /**
     * Returns whether a range processor is valid or not.
     *
     * @param string $fullclassname
     * @return bool
     */
    protected static function is_valid($fullclassname, $baseclass) {
        if (is_subclass_of($fullclassname, $baseclass)) {
            if ((new \ReflectionClass($fullclassname))->isInstantiable()) {
                return true;
            }
        }
        return false;
    }

}