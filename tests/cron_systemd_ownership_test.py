import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

REPO = Path(__file__).parents[1]


class CronOwnershipTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which('perl'), 'Perl is required')
    def test_legacy_start_only_without_systemd_ownership(self):
        for filename in ('crontasks.pl', 'startup.pl'):
            source = (REPO / 'misc' / filename).read_text()
            guard = re.search(r'my \$systemdTaralink = .*?startTaraSystemsOk\(\) unless \$systemdTaralink;', source, re.S).group()
            paths = re.findall(r'"(/[^"\n]+)"', guard)
            self.assertEqual(len(paths), 5)
            for present in [None, *paths]:
                with self.subTest(present=present), tempfile.TemporaryDirectory() as folder:
                    root = Path(folder)
                    script = guard
                    for path in paths:
                        script = script.replace('"'+path+'"', '"'+str(root / path.lstrip('/'))+'"')
                    if present:
                        target = root / present.lstrip('/')
                        target.parent.mkdir(parents=True)
                        target.touch()
                    # Execute only the extracted guard, never the actual cron task.
                    result = subprocess.run(['perl', '-e', 'sub startTaraSystemsOk { print "LEGACY"; }\n'+script],
                                            capture_output=True, text=True, check=True)
                    self.assertEqual(result.stdout, '' if present else 'LEGACY')

    def test_firewall_is_executable(self):
        self.assertTrue(os.access(REPO / 'misc/firewall.sh', os.X_OK))


if __name__ == '__main__':
    unittest.main()
