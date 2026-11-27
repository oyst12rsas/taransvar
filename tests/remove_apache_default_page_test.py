"""misc/remove_apache_default_page.sh removes only the web server's stock page."""
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[1] / 'misc/remove_apache_default_page.sh'
APACHE = '<html><head><title>Apache2 Ubuntu Default Page: It works</title></head></html>\n'


def run(docroot):
    return subprocess.run(['bash', str(SCRIPT), str(docroot)], check=True, capture_output=True, text=True)


class RemoveDefaultPageTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = Path(self.tmp.name)
        (self.root / 'index.php').write_text('<?php echo "TaraSec";\n')

    def tearDown(self):
        self.tmp.cleanup()

    def test_stock_apache_page_is_removed(self):
        (self.root / 'index.html').write_text(APACHE)
        run(self.root)
        self.assertFalse((self.root / 'index.html').exists())
        self.assertTrue((self.root / 'index.php').exists())

    def test_own_index_html_is_kept(self):
        (self.root / 'index.html').write_text('<title>My site</title>\n')
        run(self.root)
        self.assertTrue((self.root / 'index.html').exists())

    def test_kept_when_tarasec_page_missing(self):
        (self.root / 'index.php').unlink()
        (self.root / 'index.html').write_text(APACHE)
        run(self.root)
        self.assertTrue((self.root / 'index.html').exists())

    def test_symlink_is_not_followed(self):
        target = self.root / 'elsewhere.html'
        target.write_text(APACHE)
        (self.root / 'index.html').symlink_to(target)
        run(self.root)
        self.assertTrue((self.root / 'index.html').is_symlink())
        self.assertTrue(target.exists())

    def test_missing_page_is_fine(self):
        run(self.root)


if __name__ == '__main__':
    unittest.main()
