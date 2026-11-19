@mod @mod_adaptivequiz @javascript
Feature: A technical error ends the test as an error; a measurement made before it counts on its own (issue #23)
  In order to trust what the result page, the gradebook and the completion say
  As a participant and as a teacher
  I need a technical error to be named as such - never as a regular end - and the validity of the measurement
  decided on its own: a valid measurement is graded and completes, a missing one gives no grade and completes
  nothing, with or without the error.

  Background:
    Given the following "users" exist:
      | username | firstname | lastname    | email                       |
      | student1 | Peter     | The Student | peterthestudent@example.com |
    And the following "courses" exist:
      | fullname | shortname | category | enablecompletion |
      | Course 1 | C1        | 0        | 1                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activity" exists:
      | activity              | adaptivequiz  |
      | idnumber              | adaptivequiz1 |
      | course                | C1            |
      | name                  | Adaptive Quiz |
      | startinglevel         | 5             |
      | lowestlevel           | 4             |
      | highestlevel          | 6             |
      | minimumquestions      | 1             |
      | maximumquestions      | 5             |
      | standarderror         | 5             |
      | passscore             | 5             |
      | completion            | 2             |
      | completionusegrade    | 1             |
      | completionpassgrade   | 1             |
      | completionvalidresult | 1             |

  Scenario: Ended by a technical error after a valid measurement: the error is said, the measurement is graded
    Given "student1" has an attempt at "adaptivequiz1" with measure "1.0" and standard error "0.3" completed with "error"
    Then the last attempt of "student1" at "adaptivequiz1" has the stop code "error" and a valid result
    When I log in as "student1"
    And I open the result page of my last attempt at "adaptivequiz1"
    Then I should see "could not be continued because of a technical problem" in the "Your test was ended by a technical problem" "dialogue"
    And I press the escape key
    And "Your test was ended by a technical problem" "dialogue" should not exist
    And I am on the "adaptivequiz1" "Activity" page
    And the "Receive a grade" completion condition of "Adaptive Quiz" is displayed as "done"
    And the "Receive a passing grade" completion condition of "Adaptive Quiz" is displayed as "done"
    And the "Achieve a valid CAT result" completion condition of "Adaptive Quiz" is displayed as "done"
    And I am on the "Course 1" "grades > User report > View" page
    And the following should exist in the "user-grade" table:
      | Grade item    | Grade |
      | Adaptive Quiz | 73.11 |

  Scenario: Ended by a technical error before any estimate: the error is said, no grade - not 0 -, nothing completed
    Given "student1" has an attempt at "adaptivequiz1" with measure "0" and standard error "0" completed with "error"
    Then the last attempt of "student1" at "adaptivequiz1" has the stop code "error" and no valid result
    When I log in as "student1"
    And I open the result page of my last attempt at "adaptivequiz1"
    Then I should see "could not be continued because of a technical problem" in the "Your test was ended by a technical problem" "dialogue"
    And I press the escape key
    And I am on the "adaptivequiz1" "Activity" page
    And the "Receive a grade" completion condition of "Adaptive Quiz" is displayed as "todo"
    And the "Achieve a valid CAT result" completion condition of "Adaptive Quiz" is displayed as "todo"
    And I am on the "Course 1" "grades > User report > View" page
    And the following should exist in the "user-grade" table:
      | Grade item    | Grade |
      | Adaptive Quiz | -     |

  Scenario: A regular end with a valid measurement is graded the same - the error alone changes no grade
    Given "student1" has an attempt at "adaptivequiz1" with measure "1.0" and standard error "0.3" completed with "maximumquestions"
    Then the last attempt of "student1" at "adaptivequiz1" has the stop code "maximumquestions" and a valid result
    When I log in as "student1"
    And I open the result page of my last attempt at "adaptivequiz1"
    Then "Your test was ended by a technical problem" "dialogue" should not exist
    And I am on the "Course 1" "grades > User report > View" page
    And the following should exist in the "user-grade" table:
      | Grade item    | Grade |
      | Adaptive Quiz | 73.11 |
