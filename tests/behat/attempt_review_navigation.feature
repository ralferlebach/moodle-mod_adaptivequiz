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
      | teacher2 | Nora      | Noneditor   | noranoneditor@example.com   |
      | teacher3 | Rita      | Restricted  | ritarestricted@example.com  |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | teacher2 | C1     | teacher        |
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
    And the page should meet accessibility standards with "wcag143" extra tests
    ## With the keyboard alone (issue #22).
    When I follow the "Back to Peter The Student's attempts" link with the keyboard
    Then I should see "individual user attempts report for Peter The Student"
    And "Review attempt" "link" should exist in the "Completed" "table_row"

  @javascript
  Scenario: The course is reached from the review
    Given I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I click on "1" "link" in the "Peter The Student" "table_row"
    And I click on "Review attempt" "link" in the "Completed" "table_row"
    When I follow the "Go to course" link with the keyboard
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

  @javascript
  Scenario: The review of a finished attempt follows the activity: reviewers see it hidden, the participant uses the result page (issue #16)
    ## reviewattempt.php is a report page for reviewers (mod/adaptivequiz:viewreport) and stays bound to the course
    ## module - unlike the participant's own result page (attemptfinished.php), which is reached with the activity
    ## hidden. A reviewer - mod/adaptivequiz:viewreport, by default editing teachers and managers - sees a hidden
    ## activity with moodle/course:viewhiddenactivities, as everywhere in Moodle.
    Given the following "roles" exist:
      | shortname | name     | archetype      |
      | reviewer  | Reviewer | editingteacher |
    And the following "permission overrides" exist:
      | capability                         | permission | role     | contextlevel | reference |
      | moodle/course:viewhiddenactivities | Prohibit   | reviewer | Course       | C1        |
    And the following "course enrolments" exist:
      | user     | course | role     |
      | teacher3 | C1     | reviewer |
    And the adaptivequiz activity "adaptivequiz1" is hidden from participants
    When I log in as "teacher1"
    Then the review page of the last attempt of "student1" is shown to me
    And I log out
    ## A non-editing teacher has no mod/adaptivequiz:viewreport by default (db/access.php): no reviewer.
    When I log in as "teacher2"
    Then the review page of the last attempt of "student1" is refused to me
    And I log out
    ## A reviewer (with viewreport) whose role may not see hidden activities: refused, as for every other page of
    ## the activity.
    When I log in as "teacher3"
    Then the review page of the last attempt of "student1" is refused to me
    And I log out
    ## The participant has no review page - the own result stays reachable through the result page.
    When I log in as "student1"
    Then the review page of the last attempt of "student1" is refused to me
    And I open the result page of my last attempt at "adaptivequiz1"
    And I should see "Adaptive Quiz"
