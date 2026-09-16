import importlib.util
from pathlib import Path
import unittest

spec = importlib.util.spec_from_file_location('redirects', Path(__file__).resolve().parents[1] / 'infra/apply-redirects.py')
redirects = importlib.util.module_from_spec(spec)
spec.loader.exec_module(redirects)


class RedirectHelperTests(unittest.TestCase):
    def test_collapses_chain_preserving_query(self):
        text = redirects.render({'/old/': '/middle/', '/middle/': '/new/'})
        self.assertIn('location = /old/ { return 301 /new/$is_args$args; }', text)

    def test_cycle_rejected(self):
        for mapping in [{'/a/': '/a/'}, {'/a/': '/b/', '/b/': '/a/'}]:
            with self.assertRaises(ValueError):
                redirects.render(mapping)

    def test_no_executable_nginx_or_external_paths(self):
        for path in ['/x/;include /tmp/evil;', '//evil.test/', 'https://evil.test/', '/a/../b/', '/a/$host/']:
            with self.assertRaises(ValueError):
                redirects.render({'/old/': path})

    def test_invalid_types_and_large_maps(self):
        for mapping in ['bad', {'/old/': None}, {f'/x{i}/': '/new/' for i in range(10001)}]:
            with self.assertRaises(ValueError):
                redirects.render(mapping)

    def test_legacy_empty_release_clears_redirects(self):
        self.assertNotIn('location', redirects.render([]))


if __name__ == '__main__':
    unittest.main()
