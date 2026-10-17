#!/usr/bin/env python3
"""Sorts PHP deprecation notices by where they come from (mod_adaptivequiz #13).

A PHP 8.5 run against Moodle 5.x reports many deprecations; most are in Moodle core and its bundled
libraries. Only those in this plugin are ours to fix. Every notice is recorded with its file, classified
by origin and written to separate logs; the summary gives the count per origin.

    PLUGIN       mod/adaptivequiz/ outside catmodel/ (and outside vendor/)
    CATMODEL     mod/adaptivequiz/catmodel/
    MOODLE_CORE  everything of the Moodle tree that is not an added plugin: lib/, admin/, ...,
                 standard plugins (lib/plugins.json), bundled libraries
    DEPENDENCY   other plugins that are not part of standard Moodle
    UNKNOWN      a path outside the Moodle tree, or none

Usage: classify_deprecations.py <log> <moodle root> <output dir>
Exit code 1 when PLUGIN > 0 - the gate; core findings alone never fail.
"""

import json
import os
import re
import sys

# PHPUnit 10+ lists deprecations it caught as "N) /path/file.php:LINE" with the message on the next line.
PHPUNIT_ITEM = re.compile(r'^\d+\) (?P<file>/\S+?\.php):(?P<line>\d+)\s*$')
NOTICE = re.compile(r'(?:PHP )?Deprecated:\s*(?P<message>.*?)\s+in\s+(?P<file>/\S+?)(?::(?P<line1>\d+)| on line (?P<line2>\d+))')
ORIGINS = ['PLUGIN', 'CATMODEL', 'MOODLE_CORE', 'DEPENDENCY', 'UNKNOWN']


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
    root = os.path.realpath(moodleroot).rstrip('/') + '/'
    real = os.path.realpath(path)
    if not real.startswith(root):
        return 'UNKNOWN'
    relative = real[len(root):]
    if relative.startswith('public/'):
        relative = relative[len('public/'):]
    if relative.startswith('mod/adaptivequiz/catmodel/'):
        return 'CATMODEL'
    if relative.startswith('mod/adaptivequiz/'):
        return 'MOODLE_CORE' if '/vendor/' in relative else 'PLUGIN'
    if any(relative.startswith(d) for d in standard):
        return 'MOODLE_CORE'
    # A plugin directory that is not a standard plugin: something installed next to Moodle.
    for typedir in typedirs:
        if relative.startswith(typedir) and relative.count('/') > typedir.count('/'):
            return 'DEPENDENCY'
    return 'MOODLE_CORE'


def main(logfile: str, moodleroot: str, outdir: str) -> int:
    standard = standard_plugin_dirs(moodleroot)
    typedirs = list(plugin_type_dirs(moodleroot).values())
    found = {origin: {} for origin in ORIGINS}
    def record(path: str, lineno: str, message: str) -> None:
        origin = classify(path, moodleroot, standard, typedirs)
        key = f'{path}:{lineno}: {message.strip()}'
        found[origin][key] = found[origin].get(key, 0) + 1

    with open(logfile, encoding='utf-8', errors='replace') as fh:
        lines = fh.read().splitlines()
    # PHPUnit's own list is only read inside its deprecation section.
    insection = False
    for i, line in enumerate(lines):
        if re.match(r'^\d+ tests? triggered \d+ (PHP )?deprecations?:|^There (was|were) \d+ deprecations?:', line, re.I):
            insection = True
            continue
        match = NOTICE.search(line)
        if match:
            record(match.group('file'), match.group('line1') or match.group('line2') or '0', match.group('message'))
            continue
        item = PHPUNIT_ITEM.match(line) if insection else None
        if item:
            message = lines[i + 1] if i + 1 < len(lines) else ''
            record(item.group('file'), item.group('line'), message)

    os.makedirs(outdir, exist_ok=True)
    for origin in ORIGINS:
        with open(os.path.join(outdir, f'deprecations-{origin.lower()}.log'), 'w', encoding='utf-8') as fh:
            for key, count in sorted(found[origin].items()):
                fh.write(f'{count}x {key}\n')
    summary = {origin: {'distinct': len(found[origin]), 'total': sum(found[origin].values())} for origin in ORIGINS}
    with open(os.path.join(outdir, 'deprecations-summary.json'), 'w', encoding='utf-8') as fh:
        json.dump(summary, fh, indent=2)
    lines = ['| Origin | Distinct | Total |', '|---|---|---|']
    lines += [f'| {o} | {summary[o]["distinct"]} | {summary[o]["total"]} |' for o in ORIGINS]
    if found['PLUGIN']:
        lines += ['', '**PLUGIN DEPRECATION** - in mod_adaptivequiz itself:', '']
        lines += [f'- `{key}`' for key in sorted(found['PLUGIN'])]
    text = '\n'.join(lines) + '\n'
    with open(os.path.join(outdir, 'deprecations-summary.md'), 'w', encoding='utf-8') as fh:
        fh.write(text)
    print(text)
    for key in sorted(found['PLUGIN']):
        print(f'::error::PLUGIN DEPRECATION {key}')
    return 1 if found['PLUGIN'] else 0


if __name__ == '__main__':
    if len(sys.argv) != 4:
        print(__doc__)
        sys.exit(2)
    sys.exit(main(sys.argv[1], sys.argv[2], sys.argv[3]))
