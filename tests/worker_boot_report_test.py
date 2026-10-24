import importlib.util
from pathlib import Path
import sys
import unittest
from unittest.mock import Mock, patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'misc'))
# Existing node dependencies are supplied by the normal repository checkout.
import agent_approval_worker as worker

class BootReportTest(unittest.TestCase):
    def test_valid_boot_id_survives_repeated_reads(self):
        value='aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa'
        with patch.object(worker,'Path',return_value=Mock(read_text=lambda:value+'\n')):
            self.assertEqual(worker.reported_boot_id(),value)
            self.assertEqual(worker.reported_boot_id(),value)
    def test_missing_or_invalid_id_is_not_invented(self):
        for value in ('','not-a-boot-id'):
            with patch.object(worker,'Path',return_value=Mock(read_text=lambda:value)):
                self.assertIsNone(worker.reported_boot_id())
        with patch.object(worker,'Path',side_effect=OSError):
            self.assertIsNone(worker.reported_boot_id())

if __name__=='__main__': unittest.main()
