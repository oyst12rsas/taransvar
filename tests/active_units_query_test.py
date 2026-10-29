"""Run the actual active-client SELECT with SQLite compatibility functions."""
import re
import sqlite3
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / 'html/gatekeeper/ajax.php').read_text().split('function activeUnits()', 1)[1]
QUERY = re.search(r'\$sql = "(.*?)";', SOURCE, re.S)[1]
QUERY = QUERY.replace('DATE_SUB(NOW(), INTERVAL 15 MINUTE)', '(NOW() - 900)')

class ActiveUnitsQueryTest(unittest.TestCase):
    def setUp(self):
        self.db = sqlite3.connect(':memory:')
        self.db.row_factory = sqlite3.Row
        self.db.create_function('NOW', 0, lambda: 1000000)
        self.db.create_function('INET_NTOA', 1, lambda value: value)
        self.db.executescript('''
            CREATE TABLE unit (unitId INTEGER PRIMARY KEY, description TEXT,
                lastSeen INTEGER, mac BLOB, vci TEXT, ipAddress TEXT,
                hostname TEXT, dhcpClientId BLOB);
            CREATE TABLE traffic (ipFrom TEXT, lastSeen INTEGER, created INTEGER);
            CREATE TABLE unitPort (unitId INTEGER, created INTEGER);
            INSERT INTO unit (unitId,ipAddress,lastSeen) VALUES
                (20,'10.100.0.150',700000), (21,'10.100.0.151',700000),
                (22,'10.100.0.152',999999), (23,'10.100.0.153',700000);
        ''')

    def tearDown(self):
        self.db.close()

    def rows(self):
        return {row['unitId']: dict(row) for row in self.db.execute(QUERY)}

    def test_fresh_phone_traffic_overrides_stale_unit_without_dhcp(self):
        self.db.execute('INSERT INTO traffic VALUES (?, ?, ?)', ('10.100.0.150',999998,700000))
        row = self.rows()[20]
        self.assertEqual(row['lastSeen'], 999998)
        self.assertEqual(row['ip'], '10.100.0.150')
        self.assertEqual(row['hostname'], '')

    def test_ports_and_null_traffic_timestamp_fallback(self):
        self.db.execute('INSERT INTO unitPort VALUES (21,999997)')
        self.db.execute("INSERT INTO traffic VALUES ('10.100.0.150',NULL,999998)")
        self.assertEqual(set(self.rows()), {20,21,22})

    def test_stale_and_unknown_sources_are_excluded(self):
        self.db.executescript("""
            INSERT INTO traffic VALUES ('10.100.0.150',700000,700000);
            INSERT INTO traffic VALUES ('203.0.113.1',999999,999999);
            INSERT INTO unitPort VALUES (21,700000);
            INSERT INTO unitPort VALUES (999,999999);
        """)
        self.assertEqual(set(self.rows()), {22})

    def test_multiple_observations_merge_to_latest_timestamp(self):
        self.db.executescript("""
            INSERT INTO traffic VALUES ('10.100.0.150',999998,700000);
            INSERT INTO traffic VALUES ('10.100.0.150',999997,700000);
            INSERT INTO unitPort VALUES (20,999999);
        """)
        self.assertEqual(self.rows()[20]['lastSeen'],999999)
        self.assertEqual(len(self.rows()),2)

if __name__ == '__main__':
    unittest.main()
