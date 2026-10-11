import sys
from pathlib import Path
import tempfile
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
from configure_log_retention import install_defaults

class RetentionTests(unittest.TestCase):
    def test_defaults_preserve_hooks_retention_and_backup_and_are_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'etc/logrotate.d/rsyslog'
            path.parent.mkdir(parents=True)
            source = '/var/log/syslog\n{\n weekly\n rotate 4\n compress\n postrotate\n  /usr/lib/rsyslog/rsyslog-rotate\n endscript\n}\n'
            path.write_text(source)
            self.assertEqual(install_defaults(root), ['journal', 'rsyslog'])
            self.assertEqual(path.read_text(), source.replace('{', '{\n    maxsize 100M'))
            self.assertEqual((root / 'var/lib/tarasec-retention/rsyslog.before-size-limit').read_text(), source)
            self.assertEqual(install_defaults(root), [])

    def test_preserves_explicit_limits_per_block(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / 'etc/logrotate.d/rsyslog'
            path.parent.mkdir(parents=True)
            source = '/var/log/syslog {\n maxsize 50M\n rotate 7\n}\n/var/log/kern.log {\n weekly\n rotate 4\n}\n'
            path.write_text(source)
            self.assertEqual(install_defaults(root, '[Journal]\nSystemMaxUse=1G\n'), ['rsyslog'])
            self.assertIn('maxsize 50M', path.read_text())
            self.assertEqual(path.read_text().count('maxsize 100M'), 1)
            self.assertFalse((root / 'etc/systemd/journald.conf.d').exists())
