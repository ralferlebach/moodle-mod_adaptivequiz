@mod @mod_adaptivequiz @javascript
Feature: An unexpected end of the attempt is explained before the feedback
  In order to understand why my test ended before I expected it
  As a student
  I need a notice I confirm before I see my feedback (issue #20)

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email                       |
      | student1 | Peter     | The Student | peterthestudent@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "question categories" exist:
      | contextlevel | reference | name                    |
      | Course       | C1        | Adaptive Quiz Questions |
    And the following "questions" exist:
      | questioncategory        | qtype     | name | questiontext    |
      | Adaptive Quiz Questions | truefalse | Q1   | First question  |
      | Adaptive Quiz Questions | truefalse | Q2   | Second question |

  Scenario: No suitable question left: a notice, then the feedback, and no second notice
    ## Questions on levels 1 and 3 only, five allowed: after two right answers there is nothing left to ask.
    Given the following "core_question > Tags" exist:
      | question | tag    |
      | Q1       | adpq_1 |
      | Q2       | adpq_3 |
    And the following "activity" exists:
      | activity          | adaptivequiz            |
      | idnumber          | adaptivequiz1           |
      | course            | C1                      |
      | name              | Adaptive Quiz           |
      | startinglevel     | 1                       |
      | lowestlevel       | 1                       |
      | highestlevel      | 3                       |
      | minimumquestions  | 1                       |
      | maximumquestions  | 5                       |
      | standarderror     | 1                       |
      | questionpoolnamed | Adaptive Quiz Questions |
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    Then I should see "There are no further suitable questions for you at the moment" in the "Your test has ended" "dialogue"
    And "Continue to course" "link" should not be visible
    And I click on "OK" "button" in the "Your test has ended" "dialogue"
    And "Your test has ended" "dialogue" should not exist
    And "Continue to course" "link" should be visible
    And I should see "There are no further suitable questions for you at the moment"
    And I reload the page
    And "Your test has ended" "dialogue" should not exist
    And "Continue to course" "link" should be visible

  Scenario: The maximum number of questions is the regular end: no notice
    Given the following "core_question > Tags" exist:
      | question | tag    |
      | Q1       | adpq_1 |
      | Q2       | adpq_2 |
    And the following "activity" exists:
      | activity          | adaptivequiz            |
      | idnumber          | adaptivequiz1           |
      | course            | C1                      |
      | name              | Adaptive Quiz           |
      | startinglevel     | 1                       |
      | lowestlevel       | 1                       |
      | highestlevel      | 2                       |
      | minimumquestions  | 1                       |
      | maximumquestions  | 1                       |
      | standarderror     | 1                       |
      | questionpoolnamed | Adaptive Quiz Questions |
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    Then "Your test has ended" "dialogue" should not exist
    And "Continue to course" "link" should be visible
