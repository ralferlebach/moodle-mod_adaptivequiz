#!/usr/bin/env python3
"""Self-test of classify_deprecations.py: an injected plugin deprecation is found, core alone passes."""

import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))


def run(lines, root):
    with tempfile.TemporaryDirectory() as out:
        log = os.path.join(out, 'php.log')
        with open(log, 'w') as fh:
            fh.write('\n'.join(lines) + '\n')
        code = subprocess.call([sys.executable, os.path.join(HERE, 'classify_deprecations.py'), log, root, out],
                               stdout=subprocess.DEVNULL)
        with open(os.path.join(out, 'deprecations-summary.json')) as fh:
            return code, json.load(fh)


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
    catmodel = f'PHP Deprecated:  Sub in {root}/public/mod/adaptivequiz/catmodel/catquiz/lib.php on line 5'

    code, summary = run(core + [catmodel], root)
    assert code == 0, 'Core, dependencies and the CAT model alone do not fail the gate.'
    assert summary['MOODLE_CORE']['total'] == 2 and summary['DEPENDENCY']['total'] == 1, summary
    assert summary['CATMODEL']['total'] == 1 and summary['PLUGIN']['total'] == 0, summary

    phpunit = ['', '1 test triggered 1 deprecation:', '', f'1) {root}/public/mod/adaptivequiz/classes/x.php:4', 'Creation of dynamic property']
    code, summary = run(core + phpunit, root)
    assert code == 1 and summary['PLUGIN']['total'] == 1, summary

    code, summary = run(core + [plugin, plugin], root)
    assert code == 1, 'A deprecation in mod_adaptivequiz fails the gate.'
    assert summary['PLUGIN'] == {'distinct': 1, 'total': 2}, summary
print('classify_deprecations: self-test passed')
