"""Exercise the actual getTagData fallback SELECT with SQLite compatibility UDFs.

No database service is needed. Only MySQL's SECOND keyword is quoted; the
selection predicate, ordering and LIMIT are executed directly from taraLib.php.
"""
import re
import sqlite3
import unittest
from pathlib import Path

SOURCE = (Path(__file__).resolve().parents[1] / 'html/taraLib.php').read_text()
QUERY = re.search(r'\$szSQL = "(SELECT reportId, severity, infoSharePartners, why,.*?)";', SOURCE, re.S)[1]
QUERY = QUERY.replace('TIMESTAMPDIFF(SECOND,', "TIMESTAMPDIFF('SECOND',")
NOW = 1000000


class HackReportExpiryTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(':memory:')
        self.db.create_function('NOW', 0, lambda: NOW)
        self.db.create_function('TIMESTAMPDIFF', 3, lambda unit, start, end: None if start is None else end-start)
        self.db.create_function('INET_ATON', 1, lambda ip: ip)
        self.db.create_function('INET_NTOA', 1, lambda ip: ip)
        self.db.execute('''CREATE TABLE hackReport (
            reportId INTEGER PRIMARY KEY, ip TEXT, port INTEGER, severity INTEGER,
            infoSharePartners INTEGER, why TEXT, partnerIp TEXT,
            hrCategory TEXT, lastSeen INTEGER, created INTEGER)''')

    def tearDown(self):
        self.db.close()

    def add(self, age, category='demo', why='test', port=1234, last_seen=True, ip='sender', severity=7):
        self.db.execute('''INSERT INTO hackReport
            (ip,port,severity,why,hrCategory,lastSeen,created)
            VALUES (?,?,?,?,?,?,?)''',
            (ip, port, severity, why, category, NOW-age if last_seen else None, NOW-age))

    def selected(self):
        return self.db.execute(QUERY, ('sender', 1234)).fetchone()

    def test_demo_boundary(self):
        for age, accepted in [(0, True), (299, True), (300, False), (301, False)]:
            with self.subTest(age=age):
                self.db.execute('DELETE FROM hackReport')
                self.add(age)
                self.assertEqual(self.selected() is not None, accepted)

    def test_forwarded_demo_prefix_expires(self):
        self.add(300, category='from_partner', why='DEMO:forwarded')
        self.assertIsNone(self.selected())

    def test_null_category_demo_prefix_expires(self):
        self.add(300, category=None, why='DEMO:legacy')
        self.assertIsNone(self.selected())

    def test_real_reports_do_not_expire(self):
        for category, why in [('ssh_fail', 'attack'), (None, None), ('from_partner', 'real')]:
            with self.subTest(category=category):
                self.db.execute('DELETE FROM hackReport')
                self.add(365*86400, category=category, why=why)
                self.assertEqual(self.selected()[1], 7)

    def test_expired_demo_does_not_hide_real_report(self):
        self.add(86400, category='ssh_fail')
        self.add(301)
        self.assertEqual(self.selected()[0], 1)

    def test_created_fallback_and_refresh(self):
        self.add(300, last_seen=False)
        self.assertIsNone(self.selected())
        self.db.execute('UPDATE hackReport SET lastSeen=?', (NOW,))
        self.assertIsNotNone(self.selected())

    def test_scope(self):
        self.add(0, ip='other')
        self.add(0, port=9999)
        self.assertIsNone(self.selected())
        self.add(0, port=0)
        self.assertIsNotNone(self.selected())

    def test_latest_clean_notification_supersedes_demo(self):
        self.add(10)
        self.add(0, why='DEMO:clean', severity=1)
        self.assertEqual(self.selected()[1], 1)

    def test_same_time_order_is_deterministic(self):
        self.add(0)
        self.add(0, severity=1)
        self.assertEqual(self.selected()[0], 2)


if __name__ == '__main__':
    unittest.main()
