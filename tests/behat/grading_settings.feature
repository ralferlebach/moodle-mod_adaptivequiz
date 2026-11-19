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
    And the following "question categories" exist:
      | contextlevel | reference | name                    |
      | Course       | C1        | Adaptive Quiz Questions |
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
      | minimumquestions    | 1                       |
      | maximumquestions    | 2                       |
      | standarderror       | 5                       |
      | questionpoolnamed   | Adaptive Quiz Questions |
      | passscore           | 5                       |
      | completion          | 2                       |
      | completionusegrade  | 1                       |
      | completionpassgrade | 1                       |
    And the following "activity" exists:
      | activity          | adaptivequiz            |
      | idnumber          | adaptivequiz2           |
      | course            | C1                      |
      | name              | Plain Quiz              |
      | startinglevel     | 5                       |
      | lowestlevel       | 4                       |
      | highestlevel      | 6                       |
      | minimumquestions  | 1                       |
      | maximumquestions  | 2                       |
      | standarderror     | 5                       |
      | questionpoolnamed | Adaptive Quiz Questions |

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

  @accessibility
  Scenario Outline: The link function <link> is saved, shown again and kept by a duplicate
    Given I am on the "Plain Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    # The fields of the grading settings; the rest of the core form is not the subject here.
    And the "#fitem_id_linkfunction" "css_element" should meet accessibility standards
    And the "#fitem_id_passscore" "css_element" should meet accessibility standards
    When I set the field "Link function" to "<link>"
    And I set the field "Pass score" to "<pass>"
    And I press "Save and return to course"
    And I am on the "Plain Quiz" "adaptivequiz activity editing" page
    And I expand all fieldsets
    Then the field "Link function" matches value "<link>"
    And the field "Pass score" matches value "<pass>"
    When I am on "Course 1" course homepage with editing mode on
    And I duplicate "Plain Quiz" activity
    And I am on the "Plain Quiz (copy)" "adaptivequiz activity editing" page
    And I expand all fieldsets
    Then the field "Link function" matches value "<link>"
    And the field "Pass score" matches value "<pass>"

    Examples:
      | link                                      | pass |
      | Linear                                    | 5    |
      | Piecewise linear, pass score at 50 %      | 5    |
      | Logistic, 50 % in the middle of the range | 5    |
      | Logistic, 50 % at the pass score          | 5.5  |
      | All or nothing at the pass score          | 4.5  |

  Scenario: Fisher-Rao is refused where the result source offers no test information
    Given I am on the "Plain Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    When I set the field "Link function" to "Fisher-Rao arc length (information of the item battery)"
    And I press "Save and display"
    Then I should see "The result source offers no usable test information for this activity"
