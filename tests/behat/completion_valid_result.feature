@mod @mod_adaptivequiz @javascript
Feature: Completion requires a valid CAT result.
  As a teacher, I can require a valid CAT result for activity completion, so that
  a technically completed but invalid attempt does not complete the activity
  (issue #8).

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                      |
      | student1 | Student1  | Test     | toolgenerator1@example.com |
      | teacher  | Teacher   | Test     | toolgenerator3@example.com |
    And the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | student1 | C1     | student        |
      | teacher  | C1     | editingteacher |

  @javascript
  Scenario: A teacher can enable the valid-result completion rule
    Given the following "question categories" exist:
      | contextlevel | reference | name                    |
      | Course       | C1        | Adaptive Quiz Questions |
    And the following "questions" exist:
      | questioncategory        | qtype     | name | questiontext     |
      | Adaptive Quiz Questions | truefalse | TF1  | First question.  |
      | Adaptive Quiz Questions | truefalse | TF2  | Second question. |
    And the following "core_question > Tags" exist:
      | question | tag    |
      | TF1      | adpq_1 |
      | TF2      | adpq_2 |
    And I log in as "teacher"
    # The activity chooser step of Moodle < 4.4 is deprecated; this one opens the form directly.
    And I add a "adaptivequiz" activity to course "Course 1" section "1" and I fill the form with:
      | Name                         | Completion Quiz             |
      | Question pool                | Adaptive Quiz Questions (2) |
      | Starting level of difficulty | 1                           |
      | Lowest level of difficulty   | 1                           |
      | Highest level of difficulty  | 2                           |
      | Minimum number of questions  | 1                           |
      | Maximum number of questions  | 2                           |
      | Standard Error to stop       | 25                          |
      | ID number                    | completionquiz              |
      | Add requirements             | 1                           |
      | completionvalidresult        | 1                           |
    Then I should see "Completion Quiz"
    ## The activity now tracks completion on a valid CAT result. Re-opening the
    ## settings keeps the rule enabled (round-trip through the form).
    When I am on the "completionquiz" "Activity editing" page
    Then the field "completionvalidresult" matches value "1"
