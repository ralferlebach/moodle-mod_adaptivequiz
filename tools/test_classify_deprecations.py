#!/usr/bin/env python3
"""Self-test of classify_deprecations.py (mod_adaptivequiz #13).

An injected plugin deprecation is found, core alone passes; a run that cannot be judged (no Moodle tree,
empty log, no PHPUnit result) is an infrastructure error with its own exit code, never a green canary.
"""

import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))


def run(lines, root, *extra, env=None):
    with tempfile.TemporaryDirectory() as out:
        log = os.path.join(out, 'php.log')
        with open(log, 'w') as fh:
            fh.write('\n'.join(lines) + ('\n' if lines else ''))
        environment = {k: v for k, v in os.environ.items() if k not in ('MOODLE_SHA', 'PHP_VERSION', 'PHPUNIT_EXIT')}
        environment.update(env or {})
        code = subprocess.call([sys.executable, os.path.join(HERE, 'classify_deprecations.py'), log, root, out, *extra],
                               stdout=subprocess.DEVNULL, env=environment)
        with open(os.path.join(out, 'deprecations-summary.json')) as fh:
            raw = fh.read()
        with open(os.path.join(out, 'deprecations-summary.md')) as fh:
            markdown = fh.read()
        with open(os.path.join(out, 'deprecations-unknown.log')) as fh:
            unknown = fh.read()
        return code, json.loads(raw), {'raw': raw, 'md': markdown, 'unknown': unknown}


with tempfile.TemporaryDirectory() as root:
    os.makedirs(os.path.join(root, 'lib'))
    with open(os.path.join(root, 'lib', 'plugins.json'), 'w') as fh:
        json.dump({'standard': {'mod': ['bigbluebuttonbn']}}, fh)
    with open(os.path.join(root, 'lib', 'components.json'), 'w') as fh:
        json.dump({'plugintypes': {'mod': 'public/mod', 'local': 'public/local'}}, fh)
    core = [
        f'PHP Deprecated:  Something in {root}/public/lib/adodb/adodb.inc.php on line 12',
        f'PHP Deprecated:  Other in {root}/public/mod/bigbluebuttonbn/lib.php on line 3',
        f'Deprecated: Third in {root}/public/local/othertool/lib.php:7',
    ]
    plugin = f'PHP Deprecated:  Injected in {root}/public/mod/adaptivequiz/locallib.php on line 99'
    catmodel = f'PHP Deprecated:  Sub in {root}/public/mod/adaptivequiz/catmodel/othermodel/lib.php on line 5'
    passed = ['', 'Tests: 12, Assertions: 30, Deprecations: 3.']

    code, summary, _ = run(core + [catmodel], root)
    assert code == 0, 'Core, dependencies and the CAT model alone do not fail the gate.'
    assert summary['MOODLE_CORE']['total'] == 2 and summary['DEPENDENCY']['total'] == 1, summary
    assert summary['CATMODEL']['total'] == 1 and summary['PLUGIN']['total'] == 0, summary

    phpunit = ['', '1 test triggered 1 deprecation:', '', f'1) {root}/public/mod/adaptivequiz/classes/x.php:4', 'Creation of dynamic property']
    code, summary, _ = run(core + phpunit, root)
    assert code == 1 and summary['PLUGIN']['total'] == 1, summary

    code, summary, _ = run(core + [plugin, plugin], root)
    assert code == 1, 'A deprecation in mod_adaptivequiz fails the gate.'
    assert summary['PLUGIN'] == {'distinct': 1, 'total': 2}, summary

    # Gap 2: a section after the deprecations (warnings, failures) is not read as deprecations.
    after = phpunit + ['', '1 test triggered 1 PHP warning:', '',
                       f'1) {root}/public/mod/adaptivequiz/classes/y.php:8', 'Undefined variable $x',
                       '', 'There was 1 failure:', '',
                       f'1) {root}/public/mod/adaptivequiz/tests/z_test.php:12', 'Failed asserting that false is true.']
    code, summary, _ = run(after, root)
    assert code == 1 and summary['PLUGIN'] == {'distinct': 1, 'total': 1}, summary
    warningsonly = ['', '1 test triggered 1 PHP warning:', '',
                    f'1) {root}/public/mod/adaptivequiz/classes/y.php:8', 'Undefined variable $x']
    code, summary, _ = run(core + warningsonly, root)
    assert code == 0 and summary['PLUGIN']['total'] == 0, 'A warning is no deprecation: ' + str(summary)
    # A second deprecation section after another one is read again.
    code, summary, _ = run(warningsonly + phpunit, root)
    assert code == 1 and summary['PLUGIN']['total'] == 1, summary

    # Gap 3: a notice without a path is counted as UNKNOWN instead of being lost.
    nopath = ['PHP Deprecated:  Constant FOO is deprecated',
              '', '1 test triggered 1 deprecation:', '', '1) Method bar() is deprecated', 'since 8.5']
    code, summary, files = run(nopath, root)
    assert code == 0 and summary['UNKNOWN'] == {'distinct': 2, 'total': 2}, summary
    assert '(no path):0: Constant FOO is deprecated' in files['unknown'], files['unknown']
    assert 'Method bar() is deprecated since 8.5' in files['unknown'], files['unknown']
    # The message line of a listed deprecation is not counted a second time.
    code, summary, _ = run(['1 test triggered 1 deprecation:', '', f'1) {root}/public/lib/a.php:1', 'Deprecated: x'], root)
    assert summary['MOODLE_CORE']['total'] == 1 and summary['UNKNOWN']['total'] == 0, summary

    # Gap 4: the neutral test CAT model is host code - it gates.
    testcatmodel = f'PHP Deprecated:  T in {root}/public/mod/adaptivequiz/catmodel/testcatmodel/lib.php on line 2'
    code, summary, _ = run([testcatmodel], root)
    assert code == 1 and summary['PLUGIN']['total'] == 1 and summary['CATMODEL']['total'] == 0, summary

    # Gap 5: libraries bundled below mod/adaptivequiz are a dependency, not core and not the plugin.
    vendor = [f'PHP Deprecated:  V in {root}/public/mod/adaptivequiz/vendor/lib/x.php on line 1',
              f'PHP Deprecated:  W in {root}/public/mod/adaptivequiz/catmodel/othermodel/vendor/y.php on line 1']
    code, summary, _ = run(vendor, root)
    assert code == 0 and summary['DEPENDENCY']['total'] == 2, summary
    assert summary['MOODLE_CORE']['total'] == 0 and summary['CATMODEL']['total'] == 0, summary

    # Gap 1: --expect-phpunit - a log without a PHPUnit result is an infrastructure error (3), not green.
    code, summary, files = run(core, root, '--expect-phpunit')
    assert code == 3 and summary['gate']['infrastructure'] == 'FEHLER', summary
    assert 'Infrastruktur: FEHLER' in files['md'], files['md']
    code, summary, _ = run(core + passed, root, '--expect-phpunit')
    assert code == 0 and summary['meta']['tests_run'] == 12, summary
    code, summary, _ = run(core + ['', 'OK (7 tests, 9 assertions)'], root, '--expect-phpunit')
    assert code == 0 and summary['meta']['tests_run'] == 7, summary
    code, summary, _ = run(core + ['No tests executed!'], root, '--expect-phpunit')
    assert code == 3, summary
    code, summary, _ = run(core + passed, root, '--expect-phpunit', '--phpunit-exit', '255')
    assert code == 3 and summary['meta']['phpunit_exit'] == 255, 'A crashed PHPUnit is no result: ' + str(summary)
    code, summary, _ = run(core + passed, root, '--expect-phpunit', '--phpunit-exit', '1')
    assert code == 0, 'Failing tests are not the measure of the canary: ' + str(summary)
    # Infrastructure wins over the gate, but the plugin finding stays visible.
    code, summary, _ = run([plugin], root, '--expect-phpunit')
    assert code == 3 and summary['gate']['plugin'] == 1 and summary['gate']['result'] == 'FAIL', summary

    # Empty log - always an infrastructure error.
    code, summary, _ = run([], root)
    assert code == 3 and 'log is empty' in summary['gate']['problems'], summary

    # Gap 6: reproducible output - Moodle SHA, PHP version and PHPUnit exit in meta, sorted keys, gate line.
    code, summary, files = run(core + passed + [plugin], root, '--expect-phpunit', '--moodle-sha', 'abc123',
                               env={'PHP_VERSION': '8.5.0', 'PHPUNIT_EXIT': '1'})
    assert summary['meta'] == {'moodle_sha': 'abc123', 'php_version': '8.5.0', 'phpunit_exit': 1,
                               'tests_run': 12, 'expect_phpunit': True}, summary['meta']
    assert files['raw'] == json.dumps(summary, indent=2, sort_keys=True) + '\n', 'Keys are sorted.'
    assert files['md'].startswith('Moodle `abc123` · PHP 8.5.0 · PHPUnit-Exit 1 · Tests 12'), files['md']
    assert '**Gate: PLUGIN=1 → FAIL; Infrastruktur: OK**' in files['md'], files['md']
    code, summary, files = run(core + passed, root, '--expect-phpunit')
    assert '**Gate: PLUGIN=0 → PASS; Infrastruktur: OK**' in files['md'], files['md']

# A missing Moodle tree (no lib/components.json) cannot be judged.
with tempfile.TemporaryDirectory() as emptyroot:
    code, summary, _ = run(['Tests: 1, Assertions: 1.', f'PHP Deprecated:  X in {emptyroot}/lib/a.php on line 1'], emptyroot)
    assert code == 3 and any('components.json' in p for p in summary['gate']['problems']), summary
code, summary, _ = run(['Tests: 1, Assertions: 1.'], '/nonexistent/moodle')
assert code == 3, summary

print('classify_deprecations: self-test passed')
