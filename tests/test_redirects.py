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


class OutboundTests(unittest.TestCase):
    def test_partner_link_becomes_a_302_at_the_go_address(self):
        text = redirects.render_outbound({'candy-ai': "https://partner.example/r?a=1&b=x;y#top", 'Joi_2': 'http://joi.example'})
        self.assertIn('location = /go/candy-ai/ { return 302 "https://partner.example/r?a=1&b=x;y#top"; }', text)
        self.assertIn('location = /go/Joi_2/ { return 302 "http://joi.example"; }', text)

    def test_unsafe_entries_are_left_to_the_release_page(self):
        unsafe = {
            'quote': 'https://evil.example/"; include /etc/passwd; "',
            'dollar': 'https://partner.example/?u=$host',
            'space': 'https://partner.example/a b',
            'brace': 'https://partner.example/}',
            'newline': 'https://partner.example/' + chr(10) + 'location',
            'scheme': 'javascript:alert(1)',
            '../up': 'https://partner.example/',
            'long': 'https://partner.example/' + 'a' * 2000,
        }
        text = redirects.render_outbound({**unsafe, 'fine': 'https://partner.example/'})
        self.assertEqual(text.count('location'), 1)
        self.assertIn('/go/fine/', text)

    def test_a_map_that_is_not_one_answers_nothing_rather_than_refusing(self):
        for mapping in ['bad', [], {'a': None}, {f'p{i}': 'https://partner.example/' for i in range(1001)}]:
            self.assertNotIn('location', redirects.render_outbound(mapping))


if __name__ == '__main__':
    unittest.main()
