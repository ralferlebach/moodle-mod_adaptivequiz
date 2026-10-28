@mod @mod_adaptivequiz @mod_adaptivequiz_attempt_feedback
Feature: The attempt feedback keeps what a trusted author wrote and runs nothing an untrusted one did (issue #21)
  The custom attempt feedback is stored as written and shown without cleaning only when its author holds
  mod/adaptivequiz:trustedfeedback (RISK_XSS; by default editing teachers and managers). Every other author's text
  is cleaned when it is saved and when it is shown. The decision is made on the server, a field added to the request
  changes nothing, and $CFG->forceclean still cleans everything.

  Background:
    ## An author may edit the activity like an editing teacher, but is not trusted with active content.
    Given the following "roles" exist:
      | shortname | name   | archetype      |
      | author    | Author | editingteacher |
    And the following "permission overrides" exist:
      | capability                       | permission | role   | contextlevel | reference |
      | mod/adaptivequiz:trustedfeedback | Prohibit   | author | System       |           |
    And the following "users" exist:
      | username | firstname | lastname    | email                       |
      | teacher1 | John      | The Teacher | johntheteacher@example.com  |
      | student1 | Peter     | The Student | peterthestudent@example.com |
      | author1  | Ann       | The Author  | anntheauthor@example.com    |
    And the following "courses" exist:
      | fullname | shortname | category |
      | Course 1 | C1        | 0        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | author1  | C1     | author         |
    And the following "question categories" exist:
      | contextlevel | reference | name                    |
      | Course       | C1        | Adaptive Quiz Questions |
    And the following "questions" exist:
      | questioncategory        | qtype     | name | questiontext    | answer |
      | Adaptive Quiz Questions | truefalse | Q1   | First question  | True   |
      | Adaptive Quiz Questions | truefalse | Q2   | Second question | True   |
    And the following "core_question > Tags" exist:
      | question | tag    |
      | Q1       | adpq_1 |
      | Q2       | adpq_2 |
    And the following "activity" exists:
      | activity           | adaptivequiz            |
      | idnumber           | adaptivequiz1           |
      | course             | C1                      |
      | name               | Adaptive Quiz           |
      | startinglevel      | 1                       |
      | lowestlevel        | 1                       |
      | highestlevel       | 2                       |
      | minimumquestions   | 1                       |
      | maximumquestions   | 2                       |
      | standarderror      | 20                      |
      | questionpoolnamed  | Adaptive Quiz Questions |
      | showabilitymeasure | 1                       |

  @javascript
  Scenario: A trusted author's HTML and script survive edit, save and reload and reach the feedback page
    ## The editor of the site (TinyMCE) - the round trip a teacher makes.
    Given I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Attempt feedback" to multiline:
      """
      <p><a class="fb-link" href="https://moodle.org/">Moodle</a></p>
      <table class="fb-table"><tbody><tr><th>Level</th><td>3</td></tr></tbody></table>
      <div class="fb-data" data-level="3">Level three</div>
      <script>document.body.setAttribute('data-feedback-script', 'ran');</script>
      """
    And I click on "Save and display" "button"
    ## Edit again: everything is still there.
    ## Legacy line: the editor of the attempt feedback takes no files (maxfiles 0, no file area) - files are a v3 feature.
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    Then the attempt feedback in the form contains:
      | <a class="fb-link" href="https://moodle.org/">                          |
      | <table class="fb-table">                                                |
      | data-level="3"                                                          |
      | <script>document.body.setAttribute('data-feedback-script', 'ran');</script> |
    ## Save once more without a change: nothing is lost on the second round.
    And I click on "Save and display" "button"
    And I log out
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    Then "a.fb-link[href='https://moodle.org/']" "css_element" should exist
    And "table.fb-table th" "css_element" should exist
    And "div.fb-data[data-level='3']" "css_element" should exist
    And "body[data-feedback-script='ran']" "css_element" should exist

  @javascript
  Scenario: An untrusted author's script, event handlers and javascript: links never run, also with a forged field
    ## The plain text area sends the markup as typed: what reaches the server is the raw request an attacker would
    ## send, not what an editor in the browser has already filtered.
    Given the following config values are set as admin:
      | texteditors | textarea |
    And I am on the "adaptivequiz1" "Activity" page logged in as "author1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I should see "Scripts and unsafe markup are removed from the feedback when you save it"
    And I set the field "Attempt feedback" to multiline:
      """
      <p class="fb-untrusted">Untrusted feedback</p>
      <script>document.body.setAttribute('data-xss-script', '1');</script>
      <img src="does-not-exist.png" alt="broken" onerror="document.body.setAttribute('data-xss-onerror', '1')">
      <p><a class="fb-js-link" href="javascript:document.body.setAttribute('data-xss-href', '1')">Click</a></p>
      <svg onload="document.body.setAttribute('data-xss-svg', '1')"><circle r="1"></circle></svg>
      <iframe srcdoc="&lt;script&gt;parent.document.body.setAttribute('data-xss-iframe', '1')&lt;/script&gt;"></iframe>
      """
    ## The request is changed in the browser: the stored mark must not follow it.
    And I add the field "attemptfeedbacktrusted" with the value "1" to the form
    And I click on "Save and display" "button"
    And I log out
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    Then I should see "Untrusted feedback"
    And "[data-region='adaptivequiz-attempt-feedback'] .no-overflow script" "css_element" should not exist
    And "#region-main [onerror]" "css_element" should not exist
    And "#region-main [onload]" "css_element" should not exist
    And "#region-main a[href^='javascript']" "css_element" should not exist
    And "#region-main iframe" "css_element" should not exist
    And the stored attempt feedback of "adaptivequiz1" is not trusted
    And I wait "1" seconds
    And "body[data-xss-script]" "css_element" should not exist
    And "body[data-xss-onerror]" "css_element" should not exist
    And "body[data-xss-href]" "css_element" should not exist
    And "body[data-xss-svg]" "css_element" should not exist
    And "body[data-xss-iframe]" "css_element" should not exist

  @javascript
  Scenario: With forceclean the site cleans a trusted author's text as well
    Given the following config values are set as admin:
      | forceclean | 1 |
    And I am on the "adaptivequiz1" "Activity" page logged in as "teacher1"
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    And I set the field "Attempt feedback" to multiline:
      """
      <table class="fb-table"><tbody><tr><th>Level</th><td>3</td></tr></tbody></table>
      <script>document.body.setAttribute('data-feedback-script', 'ran');</script>
      """
    And I click on "Save and display" "button"
    And the stored attempt feedback of "adaptivequiz1" is trusted
    And I log out
    When I am on the "adaptivequiz1" "Activity" page logged in as "student1"
    And I click on "Start attempt" "link"
    And I click on "True" "radio" in the "First question" "question"
    And I press "Submit answer"
    And I click on "True" "radio" in the "Second question" "question"
    And I press "Submit answer"
    Then "table.fb-table th" "css_element" should exist
    And I wait "1" seconds
    And "body[data-feedback-script]" "css_element" should not exist
