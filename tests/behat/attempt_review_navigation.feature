@mod @mod_adaptivequiz
Feature: Leave the attempt review (issue #22)
  In order to get back from reviewing an attempt, also where Moodle shows no header or breadcrumbs (LTI)
  As a teacher
  I need a way back to the person's attempts and a way to the course on the review page itself

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email                       |
      | teacher1 | John      | The Teacher | johntheteacher@example.com  |
      | student1 | Peter     | The Student | peterthestudent@example.com |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
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
      | questioncategory        | qtype     | name | questiontext    |
      | Adaptive Quiz Questions | truefalse | TF1  | First question  |
      | Adaptive Quiz Questions | truefalse | TF2  | Second question |
    And the following "core_question > Tags" exist:
      | question | tag    |
      | TF1      | adpq_1 |
      | TF2      | adpq_2 |
    And the following "activity" exists:
      | activity          | adaptivequiz            |
      | idnumber          | adaptivequiz1           |
      | course            | C1                      |
      | name              | Adaptive Quiz           |
      | startinglevel     | 1                       |
      | lowestlevel       | 1                       |
      | highestlevel      | 2                       |
      | minimumquestions  | 1                       |
      | maximumquestions  | 2                       |
      | standarderror     | 5                       |
      | questionpoolnamed | Adaptive Quiz Questions |
    And the following "mod_adaptivequiz > links with question banks" exist:
      | adaptivequiz  | idnumber |
      | Adaptive Quiz | qbank1   |
    And I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "button"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    And I press "Continue"
    And I log out

  @javascript @accessibility
  Scenario: Back from the review leads to the person's attempts in this activity
    Given I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I click on "1" "link" in the "Peter The Student" "table_row"
    And I click on "Review attempt" "link" in the "Completed" "table_row"
    And the page should meet accessibility standards
    When I click on "Back to Peter The Student's attempts" "link"
    Then I should see "individual user attempts report for Peter The Student"
    And "Review attempt" "link" should exist in the "Completed" "table_row"

  @javascript
  Scenario: The course is reached from the review
    Given I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I click on "1" "link" in the "Peter The Student" "table_row"
    And I click on "Review attempt" "link" in the "Completed" "table_row"
    When I click on "Go to course" "link"
    Then I should see "Course 1"
    And "Adaptive Quiz" "link" should exist

  @javascript
  Scenario: Without header and breadcrumbs, as in LTI, both ways out remain
    Given I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I click on "1" "link" in the "Peter The Student" "table_row"
    And I click on "Review attempt" "link" in the "Completed" "table_row"
    And the page is shown without header and breadcrumbs
    Then ".breadcrumb" "css_element" should not exist
    And "Go to course" "link" should be visible
    When I click on "Back to Peter The Student's attempts" "link"
    Then I should see "individual user attempts report for Peter The Student"
