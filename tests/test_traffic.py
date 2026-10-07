from datetime import datetime, timezone
import gzip
import importlib.util
import json
import os
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('traffic', Path(__file__).resolve().parents[1] / 'infra/aggregate-traffic.py')
traffic = importlib.util.module_from_spec(spec)
spec.loader.exec_module(traffic)

PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 Safari/604.1'
DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'
TABLET = 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'
GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
HOST = 'aigirlfriendranking-germany.site'


def click(uri, ip='203.0.113.1', ua=PHONE, ref=f'https://{HOST}/reviews/candy-ai/', t='2026-10-07T12:00:00+00:00', **extra):
    return {'t': t, 'host': HOST, 'uri': uri, 'status': '302', 'method': 'GET', 'ref': ref, 'ip': ip, 'ua': ua, 'cc': 'DE', **extra}


def view(uri, ref='https://www.google.de/', ua=PHONE, t='2026-10-07T11:59:00+00:00', host=HOST, cc='DE'):
    return {'t': t, 'host': host, 'uri': uri, 'ref': ref, 'ua': ua, 'cc': cc}


class CountTests(unittest.TestCase):
    wanted = {'2026-10-07'}

    def test_clicks_by_page_product_and_button_with_repeat_presses_counted_once(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero'),
            click('/go/candy-ai/?from=hero'),  # the same reader again
            click('/go/candy-ai/?from=sticky', ip='203.0.113.2'),
            click('/go/candy-ai/index.html?from=card', ip='203.0.113.3', ua=DESKTOP, status='200'),
        ], [], self.wanted)
        rows = traffic.serialise('2026-10-07', days['2026-10-07'], 'now')['hosts'][HOST]['clicks']
        self.assertEqual(rows, [
            {'page': '/reviews/candy-ai/', 'product': 'candy-ai', 'placement': 'card', 'country': 'DE', 'device': 'desktop', 'clicks': 1, 'unique': 1},
            {'page': '/reviews/candy-ai/', 'product': 'candy-ai', 'placement': 'hero', 'country': 'DE', 'device': 'mobile', 'clicks': 2, 'unique': 1},
            {'page': '/reviews/candy-ai/', 'product': 'candy-ai', 'placement': 'sticky', 'country': 'DE', 'device': 'mobile', 'clicks': 1, 'unique': 1},
        ])

    def test_readers_behind_one_cloudflare_edge_are_told_apart(self):
        edge = '104.23.170.126'
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.1'),
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.2'),
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.2'),
        ], [], self.wanted)
        self.assertEqual(list(days['2026-10-07'][HOST]['clicks'].values()), [(3, 2)])

    def test_robots_unknown_addresses_and_misses_are_not_readers(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ua=GOOGLEBOT),
            click('/go/candy-ai/?from=hero', method='HEAD'),
            click('/go/candy-ai/?from=hero', ua='curl/8.5.0'),
            click('/go/nothing/', status='404'),
            click('/go/', status='200'),
            click('/go/candy-ai/', t='2026-10-06T23:59:59+00:00'),  # another day
        ], [view('/', ua=GOOGLEBOT)], self.wanted)
        counted = days['2026-10-07'][HOST]
        self.assertEqual(counted['clicks'], {})
        self.assertEqual(counted['bots'], {'views': 1, 'clicks': 3})

    def test_a_page_from_elsewhere_or_a_strange_button_is_left_blank(self):
        days = traffic.count([
            click('/go/candy-ai/?from=%3Cscript%3E', ref='https://evil.example/reviews/'),
            click('/go/candy-ai/', ip='203.0.113.9', ref='', cc='zz9'),
        ], [], self.wanted)
        rows = traffic.serialise('2026-10-07', days['2026-10-07'], 'now')['hosts'][HOST]['clicks']
        self.assertEqual([(row['page'], row['placement'], row['country']) for row in rows], [('', '', ''), ('', '', 'DE')])

    def test_views_by_page_source_country_and_device(self):
        days = traffic.count([], [
            view('/'), view('/?utm_source=x'), view('/', ua=DESKTOP, ref=''),
            view('/reviews/candy-ai/', ref=f'https://{HOST}/', ua=TABLET),
            view('/reviews/candy-ai/', ref='https://aigirlfriendranking.site/'),
            view('/reviews/candy-ai/', ref='https://chatgpt.com/'),
            view('/', host='aigirlfriendranking.site', cc='US'),
            view('/%C3%BCber/', ref='https://www.reddit.com/r/x'),
        ], self.wanted)
        rows = traffic.serialise('2026-10-07', days['2026-10-07'], 'now')['hosts'][HOST]['views']
        self.assertEqual([(row['page'], row['source'], row['device'], row['views']) for row in rows], [
            ('/', 'direct', 'desktop', 1),
            ('/', 'google', 'mobile', 2),
            ('/reviews/candy-ai/', 'ai', 'mobile', 1),
            # A neighbouring site of the network is not a search engine.
            ('/reviews/candy-ai/', 'internal', 'mobile', 1),
            ('/reviews/candy-ai/', 'internal', 'tablet', 1),
            ('/über/', 'social', 'mobile', 1),
        ])

    def test_a_day_is_the_utc_day(self):
        self.assertEqual(traffic.day_of('2026-10-08T01:30:00+03:00'), '2026-10-07')
        self.assertIsNone(traffic.day_of('yesterday'))


class RunTests(unittest.TestCase):
    def test_writes_the_open_days_from_current_and_rotated_logs(self):
        with tempfile.TemporaryDirectory() as logs, tempfile.TemporaryDirectory() as output:
            def log(name, records, packed=False):
                text = ''.join(json.dumps(record) + '\n' for record in records) + 'not json\n'
                path = os.path.join(logs, name)
                if packed:
                    with gzip.open(path, 'wt', encoding='utf-8') as stream:
                        stream.write(text)
                else:
                    Path(path).write_text(text, encoding='utf-8')

            log('aigf-clicks.log', [click('/go/candy-ai/?from=hero')])
            log('aigf-clicks.log.2.gz', [click('/go/candy-ai/?from=card', ip='203.0.113.5', t='2026-10-06T10:00:00+00:00')], packed=True)
            log('aigf-views.log', [view('/reviews/candy-ai/')])
            written = traffic.run(datetime(2026, 10, 7, 12, 30, tzinfo=timezone.utc), logs, output)
            self.assertEqual(written, ['2026-10-06', '2026-10-07'])
            today = json.loads(Path(output, '2026-10-07.json').read_text(encoding='utf-8'))
            self.assertEqual(today['generatedAt'], '2026-10-07T12:30:00+00:00')
            self.assertEqual(today['hosts'][HOST]['views'][0]['views'], 1)
            self.assertEqual(today['hosts'][HOST]['clicks'][0]['placement'], 'hero')
            yesterday = json.loads(Path(output, '2026-10-06.json').read_text(encoding='utf-8'))
            self.assertEqual(yesterday['hosts'][HOST]['clicks'][0]['placement'], 'card')
            # Nobody's address or browser leaves the script.
            for name in os.listdir(output):
                text = Path(output, name).read_text(encoding='utf-8')
                self.assertNotIn('203.0.113', text)
                self.assertNotIn('iPhone', text)

    def test_an_open_day_with_nothing_logged_is_still_written_as_counted(self):
        with tempfile.TemporaryDirectory() as logs, tempfile.TemporaryDirectory() as output:
            written = traffic.run(datetime(2026, 10, 7, 0, 5, tzinfo=timezone.utc), logs, output)
            self.assertEqual(written, ['2026-10-06', '2026-10-07'])
            self.assertEqual(json.loads(Path(output, '2026-10-07.json').read_text())['hosts'], {})


if __name__ == '__main__':
    unittest.main()
