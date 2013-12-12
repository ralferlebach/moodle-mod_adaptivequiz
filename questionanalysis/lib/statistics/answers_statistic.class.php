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

require_once(dirname(__FILE__).'/question_statistic.interface.php');

/**
 * Questions-statistic interface
 *
 * This interface defines the methods required for pluggable statistics that may be added to the question analysis.
 *
 * This module was created as a collaborative effort between Middlebury College
 * and Remote Learner.
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 Middlebury College {@link http://www.middlebury.edu/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaptivequiz_answers_statistic implements adaptivequiz_question_statistic {

    /**
     * Answer a display-name for this statistic.
     *
     * @return string
     */
    public function get_display_name () {
        return get_string('answers_display_name', 'adaptivequiz');
    }

    /**
     * Calculate this statistic for a question's results
     *
     * @param adaptivequiz_question_analyser $analyser
     * @return adaptivequiz_question_statistic_result
     */
    public function calculate (adaptivequiz_question_analyser $analyser) {
        // Sort the results.
        $results = $analyser->get_results();
        foreach ($results as $result) {
            $sortkeys[] = $result->score->measured_ability_in_logits();
        }
        array_multisort($sortkeys, SORT_NUMERIC, SORT_DESC, $results);

        ob_start();
        foreach ($results as $result) {
            if ($result->correct) {
                // If the user answered correctly,
                // the result is in-range if their measured ability + stderr is >= the question level.
                $ceiling = $result->score->measured_ability_in_logits() + $result->score->standard_error_in_logits();
                $inrange = ($ceiling >= $analyser->get_question_level_in_logits());
            } else {
                // If the user answered incorrectly,
                // the result is in-range if their measured ability - stderr is <= the question level.
                $floor = $result->score->measured_ability_in_logits() - $result->score->standard_error_in_logits();
                $inrange = ($floor <= $analyser->get_question_level_in_logits());
            }
            print "<pre style=\"color: ".(($result->correct) ? "green" : "red")."; ".(($inrange) ? "" : "font-weight: bold;")."\">";
            print "User: ".$result->user->firstname." ".$result->user->lastname."\n";
            print "Result: ".(($result->correct) ? "correct" : "incorrect")."\n";
            print "Person ability (scaled): ".round($result->score->measured_ability_in_scale(), 2)."\n";
            print "STDERR (scaled): ".round($result->score->standard_error_in_scale(), 2)."\n";
            print "</pre>";
        }

        return new adaptivequiz_answers_statistic_result (count($results), ob_get_clean());
    }
}

/**
 * Questions-statistic-result interface
 *
 * This interface defines the methods required for pluggable statistic-results that may be added to the question analysis.
 *
 * This module was created as a collaborative effort between Middlebury College
 * and Remote Learner.
 *
 * @package    mod_adaptivequiz
 * @copyright  2013 Middlebury College {@link http://www.middlebury.edu/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adaptivequiz_answers_statistic_result implements adaptivequiz_question_statistic_result {

    /** @var int $count  */
    protected $count = null;

    /** @var string $printable  */
    protected $printable = null;

    /**
     * Constructor
     *
     * @param int $count
     * @return void
     */
    public function __construct ($count, $printable) {
        $this->count = $count;
        $this->printable = $printable;
    }

    /**
     * A sortable version of the result.
     *
     * @return mixed string or numeric
     */
    public function sortable () {
        return $this->count;
    }

    /**
     * A printable version of the result.
     *
     * @param numeric $result
     * @return mixed string or numeric
     */
    public function printable () {
        return $this->printable;
    }
}