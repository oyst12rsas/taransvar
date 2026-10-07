#!/usr/bin/env python3
"""Focused archive protocol tests; optionally exercise MariaDB via CI."""
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
REAL = os.environ.get('ARCHIVE_TEST_DATABASE') == '1'
MARIADB = shutil.which('mariadb')


class InstallPolicy(unittest.TestCase):
    def test_config_migration_and_disable_preserve_local_settings(self):
        source = (ROOT / 'misc/install_archive_retention.sh').read_text()
        writer = source.split("<<'PY'\n", 1)[1].split('\nPY\n', 1)[0]
        with tempfile.TemporaryDirectory() as directory:
            p = Path(directory)
            writer = writer.replace('/etc/tarasec-', str(p / 'config-'))
            writer = writer.replace('/usr/local/sbin/tarasec-', str(p / 'installed-'))
            script = p / 'writer.py'
            script.write_text(writer)
            config = p / 'config-traffic-archive.conf'
            old = p / 'installed-traffic-archive'
            # New installations have no testbed address and do not delete.
            subprocess.run(['python3', str(script), 'traffic', 'install'], check=True)
            self.assertIn('ENABLED=0', config.read_text())
            self.assertIn('ARCHIVE_HOST=\n', config.read_text())
            # Preserve the already-verified deployment without resetting policy.
            config.write_text('RETENTION_DAYS=45\nBATCH_ROWS=100\nMAX_BATCHES=2\n')
            old.write_text('sftp archive-upload@192.168.122.133')
            subprocess.run(['python3', str(script), 'traffic', 'install'], check=True)
            self.assertIn('ENABLED=1', config.read_text())
            self.assertIn('ARCHIVE_HOST=192.168.122.133', config.read_text())
            subprocess.run(['python3', str(script), 'traffic', 'disable'], check=True)
            text = config.read_text()
            self.assertIn('ENABLED=0', text)
            self.assertIn('RETENTION_DAYS=45', text)
            self.assertIn('BATCH_ROWS=100', text)
            subprocess.run(['python3', str(script), 'traffic', 'install'], check=True)
            self.assertIn('ENABLED=0', config.read_text())


def db(query):
    return subprocess.check_output(
        [MARIADB, '-h', '127.0.0.1', '-P', '3307', '-u', 'root', '-N', '-B', '-e', query],
        text=True).strip()


class ArchiveProtocol(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.p = Path(self.tmp.name)
        self.bin = self.p / 'bin'
        self.bin.mkdir()
        source = (ROOT / 'misc/archive_table.sh').read_text()
        self.runtime = self.p / 'archive.sh'
        self.runtime.write_text(source.replace('/var/lib/tarasec-', str(self.p / 'state-')))
        self.log = self.p / 'sql.log'
        self.log.touch()
        self.env = dict(os.environ, PATH=str(self.bin) + ':' + os.environ['PATH'],
                        ENABLED='1', ARCHIVE_HOST='archive.example.org', MAX_BATCHES='1',
                        ARCHIVE_KEY=str(self.p / 'key'), DB_NAME='archive_fixture',
                        TEST_LOG=str(self.log), TEST_REMOTE=str(self.p / 'remote'),
                        TEST_REAL=str(int(REAL)), TEST_MARIADB=MARIADB or '')
        (self.p / 'key').touch()
        mock = r'''#!/usr/bin/env python3
import os,sys,pathlib,shutil,subprocess
name=pathlib.Path(sys.argv[0]).name
if name=='sleep': sys.exit(0)
if name=='mariadb':
 q=sys.argv[sys.argv.index('-e')+1] if '-e' in sys.argv else sys.stdin.read()
 with open(os.environ['TEST_LOG'],'a') as f:f.write(q+'\n')
 if os.environ['TEST_REAL']=='1':
  sys.exit(subprocess.run([os.environ['TEST_MARIADB'],'-h','127.0.0.1','-P','3307','-u','root']+sys.argv[1:],input=q if '-e' not in sys.argv else None,text=True).returncode)
 if 'GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION' in q:
  print('changed' if os.getenv('BAD_SCHEMA') else ('ip,created,status' if os.environ['TEST_TABLE']=='statuslog' else 'trafficId,ipFrom,ipTo,whoIsId,portFrom,portTo,created,count,isLan,tag,lastSeen'))
 elif 'SELECT ENGINE' in q:print('InnoDB')
 elif 'information_schema.STATISTICS' in q:print('created,ip' if os.environ['TEST_TABLE']=='statuslog' else 'lastSeen,ipFrom')
 elif 'KEY_COLUMN_USAGE' in q:print(1 if os.getenv('HAS_FK') else 0)
 elif 'DATE_FORMAT' in q:print('2026-09-07 13:00:00')
 elif 'COUNT(*)' in q:print(2)
 elif 'CHECKSUM TABLE' in q:print('table\t123456')
 elif 'DELETE t' in q:print(1)
elif name=='mariadb-dump':
 if os.environ['TEST_REAL']=='1':
  sys.exit(subprocess.run(['REAL_DUMP','-h','127.0.0.1','-P','3307','-u','root']+sys.argv[1:]).returncode)
 print('-- simulated dump')
else:
 batch=sys.stdin.read()
 if os.getenv('FAIL_UPLOAD'):sys.exit(1)
 remote=pathlib.Path(os.environ['TEST_REMOTE'])
 for line in batch.splitlines():
  parts=line.split();cmd=parts[0];rp=lambda x:remote/x.lstrip('/')
  if cmd=='mkdir':rp(parts[1]).mkdir(parents=True)
  elif cmd=='put':
   shutil.copyfile(parts[1],rp(parts[2]))
   if os.environ['TEST_REAL']=='1' and parts[2].endswith('.part'):
    q="UPDATE archive_fixture.partnerRouterStatusLog SET status='Original ' WHERE ip=1" if os.environ['TEST_TABLE']=='statuslog' else "UPDATE archive_fixture.traffic SET count=count+1 WHERE trafficId=1"
    subprocess.run([os.environ['TEST_MARIADB'],'-h','127.0.0.1','-P','3307','-u','root','-e',q],check=True)
  elif cmd=='rename':rp(parts[1]).rename(rp(parts[2]))
  elif cmd=='get':shutil.copyfile(rp(parts[1]),parts[2])
'''
        mock = mock.replace('REAL_DUMP', shutil.which('mariadb-dump') or 'mariadb-dump')
        for cmd in ('mariadb', 'mariadb-dump', 'sftp', 'sleep'):
            path = self.bin / cmd
            path.write_text(mock)
            path.chmod(0o755)

    def tearDown(self):
        if REAL:
            # The isolated CI database contains only this test's scratch schemas.
            names = db("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME LIKE 'ts_archive_stage_%' OR SCHEMA_NAME LIKE 'ts_archive_restore_%'").splitlines()
            for name in names:
                db(f'DROP DATABASE `{name}`')
            db('DROP DATABASE IF EXISTS archive_fixture')
        self.tmp.cleanup()

    def run_archive(self, table='traffic', **extra):
        return subprocess.run(['bash', str(self.runtime), table],
                              env=dict(self.env, TEST_TABLE=table, **extra),
                              capture_output=True, text=True)

    def fixture(self, table):
        if not REAL:
            return
        db('CREATE DATABASE archive_fixture')
        if table == 'traffic':
            db('''CREATE TABLE archive_fixture.traffic (
trafficId INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,ipFrom INT UNSIGNED,ipTo INT UNSIGNED,
whoIsId INT UNSIGNED,portFrom INT UNSIGNED,portTo INT UNSIGNED,created TIMESTAMP NOT NULL,
count INT UNSIGNED NOT NULL DEFAULT 1,isLan BIT NOT NULL DEFAULT b'0',tag INT UNSIGNED,lastSeen TIMESTAMP NULL,
KEY idx_traffic_active_lastseen(lastSeen,ipFrom)) ENGINE=InnoDB;
INSERT INTO archive_fixture.traffic(trafficId,created,lastSeen) VALUES
(1,NOW()-INTERVAL 60 DAY,NOW()-INTERVAL 60 DAY),
(2,NOW()-INTERVAL 60 DAY,NOW()-INTERVAL 60 DAY),
(3,NOW(),NOW()-INTERVAL 60 DAY),
(4,NOW()-INTERVAL 60 DAY,NULL);''')
        else:
            db('''CREATE TABLE archive_fixture.partnerRouterStatusLog (
ip INT UNSIGNED NOT NULL,created DATETIME NOT NULL,status TEXT NULL,
PRIMARY KEY(ip,created),KEY idx_statuslog_created(created,ip)) ENGINE=InnoDB;
INSERT INTO archive_fixture.partnerRouterStatusLog VALUES
(1,NOW()-INTERVAL 60 DAY,'original'),
(2,NOW()-INTERVAL 60 DAY,NULL),
(3,NOW(),'recent');''')

    def test_disabled_never_contacts_database(self):
        result = self.run_archive(ENABLED='0')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(self.log.read_text(), '')

    def test_traffic_preserves_mutated_recent_and_null_rows(self):
        self.fixture('traffic')
        result = self.run_archive()
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('deleted=1 skipped=1', result.stdout)
        if REAL:
            self.assertEqual(db('SELECT GROUP_CONCAT(trafficId ORDER BY trafficId) FROM archive_fixture.traffic'), '1,3,4')
        else:
            query = self.log.read_text().split('DELETE t', 1)[1].split('COMMIT', 1)[0]
            self.assertEqual(query.count('<=>'), 11)
            self.assertIn('t.`created` <', query)

    def test_statuslog_preserves_case_and_space_mutation(self):
        self.fixture('statuslog')
        result = self.run_archive('statuslog')
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertIn('deleted=1 skipped=1', result.stdout)
        if REAL:
            self.assertEqual(db('SELECT GROUP_CONCAT(ip ORDER BY ip) FROM archive_fixture.partnerRouterStatusLog'), '1,3')
        else:
            self.assertIn('BINARY t.`status` <=> BINARY s.`status`', self.log.read_text())

    def test_failed_upload_stops_deletion_and_next_run(self):
        self.fixture('traffic')
        result = self.run_archive(FAIL_UPLOAD='1')
        self.assertNotEqual(result.returncode, 0)
        self.assertNotIn('DELETE t', self.log.read_text())
        self.log.write_text('')
        result = self.run_archive()
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.log.read_text(), '')
        if REAL:
            self.assertEqual(db('SELECT COUNT(*) FROM archive_fixture.traffic'), '4')

    @unittest.skipIf(REAL, 'Metadata faults use the simulated client')
    def test_schema_and_foreign_key_guards(self):
        for flag in ('BAD_SCHEMA', 'HAS_FK'):
            result = self.run_archive(**{flag: '1'})
            self.assertNotEqual(result.returncode, 0)
            self.assertNotIn('CREATE DATABASE', self.log.read_text())
            self.log.write_text('')


if __name__ == '__main__':
    unittest.main()
