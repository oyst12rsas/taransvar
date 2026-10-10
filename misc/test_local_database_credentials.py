import importlib.util
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest import mock

HERE=Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('provision',HERE/'provision_local_database.py')
provision=importlib.util.module_from_spec(spec);spec.loader.exec_module(provision)


class LocalDatabaseCredentials(unittest.TestCase):
    def test_first_install_generates_distinct_passwords_and_reinstall_preserves(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'credentials';calls=[]
            def execute(sql,**kwargs):calls.append((sql,kwargs));return '1\n'
            provision.provision(root,execute,web_gid=0)
            app=(root/'db-app.password').read_text().strip();perl=(root/'db-perl.password').read_text().strip()
            self.assertRegex(app,r'^[a-f0-9]{64}$');self.assertNotEqual(app,perl)
            self.assertEqual((root/'db-app.password').stat().st_mode & 0o777,0o640)
            self.assertEqual((root/'db-perl.password').stat().st_mode & 0o777,0o600)
            self.assertEqual((root/'db-app.cnf').stat().st_mode & 0o777,0o600)
            self.assertEqual((root/'access-mysql.cnf').read_text(),(root/'db-app.cnf').read_text())
            self.assertTrue(all("'@'localhost'" in sql for sql,kwargs in calls if not kwargs))
            provision.provision(root,execute,web_gid=0)
            self.assertEqual((root/'db-app.password').read_text().strip(),app)
            self.assertEqual((root/'db-perl.password').read_text().strip(),perl)
            with tempfile.TemporaryDirectory() as another:
                other=Path(another)/'credentials';provision.provision(other,execute,web_gid=0)
                self.assertNotEqual((other/'db-app.password').read_text().strip(),app)

    def test_failed_database_install_retains_generated_password_for_retry(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)/'credentials'
            def broken(sql,**kwargs):raise RuntimeError('test')
            with self.assertRaises(RuntimeError):provision.provision(root,broken,web_gid=0)
            password=(root/'db-app.password').read_text()
            provision.provision(root,lambda *a,**kw:'1\n',web_gid=0)
            self.assertEqual((root/'db-app.password').read_text(),password)

    def test_corrupt_and_symlink_credentials_are_not_silently_replaced(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);path=root/'db-app.password';path.write_text('invalid')
            with self.assertRaises(RuntimeError):provision.password(root,'app',0)
            self.assertEqual(path.read_text(),'invalid')
            path.unlink();target=root/'target';target.write_text('f'*64);path.symlink_to(target)
            with self.assertRaises(RuntimeError):provision.password(root,'app',0)
            self.assertEqual(target.read_text(),'f'*64)

    def test_sql_secrets_use_stdin_and_errors_are_sanitized(self):
        with mock.patch.object(provision.subprocess,'run',return_value=mock.Mock(returncode=1,stderr='secret',stdout='')) as run:
            with self.assertRaises(RuntimeError) as caught:provision.mysql('sensitive-statement')
            self.assertNotIn('secret',str(caught.exception))
            self.assertNotIn('sensitive-statement',' '.join(run.call_args.args[0]))
            self.assertEqual(run.call_args.kwargs['input'],'sensitive-statement')

    def test_php_perl_and_c_read_same_generated_credential(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp);password='a1'*32;config=root/'password';config.write_text(password+'\n')
            env={**os.environ,'TARASEC_DB_APP_PASSWORD_FILE':str(config),'PERL5LIB':str(HERE)}
            perl=subprocess.check_output(['perl','-MTaraSecDB=db_password','-e',"print db_password('app')"],env=env,text=True)
            self.assertEqual(perl,password)
            php=os.environ.get('PHP','php')
            result=subprocess.check_output([php,'-r',"require 'html/db_credentials.php';echo tarasecDbPassword();"],cwd=HERE.parent,env=env,text=True)
            self.assertEqual(result,password)
            source=root/'reader.c';source.write_text('#include "db_credentials.h"\nint main(void){char p[65];if(!tarasec_db_password(p))return 1;puts(p);return 0;}\n')
            binary=root/'reader';subprocess.run(['gcc','-Wall','-Wextra','-Werror','-I',str(HERE.parent/'taralink'),str(source),'-o',str(binary)],check=True)
            self.assertEqual(subprocess.check_output([str(binary)],env=env,text=True).strip(),password)
            config.write_text('corrupt\n')
            self.assertNotEqual(subprocess.run([str(binary)],env=env,capture_output=True).returncode,0)
            self.assertNotEqual(subprocess.run([php,'-r',"require 'html/db_credentials.php';tarasecDbPassword();"],cwd=HERE.parent,env=env,capture_output=True).returncode,0)
            self.assertNotEqual(subprocess.run(['perl','-MTaraSecDB=db_password','-e',"db_password('app')"],env=env,capture_output=True).returncode,0)


if __name__=='__main__':unittest.main()
