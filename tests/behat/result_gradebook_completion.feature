@mod @mod_adaptivequiz @javascript
Feature: Results reach the gradebook and pass/fail completion (issue #14)
  In order to grade an adaptive test like any other activity
  As a teacher
  I need the result of an attempt in the gradebook, a pass score in the units of the result
  source, and "Receive a passing grade" decided on the same grade

  # Minimum below maximum: this line's settings form refuses a fixed length (minimum = maximum).

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

  Scenario: The settings show the result source and what the pass score means in the gradebook
    When I am on the "Adaptive Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    Then I should see "range 4 to 6"
    And I should see "A pass score of 5 is a grade to pass of 50.00 % in the gradebook."

  Scenario: A good result is graded and passes
    Given I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And the "Receive a passing grade" completion condition of "Adaptive Quiz" is displayed as "todo"
    When I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    And I am on the "adaptivequiz1" "Activity" page
    Then the "Receive a grade" completion condition of "Adaptive Quiz" is displayed as "done"
    And the "Receive a passing grade" completion condition of "Adaptive Quiz" is displayed as "done"
    And I am on the "Course 1" "grades > User report > View" page
    And the following should exist in the "user-grade" table:
      | Grade item    | Grade     |
      | Adaptive Quiz | 83.86     |

  Scenario: A poor result is graded and fails; a lower pass score in the settings lets it pass
    Given I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    When I click on "Start attempt" "link"
    And I click on "False" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "False" "radio" in the "Third question" "question"
    And I press "Submit answer"
    And I am on the "adaptivequiz1" "Activity" page
    Then the "Receive a grade" completion condition of "Adaptive Quiz" is displayed as "done"
    And the "Receive a passing grade" completion condition of "Adaptive Quiz" is displayed as "failed"
    And I am on the "Course 1" "grades > User report > View" page
    And the following should exist in the "user-grade" table:
      | Grade item    | Grade     |
      | Adaptive Quiz | 16.14     |
    And I log out
    # The new grade to pass is applied to the results that exist, without a new attempt.
    And I am on the "Adaptive Quiz" "adaptivequiz activity editing" page logged in as teacher1
    And I expand all fieldsets
    And I set the field "Pass score" to "4.2"
    And I press "Save and display"
    And I log out
    And I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And the "Receive a passing grade" completion condition of "Adaptive Quiz" is displayed as "done"
