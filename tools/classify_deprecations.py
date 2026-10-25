#!/usr/bin/env python3
"""Sorts PHP deprecation notices by where they come from (mod_adaptivequiz #13).

A PHP 8.5 run against Moodle 5.x reports many deprecations; most are in Moodle core and its bundled
libraries. Only those in this plugin are ours to fix. Every notice is recorded with its file, classified
by origin and written to separate logs; the summary gives the count per origin.

    PLUGIN       mod/adaptivequiz/ outside catmodel/ and outside any vendor/ - and the neutral
                 test CAT model catmodel/testcatmodel/, which is host code
    CATMODEL     mod/adaptivequiz/catmodel/ (other than testcatmodel/)
    MOODLE_CORE  everything of the Moodle tree that is not an added plugin: lib/, admin/, ...,
                 standard plugins (lib/plugins.json), bundled libraries
    DEPENDENCY   other plugins that are not part of standard Moodle, and libraries bundled below
                 mod/adaptivequiz/ (any vendor/ directory there)
    UNKNOWN      a path outside the Moodle tree, or a notice without any path

Usage: classify_deprecations.py <log> <moodle root> <output dir>
           [--expect-phpunit] [--moodle-sha SHA] [--php-version V] [--phpunit-exit N]

--moodle-sha, --php-version and --phpunit-exit fall back to the environment variables MOODLE_SHA,
PHP_VERSION and PHPUNIT_EXIT; they only go into the summary, so a run can be traced back to its Moodle
commit.

Exit codes - the two kinds of red are kept apart on purpose:
    0  gate passed: no PLUGIN deprecation, infrastructure fine
    1  gate failed: at least one PLUGIN deprecation (core findings alone never fail)
    2  wrong usage
    3  infrastructure error: the run cannot be judged - empty log, no Moodle tree at <moodle root>
       (lib/components.json missing), or with --expect-phpunit no PHPUnit result in the log
       ("Tests: N" / "OK (N tests") or a PHPUnit exit code other than 0 and 1.
       A canary that never ran its tests must not look green.
"""

import argparse
import json
import os
import re
import sys

# PHPUnit 10+ lists deprecations it caught as "N) /path/file.php:LINE" with the message on the next line.
PHPUNIT_ITEM = re.compile(r'^\d+\) (?P<file>/\S+?\.php):(?P<line>\d+)\s*$')
# Any other numbered entry - inside the deprecation section it is a deprecation without a path.
PHPUNIT_ANY_ITEM = re.compile(r'^\d+\) (?P<text>.+?)\s*$')
NOTICE = re.compile(r'(?:PHP )?Deprecated:\s*(?P<message>.*?)\s+in\s+(?P<file>/\S+?)(?::(?P<line1>\d+)| on line (?P<line2>\d+))')
# The same notice without "in /path" - counted as UNKNOWN rather than lost.
NOTICE_NOPATH = re.compile(r'(?:PHP )?Deprecated:\s*(?P<message>.+?)\s*$')
# Every heading of a PHPUnit result section. Only the deprecation sections are read; any other heading
# (warnings, notices, failures, errors, risky ...) ends them.
SECTION = re.compile(r'^(?:\d+ tests? triggered \d+ (?P<kind1>.+?)|There (?:was|were) \d+ (?P<kind2>.+?)):\s*$', re.I)
DEPRECATION_KIND = re.compile(r'^(?:PHP )?deprecations?$', re.I)
# The closing lines of a PHPUnit run also end any section.
SECTION_END = re.compile(r'^(?:Tests: \d+|OK \(\d+ tests?|OK, but|FAILURES!|ERRORS!|No tests executed)', re.I)
TESTS_RUN = re.compile(r'^(?:Tests: (?P<n1>\d+)|OK \((?P<n2>\d+) tests?)', re.M)
ORIGINS = ['PLUGIN', 'CATMODEL', 'MOODLE_CORE', 'DEPENDENCY', 'UNKNOWN']
HOST_CATMODELS = ['testcatmodel']


def load_json(moodleroot: str, name: str) -> dict:
    """lib/<name> of the Moodle tree - at the root (5.1 and later) or below public/."""
    for candidate in ('lib/' + name, 'public/lib/' + name):
        path = os.path.join(moodleroot, candidate)
        if os.path.isfile(path):
            with open(path, encoding='utf-8') as fh:
                return json.load(fh)
    return {}


def plugin_type_dirs(moodleroot: str) -> dict:
    """Plugin type to its directory, relative to the code root (without public/)."""
    types = load_json(moodleroot, 'components.json').get('plugintypes', {})
    return {t: (d[len('public/'):] if d.startswith('public/') else d).rstrip('/') + '/' for t, d in types.items()}


def standard_plugin_dirs(moodleroot: str) -> list:
    """Directories of the plugins that ship with Moodle, relative to the code root."""
    types = plugin_type_dirs(moodleroot)
    dirs = []
    for plugintype, names in load_json(moodleroot, 'plugins.json').get('standard', {}).items():
        base = types.get(plugintype)
        if base is None:
            continue
        dirs += [base + name + '/' for name in names]
    return dirs


def classify(path: str, moodleroot: str, standard: list, typedirs: list) -> str:
    if not path:
        return 'UNKNOWN'
    root = os.path.realpath(moodleroot).rstrip('/') + '/'
    real = os.path.realpath(path)
    if not real.startswith(root):
        return 'UNKNOWN'
    relative = real[len(root):]
    if relative.startswith('public/'):
        relative = relative[len('public/'):]
    if relative.startswith('mod/adaptivequiz/'):
        inner = relative[len('mod/adaptivequiz/'):]
        # A library bundled with the plugin or a CAT model is not our code either.
        if inner.startswith('vendor/') or '/vendor/' in inner:
            return 'DEPENDENCY'
        if inner.startswith('catmodel/'):
            # The neutral test CAT model ships with the host - its deprecations are ours.
            if any(inner.startswith(f'catmodel/{name}/') for name in HOST_CATMODELS):
                return 'PLUGIN'
            return 'CATMODEL'
        return 'PLUGIN'
    if any(relative.startswith(d) for d in standard):
        return 'MOODLE_CORE'
    # A plugin directory that is not a standard plugin: something installed next to Moodle.
    for typedir in typedirs:
        if relative.startswith(typedir) and relative.count('/') > typedir.count('/'):
            return 'DEPENDENCY'
    return 'MOODLE_CORE'


def scan(lines: list, record) -> None:
    """Feeds every deprecation of the log to record(path, line, message)."""
    # PHPUnit's own list is only read inside its deprecation sections; every other heading ends one.
    insection = False
    consumed = -1
    for i, line in enumerate(lines):
        if i == consumed:
            # The message line of the entry above - already recorded with it.
            continue
        heading = SECTION.match(line)
        if heading:
            insection = bool(DEPRECATION_KIND.match(heading.group('kind1') or heading.group('kind2') or ''))
            continue
        if SECTION_END.match(line):
            insection = False
            continue
        match = NOTICE.search(line)
        if match:
            record(match.group('file'), match.group('line1') or match.group('line2') or '0', match.group('message'))
            continue
        match = NOTICE_NOPATH.search(line)
        if match:
            record('', '0', match.group('message'))
            continue
        if not insection:
            continue
        message = lines[i + 1] if i + 1 < len(lines) else ''
        item = PHPUNIT_ITEM.match(line)
        if item:
            record(item.group('file'), item.group('line'), message)
            consumed = i + 1
            continue
        other = PHPUNIT_ANY_ITEM.match(line)
        if other:
            record('', '0', (other.group('text') + ' ' + message).strip())
            consumed = i + 1


def infrastructure_problems(text: str, moodleroot: str, expectphpunit: bool, phpunitexit) -> list:
    """Reasons why the run cannot be judged; empty when it can."""
    problems = []
    if not text.strip():
        problems.append('log is empty')
    if not load_json(moodleroot, 'components.json').get('plugintypes'):
        problems.append(f'no Moodle tree at {moodleroot} (lib/components.json missing)')
    if expectphpunit:
        if not TESTS_RUN.search(text):
            problems.append('no PHPUnit result in the log ("Tests: N" / "OK (N tests")')
        if phpunitexit is not None and phpunitexit not in (0, 1):
            problems.append(f'PHPUnit exited with {phpunitexit} (neither pass nor test failure)')
    return problems


def tests_run(text: str):
    match = None
    for match in TESTS_RUN.finditer(text):
        pass
    if match is None:
        return None
    return int(match.group('n1') or match.group('n2'))


def optional_int(value):
    if value is None or str(value).strip() == '':
        return None
    try:
        return int(str(value).strip())
    except ValueError:
        return None


def main(argv: list) -> int:
    parser = argparse.ArgumentParser(add_help=False)
    parser.add_argument('log')
    parser.add_argument('moodleroot')
    parser.add_argument('outdir')
    parser.add_argument('--expect-phpunit', action='store_true')
    parser.add_argument('--moodle-sha', default=os.environ.get('MOODLE_SHA'))
    parser.add_argument('--php-version', default=os.environ.get('PHP_VERSION'))
    parser.add_argument('--phpunit-exit', default=os.environ.get('PHPUNIT_EXIT'))
    try:
        args = parser.parse_args(argv)
    except SystemExit:
        print(__doc__)
        return 2
    phpunitexit = optional_int(args.phpunit_exit)

    standard = standard_plugin_dirs(args.moodleroot)
    typedirs = list(plugin_type_dirs(args.moodleroot).values())
    found = {origin: {} for origin in ORIGINS}

    def record(path: str, lineno: str, message: str) -> None:
        origin = classify(path, args.moodleroot, standard, typedirs)
        key = f'{path or "(no path)"}:{lineno}: {message.strip()}'
        found[origin][key] = found[origin].get(key, 0) + 1

    text = ''
    if os.path.isfile(args.log):
        with open(args.log, encoding='utf-8', errors='replace') as fh:
            text = fh.read()
    scan(text.splitlines(), record)
    problems = infrastructure_problems(text, args.moodleroot, args.expect_phpunit, phpunitexit)

    os.makedirs(args.outdir, exist_ok=True)
    for origin in ORIGINS:
        with open(os.path.join(args.outdir, f'deprecations-{origin.lower()}.log'), 'w', encoding='utf-8') as fh:
            for key, count in sorted(found[origin].items()):
                fh.write(f'{count}x {key}\n')
    plugincount = sum(found['PLUGIN'].values())
    summary = {origin: {'distinct': len(found[origin]), 'total': sum(found[origin].values())} for origin in ORIGINS}
    summary['meta'] = {
        'moodle_sha': args.moodle_sha or None,
        'php_version': args.php_version or None,
        'phpunit_exit': phpunitexit,
        'tests_run': tests_run(text),
        'expect_phpunit': args.expect_phpunit,
    }
    summary['gate'] = {
        'plugin': plugincount,
        'result': 'FAIL' if plugincount else 'PASS',
        'infrastructure': 'FEHLER' if problems else 'OK',
        'problems': problems,
    }
    with open(os.path.join(args.outdir, 'deprecations-summary.json'), 'w', encoding='utf-8') as fh:
        json.dump(summary, fh, indent=2, sort_keys=True)
        fh.write('\n')

    meta = summary['meta']
    lines = [
        f'Moodle `{meta["moodle_sha"] or "unbekannt"}` · PHP {meta["php_version"] or "unbekannt"}'
        f' · PHPUnit-Exit {meta["phpunit_exit"] if meta["phpunit_exit"] is not None else "unbekannt"}'
        f' · Tests {meta["tests_run"] if meta["tests_run"] is not None else "keine"}',
        '',
        f'**Gate: PLUGIN={plugincount} → {summary["gate"]["result"]}; '
        f'Infrastruktur: {summary["gate"]["infrastructure"]}**',
        '',
    ]
    if problems:
        lines += ['Der Lauf ist nicht beurteilbar (Exit 3):', '']
        lines += [f'- {p}' for p in problems]
        lines += ['']
    lines += ['| Origin | Distinct | Total |', '|---|---|---|']
    lines += [f'| {o} | {summary[o]["distinct"]} | {summary[o]["total"]} |' for o in ORIGINS]
    if found['PLUGIN']:
        lines += ['', '**PLUGIN DEPRECATION** - in mod_adaptivequiz itself:', '']
        lines += [f'- `{key}`' for key in sorted(found['PLUGIN'])]
    report = '\n'.join(lines) + '\n'
    with open(os.path.join(args.outdir, 'deprecations-summary.md'), 'w', encoding='utf-8') as fh:
        fh.write(report)
    print(report)
    for key in sorted(found['PLUGIN']):
        print(f'::error::PLUGIN DEPRECATION {key}')
    for problem in problems:
        print(f'::error::CANARY INFRASTRUCTURE {problem}')
    # An unjudgeable run wins over the gate: its PLUGIN count may be incomplete.
    if problems:
        return 3
    return 1 if found['PLUGIN'] else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
