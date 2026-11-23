#!/usr/bin/env python3
"""Self-test of ci_evidence.py, issue #176 (re-audit of 20.11.2026).

A required Behat feature counts only when the run executed and passed each of its scenarios (each example row of an
outline): a correctly tagged feature the run did not select must turn the evidence red. Run with
    python3 .github/scripts/ci_evidence_selftest.py
"""

import json
import os
import subprocess
import sys
import tempfile
import unittest

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
import ci_evidence  # noqa: E402

FEATURE = """@local_catquiz @javascript
Feature: Two scenarios and an outline

  Scenario: First
    Given I log in as "admin"

  Scenario: Second
    Given I log in as "admin"

  Scenario Outline: Outline
    Given I log in as "<user>"

    Examples:
      | user    |
      | admin   |
      | teacher |
"""
# The lines a run reports: the two scenarios and the two example rows.
RUNS = [4, 7, 15, 16]


class check_features_test(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.mkdtemp()
        with open(os.path.join(self.dir, 'catquiz_sample.feature'), 'w', encoding='utf-8') as f:
            f.write(FEATURE)

    def check(self, executed):
        return ci_evidence.check_features(['catquiz_sample'], self.dir, '@local_catquiz', executed)

    @staticmethod
    def record(line, result='passed', feature='catquiz_sample.feature'):
        return {'feature': feature, 'line': line, 'title': 'x', 'result': result}

    def test_the_lines_a_run_reports(self):
        self.assertEqual(RUNS, ci_evidence.expected_runs(FEATURE))

    def test_green_when_every_scenario_ran_and_passed(self):
        rows, problems = self.check([self.record(line) for line in RUNS])
        self.assertEqual([], problems)
        self.assertTrue(rows[0]['ok'])
        self.assertEqual(4, rows[0]['passed'])

    def test_red_when_the_feature_did_not_run(self):
        # The gap of #176: present, tagged, not skipped - and not selected by the run.
        rows, problems = self.check([self.record(4, feature='catquiz_other.feature')])
        self.assertFalse(rows[0]['ok'])
        self.assertEqual(1, len(problems))
        self.assertIn('ran 0 of 4', problems[0])

    def test_red_when_one_example_row_did_not_run(self):
        rows, problems = self.check([self.record(line) for line in RUNS[:-1]])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('not run: lines 16', problems[0])

    def test_red_when_a_scenario_failed_in_its_last_run(self):
        rows, problems = self.check([self.record(line) for line in RUNS[:-1]] + [self.record(16, 'failed')])
        self.assertIn('not passed: lines 16 (failed)', problems[0])

    def test_a_rerun_that_passed_counts(self):
        records = [self.record(line) for line in RUNS[:-1]] + [self.record(16, 'failed'), self.record(16, 'passed')]
        rows, problems = self.check(records)
        self.assertEqual([], problems)
        self.assertEqual(1, rows[0]['reruns'])

    def test_without_the_record_only_the_file_is_checked(self):
        rows, problems = self.check(None)
        self.assertEqual([], problems)
        self.assertNotIn('ran', rows[0]['note'])

    # Re-audit of 22.11.2026, #176 DoD3: the edge cases.

    def test_red_when_the_run_was_aborted_within_the_feature(self):
        # The run ended after the second scenario: the rest has no record.
        rows, problems = self.check([self.record(line) for line in RUNS[:2]])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('ran 2 of 4', problems[0])
        self.assertIn('not run: lines 15, 16', problems[0])

    def test_red_when_a_required_scenario_was_skipped(self):
        # A scenario that skips itself (a missing plugin) did not show what it is required for.
        rows, problems = self.check([self.record(line) for line in RUNS[:-1]] + [self.record(16, 'skipped')])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('not passed: lines 16 (skipped)', problems[0])

    def test_scenarios_of_the_same_name_are_told_apart_by_their_line(self):
        feature = FEATURE.replace('Scenario: Second', 'Scenario: First')
        with open(os.path.join(self.dir, 'catquiz_sample.feature'), 'w', encoding='utf-8') as f:
            f.write(feature)
        self.assertEqual(RUNS, ci_evidence.expected_runs(feature))
        # Only the first of the two "First" ran: the record of a title does not stand for its namesake.
        rows, problems = self.check([self.record(line) for line in (4, 15, 16)])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('not run: lines 7', problems[0])

    def test_red_when_the_record_is_of_another_state_of_the_feature(self):
        # A record from another branch, whose feature has an extra line on top: no line matches this one.
        rows, problems = self.check([self.record(line + 1) for line in RUNS])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('ran 1 of 4', problems[0])
        self.assertIn('no scenario of this file: 5, 8, 17', problems[0])

    def test_red_when_the_record_holds_more_than_this_state_of_the_feature(self):
        # Every scenario ran and passed, and the record has one more: it is not of this state of the feature.
        rows, problems = self.check([self.record(line) for line in RUNS] + [self.record(21)])
        self.assertFalse(rows[0]['ok'])
        self.assertIn('no scenario of this file: 21', problems[0])


class main_test(unittest.TestCase):
    def run_evidence(self, executed_lines=None, with_file=True, with_option=True):
        work = tempfile.mkdtemp()
        features = os.path.join(work, 'features')
        os.makedirs(features)
        with open(os.path.join(features, 'catquiz_sample.feature'), 'w', encoding='utf-8') as f:
            f.write(FEATURE)
        required = os.path.join(work, 'required.txt')
        with open(required, 'w', encoding='utf-8') as f:
            f.write('catquiz_sample   # #176\n')
        log = os.path.join(work, 'behat.log')
        with open(log, 'w', encoding='utf-8') as f:
            f.write('4 scenarios (4 passed)\n')
        executed = os.path.join(work, 'executed.jsonl')
        if with_file:
            with open(executed, 'w', encoding='utf-8') as f:
                for line in executed_lines or []:
                    f.write(json.dumps({'feature': 'catquiz_sample.feature', 'line': line, 'result': 'passed'}) + '\n')
        moodle = os.path.join(work, 'moodle')
        os.makedirs(moodle)
        with open(os.path.join(moodle, 'version.php'), 'w', encoding='utf-8') as f:
            f.write("<?php\n$version = 2025100600.00;\n$release = '5.1';\n")
        env = dict(os.environ)
        env.pop('GITHUB_STEP_SUMMARY', None)
        return subprocess.run(
            [sys.executable, os.path.join(HERE, 'ci_evidence.py'), '--title', 'selftest', '--moodle', moodle,
             '--behat-log', log, '--required-features', required, '--features-dir', features,
             '--tag', '@local_catquiz', '--out', os.path.join(work, 'out')]
            + (['--behat-executed', executed] if with_option else []),
            capture_output=True, text=True, env=env)

    def test_the_job_fails_for_a_required_feature_that_did_not_run(self):
        self.assertEqual(1, self.run_evidence([4, 7]).returncode)

    def test_the_job_fails_without_the_record(self):
        result = self.run_evidence(with_file=False)
        self.assertEqual(1, result.returncode)
        self.assertIn('Record of the executed scenarios', result.stdout)

    def test_the_job_fails_without_the_option_for_the_record(self):
        # Re-audit of 23.11.2026, case B: the required features without --behat-executed are an inventory only.
        result = self.run_evidence(RUNS, with_option=False)
        self.assertEqual(1, result.returncode)
        self.assertIn('without --behat-executed', result.stdout)
        self.assertNotIn('All required tests ran', result.stdout)

    def test_the_job_passes_when_everything_ran(self):
        result = self.run_evidence(RUNS)
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)


if __name__ == '__main__':
    unittest.main(verbosity=2)
