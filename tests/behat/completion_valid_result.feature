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
    Given I am on the "Course 1" course page logged in as teacher
    And I turn editing mode on
    And I add a "Adaptive Quiz" to section "1"
    And I set the following fields to these values:
      | Name                     | Completion Quiz |
      | Completion tracking      | Show activity as complete when conditions are met |
      | completionvalidresult    | 1               |
    And I press "Save and return to course"
    Then I should see "Completion Quiz"
    ## The activity now tracks completion on a valid CAT result. Re-opening the
    ## settings keeps the rule enabled (round-trip through the form).
    When I open "Completion Quiz" actions menu
    And I choose "Settings" in the open action menu
    And I wait until the page is ready
    Then the field "completionvalidresult" matches value "1"
