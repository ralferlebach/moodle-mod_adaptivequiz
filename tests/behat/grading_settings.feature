@mod @mod_adaptivequiz @javascript
Feature: Grading settings of the activity (issue #25)
  In order to decide whether and how an adaptive test is graded
  As a teacher
  I need to switch grading off, choose the link function and see settings refused that cannot give a grade

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email                       |
      | teacher1 | John      | The Teacher | johntheteacher@example.com  |
      | student1 | Peter     | The Student | peterthestudent@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | qbank    | Qbank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name                    |
      | Activity module | qbank1    | Adaptive Quiz Questions |
    And the following "questions" exist:
      | questioncategory        | qtype     | name | questiontext    | answer |
      | Adaptive Quiz Questions | truefalse | TF1  | First question  | True   |
      | Adaptive Quiz Questions | truefalse | TF2  | Second question | True   |
      | Adaptive Quiz Questions | truefalse | TF3  | Third question  | True   |
    And the following "core_question > Tags" exist:
      | question | tag    |
      | TF1      | adpq_5 |
      | TF2      | adpq_6 |
      | TF3      | adpq_4 |
    And the following "activity" exists:
      | activity            | adaptivequiz            |
      | idnumber            | adaptivequiz1           |
      | course              | C1                      |
      | name                | Adaptive Quiz           |
      | startinglevel       | 5                       |
      | lowestlevel         | 4                       |
      | highestlevel        | 6                       |
      | minimumquestions    | 2                       |
      | maximumquestions    | 2                       |
      | standarderror       | 5                       |
      | questionpoolnamed   | Adaptive Quiz Questions |
      | passscore           | 5                       |
      | completion          | 2                       |
      | completionusegrade  | 1                       |
      | completionpassgrade | 1                       |
    And the following "mod_adaptivequiz > links with question banks" exist:
      | adaptivequiz  | idnumber |
      | Adaptive Quiz | qbank1   |
    And the following "activity" exists:
      | activity          | adaptivequiz            |
      | idnumber          | adaptivequiz2           |
      | course            | C1                      |
      | name              | Plain Quiz              |
      | startinglevel     | 5                       |
      | lowestlevel       | 4                       |
      | highestlevel      | 6                       |
      | minimumquestions  | 2                       |
      | maximumquestions  | 2                       |
      | standarderror     | 5                       |
      | questionpoolnamed | Adaptive Quiz Questions |
    And the following "mod_adaptivequiz > links with question banks" exist:
      | adaptivequiz | idnumber |
      | Plain Quiz   | qbank1   |

  Scenario: A link function that needs a pass score is refused without one, and with one at the end of the range
    Given I am on the "Plain Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    When I set the field "Link function" to "Piecewise linear, pass score at 50 %"
    And I press "Save and display"
    Then I should see "This link function needs a pass score."
    When I set the field "Pass score" to "6"
    And I press "Save and display"
    Then I should see "For this link function the pass score must lie strictly inside the result range."
    When I set the field "Pass score" to "5"
    And I press "Save and display"
    Then I should not see "This link function needs a pass score."
    And I should not see "must lie strictly inside the result range"

  Scenario: The steepness is only offered for the logistic link functions
    Given I am on the "Plain Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    Then "Steepness k" "field" should not be visible
    When I set the field "Link function" to "Logistic, 50 % in the middle of the range"
    Then "Steepness k" "field" should be visible
    When I set the field "Steepness k" to "0"
    And I press "Save and display"
    Then I should see "The steepness must be a number above 0."

  Scenario: No grading is refused while completion asks for a passing grade
    Given I am on the "Adaptive Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    When I set the field "Grading" to "No grading"
    Then "Link function" "field" should not be visible
    And "Pass score" "field" should not be visible
    And I press "Save and display"
    And I should see "Without grading there is no grade to receive or to pass."

  Scenario: No grading leaves the gradebook without the activity, graded again it is back
    Given I am on the "Plain Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    When I set the field "Grading" to "No grading"
    And I press "Save and return to course"
    And I am on the "Course 1" "grades > Grader report > View" page
    Then I should not see "Plain Quiz" in the "#user-grades" "css_element"
    And I am on the "Plain Quiz" "adaptivequiz activity editing" page
    And I expand all fieldsets
    And I set the field "Grading" to "Graded"
    And I press "Save and return to course"
    And I am on the "Course 1" "grades > Grader report > View" page
    And I should see "Plain Quiz" in the "#user-grades" "css_element"
