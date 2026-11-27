import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'misc'))
import collab_freeze as f

FREEZE = [{'name': 'freeze'}]


def issue(**fields):
    base = dict(number=7, title='[claude3] Odd PR from flowise', state='open',
                author_association='OWNER', labels=FREEZE)
    base.update(fields)
    return base


class ActiveFreezeTests(unittest.TestCase):
    def test_trusted_open_labelled_issue_freezes(self):
        self.assertEqual(len(f.active_freezes([issue()])), 1)
        self.assertEqual(len(f.active_freezes([issue(author_association='COLLABORATOR')])), 1)

    def test_ignored_issues(self):
        for ignored in (issue(state='closed'), issue(labels=[{'name': 'bug'}]),
                        issue(author_association='NONE'), issue(author_association='CONTRIBUTOR'),
                        issue(pull_request={})):
            self.assertEqual(f.active_freezes([ignored]), [], ignored)

    def test_describe(self):
        self.assertEqual(f.describe([]), 'No collaboration freeze.')
        self.assertIn('#7', f.describe([issue()]))
        self.assertIn('+1 more', f.describe([issue(), issue(number=8)]))


class GuardTests(unittest.TestCase):
    allowed = {'oyst12rsas'}

    def event(self, action, actor, **extra):
        return dict(action=action, sender={'login': actor}, issue=issue(**extra.pop('issue', {})), **extra)

    def test_releaser_may_close_or_unlabel(self):
        self.assertIsNone(f.guard_decision('issues', self.event('closed', 'Oyst12rsas', issue={'state': 'closed'}), self.allowed))
        self.assertIsNone(f.guard_decision('issues', self.event('unlabeled', 'oyst12rsas', label={'name': 'freeze'}), self.allowed))

    def test_other_account_closing_is_reopened(self):
        payload = self.event('closed', 'codex-flowise-bot', issue={'state': 'closed'})
        self.assertEqual(f.guard_decision('issues', payload, self.allowed), 'reopen')

    def test_other_account_removing_label_is_relabelled(self):
        payload = self.event('unlabeled', 'claude-dbserver1', label={'name': 'freeze'}, issue={'labels': []})
        self.assertEqual(f.guard_decision('issues', payload, self.allowed), 'relabel')

    def test_unrelated_events_are_left_alone(self):
        self.assertIsNone(f.guard_decision('issues', self.event('closed', 'x', issue={'labels': [], 'state': 'closed'}), self.allowed))
        self.assertIsNone(f.guard_decision('issues', self.event('unlabeled', 'x', label={'name': 'bug'}), self.allowed))
        self.assertIsNone(f.guard_decision('issues', self.event('opened', 'x'), self.allowed))
        self.assertIsNone(f.guard_decision('pull_request_target', self.event('closed', 'x'), self.allowed))

    def test_releasers_from_environment(self):
        with patch.dict('os.environ', {'FREEZE_RELEASERS': 'Alice, bob ,'}):
            self.assertEqual(f.releasers(), {'alice', 'bob'})


class FakeGitHub(f.GitHub):
    def __init__(self, issues, pulls):
        super().__init__('o/r', 't')
        self.issues, self.pulls, self.calls = issues, pulls, []

    def request(self, method, path, body=None):
        self.calls.append((method, path, body))
        if path.startswith('/issues?'):
            return self.issues
        if path.startswith('/pulls?'):
            return self.pulls
        return {}


class CommandTests(unittest.TestCase):
    def test_status_exit_codes(self):
        self.assertEqual(f.status(FakeGitHub([], [])), f.EXIT_CLEAR)
        self.assertEqual(f.status(FakeGitHub([issue()], [])), f.EXIT_FROZEN)
        broken = FakeGitHub([], [])
        broken.request = lambda *a, **k: (_ for _ in ()).throw(OSError('offline'))
        self.assertEqual(f.status(broken), f.EXIT_UNKNOWN)

    def test_publish_marks_every_open_pull_request(self):
        gh = FakeGitHub([issue()], [{'head': {'sha': 'a' * 40}}, {'head': {'sha': 'b' * 40}}])
        f.publish_statuses(gh)
        posts = [c for c in gh.calls if c[0] == 'POST']
        self.assertEqual([p[1] for p in posts], ['/statuses/' + 'a' * 40, '/statuses/' + 'b' * 40])
        self.assertTrue(all(p[2]['state'] == 'failure' and p[2]['context'] == 'collab-freeze' for p in posts))
        self.assertIn('/issues/7', posts[0][2]['target_url'])

    def test_publish_success_when_clear(self):
        gh = FakeGitHub([], [{'head': {'sha': 'c' * 40}}])
        f.publish_statuses(gh)
        self.assertEqual([c[2]['state'] for c in gh.calls if c[0] == 'POST'], ['success'])

    def test_guard_reopens_and_explains(self):
        payload = dict(action='closed', sender={'login': 'codex-bot'}, issue=issue(state='closed'))
        with tempfile.NamedTemporaryFile('w', suffix='.json', delete=False) as event:
            json.dump(payload, event)
        gh = FakeGitHub([], [])
        with patch.dict('os.environ', {'GITHUB_EVENT_PATH': event.name, 'GITHUB_EVENT_NAME': 'issues',
                                       'FREEZE_RELEASERS': 'oyst12rsas'}):
            f.guard(gh)
        Path(event.name).unlink()
        self.assertEqual(gh.calls[0], ('PATCH', '/issues/7', {'state': 'open'}))
        self.assertEqual(gh.calls[1][1], '/issues/7/comments')
        self.assertIn('codex-bot', gh.calls[1][2]['body'])


if __name__ == '__main__':
    unittest.main()
