import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "misc"))
from node_coordinator import Schedule, JOBS

class CoordinatorTests(unittest.TestCase):
    def test_separate_cadences_and_no_catchup_storm(self):
        schedule = Schedule()
        calls = []
        schedule.tick(100, calls.append)
        self.assertEqual(set(calls), set(JOBS))
        calls.clear()
        schedule.tick(104, calls.append)
        self.assertEqual(calls, [])
        schedule.tick(105, calls.append)
        self.assertEqual(calls, ["tarasec-ssh-approval-poll.service"])
        calls.clear()
        schedule.tick(1000, calls.append)
        self.assertEqual(len(calls), 4)
        calls.clear()
        schedule.tick(1000, calls.append)
        self.assertEqual(calls, [])

    def test_dispatch_failure_does_not_block_other_jobs(self):
        calls = []
        def launch(unit):
            calls.append(unit)
            if unit == "tarasec-ssh-approval-poll.service":
                raise OSError("unavailable")
        Schedule().tick(1, launch)
        self.assertEqual(set(calls), set(JOBS))
