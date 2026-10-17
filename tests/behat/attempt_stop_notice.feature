@mod @mod_adaptivequiz
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
    And the following "activities" exist:
      | activity | name    | course | idnumber |
      | qbank    | Qbank 1 | C1     | qbank1   |
    And the following "question categories" exist:
      | contextlevel    | reference | name                    |
      | Activity module | qbank1    | Adaptive Quiz Questions |
    And the following "questions" exist:
      | questioncategory        | qtype     | name | questiontext    |
      | Adaptive Quiz Questions | truefalse | Q1   | First question  |
      | Adaptive Quiz Questions | truefalse | Q2   | Second question |

  @javascript
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
    And the following "mod_adaptivequiz > links with question banks" exist:
      | adaptivequiz  | idnumber |
      | Adaptive Quiz | qbank1   |
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "button"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    Then I should see "There are no further suitable questions for you at the moment" in the "Your test has ended" "dialogue"
    And "Continue" "button" should not be visible
    And I click on "OK" "button" in the "Your test has ended" "dialogue"
    And "Your test has ended" "dialogue" should not exist
    And "Continue" "button" should be visible
    And I should see "There are no further suitable questions for you at the moment"
    And I reload the page
    And "Your test has ended" "dialogue" should not exist
    And "Continue" "button" should be visible
    ## Neither going back nor opening the result page again brings the dialog back.
    And I press the "back" button in the browser
    And "Your test has ended" "dialogue" should not exist
    And I open the result page of my last attempt at "adaptivequiz1"
    And "Your test has ended" "dialogue" should not exist
    And "Continue" "button" should be visible

  @javascript
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
    And the following "mod_adaptivequiz > links with question banks" exist:
      | adaptivequiz  | idnumber |
      | Adaptive Quiz | qbank1   |
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "button"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    Then "Your test has ended" "dialogue" should not exist
    And "Continue" "button" should be visible

  Scenario: Without JavaScript the reason is on the page and the feedback is reachable
    Given the following "activity" exists:
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
    And "student1" has finished an attempt at "adaptivequiz1" that ended with "noeligiblequestions"
    And I log in as "student1"
    When I open the result page of my last attempt at "adaptivequiz1"
    Then I should see "Your test has ended"
    And I should see "There are no further suitable questions for you at the moment"
    And I should see "You've finished the attempt, thank you for taking the quiz!"
    And "Continue" "button" should exist
