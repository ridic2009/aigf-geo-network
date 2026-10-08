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

# What browsers people use in October 2026 send.
PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/26.4 Mobile/15E148 Safari/604.1'
DESKTOP = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'
TABLET = 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/154.0.0.0 Safari/537.36'
FIREFOX = 'Mozilla/5.0 (X11; Ubuntu; Linux x86_64; rv:138.0) Gecko/20100101 Firefox/138.0'
# What the scrapers of the network's first days sent.
OLD_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1'
VISTA = 'Mozilla/5.0 (Windows NT 6.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.89 Safari/537.36'
OLD_CHROME = 'Mozilla/5.0 (Linux; Android 12; Pixel 6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
HANDMADE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'
GOOGLE_OTHER = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; GoogleOther)'
GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
HOST = 'aigirlfriendranking-germany.site'


def click(uri, ip='203.0.113.1', ua=PHONE, ref=f'https://{HOST}/reviews/candy-ai/', t='2026-10-07T12:00:00+00:00', **extra):
    return {'t': t, 'host': HOST, 'uri': uri, 'status': '302', 'method': 'GET', 'ref': ref, 'ip': ip, 'ua': ua, 'cc': 'DE', **extra}


def view(uri, ref='https://www.google.de/', ua=PHONE, t='2026-10-07T11:59:00+00:00', host=HOST, cc='DE', **extra):
    return {'t': t, 'host': host, 'uri': uri, 'ref': ref, 'ua': ua, 'cc': cc, **extra}


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

    def test_a_strange_button_or_country_is_left_blank(self):
        days = traffic.count([
            click('/go/candy-ai/?from=%3Cscript%3E', cc='zz9'),
        ], [], self.wanted)
        rows = traffic.serialise('2026-10-07', days['2026-10-07'], 'now')['hosts'][HOST]['clicks']
        self.assertEqual([(row['page'], row['placement'], row['country']) for row in rows], [('/reviews/candy-ai/', '', '')])

    def test_a_press_that_names_no_page_of_this_site_is_no_press_of_a_person(self):
        # A person presses a button on a page, and the browser says which; a program asks /go/ directly.
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ref=''),
            click('/go/candy-ai/?from=hero', ip='203.0.113.4', ref='https://evil.example/reviews/'),
        ], [], self.wanted)
        self.assertEqual(days['2026-10-07'][HOST]['clicks'], {})
        self.assertEqual(days['2026-10-07'][HOST]['bots']['clicks'], 2)

    def test_old_handmade_and_self_declared_browsers_are_programs(self):
        for agent in (OLD_IPHONE, VISTA, OLD_CHROME, HANDMADE, GOOGLE_OTHER):
            with self.subTest(agent=agent[:60]):
                self.assertTrue(traffic.is_robot(agent, traffic.date(2026, 10, 7)))
        for agent in (PHONE, DESKTOP, TABLET, FIREFOX):
            with self.subTest(agent=agent[:60]):
                self.assertFalse(traffic.is_robot(agent, traffic.date(2026, 10, 7)))
        # The same Chrome was a person's a year ago.
        self.assertFalse(traffic.is_robot(OLD_CHROME, traffic.date(2023, 7, 1)))

    def test_a_burst_across_partners_is_a_program_and_a_double_press_is_a_person(self):
        burst = [click(f'/go/{product}/?from=card', ip='198.51.100.7', t=f'2026-10-07T12:00:{second:02d}+00:00')
                 for second, product in ((0, 'candy-ai'), (2, 'nomi'), (5, 'replika'), (7, 'kupid'), (9, 'joi'))]
        double = [click('/go/candy-ai/?from=hero', t='2026-10-07T13:00:00+00:00'),
                  click('/go/candy-ai/?from=hero', t='2026-10-07T13:00:01+00:00'),
                  click('/go/nomi/?from=card', t='2026-10-07T13:05:00+00:00')]
        days = traffic.count(burst + double, [], self.wanted)
        counted = days['2026-10-07'][HOST]
        self.assertEqual(counted['bots']['clicks'], 5)
        self.assertEqual(sum(clicks for clicks, _ in counted['clicks'].values()), 3)
        self.assertEqual(sum(unique for _, unique in counted['clicks'].values()), 2)

    def test_sec_fetch_headers_tell_a_person_from_a_program_once_they_are_logged(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero', sfm='navigate', sfs='same-origin', sfu='?1', sp=''),
            click('/go/nomi/?from=hero', ip='203.0.113.21', sfm='', sfs='', sfu='', sp=''),
            click('/go/nomi/?from=hero', ip='203.0.113.22', sfm='navigate', sfs='same-origin', sfu='', sp=''),
            click('/go/nomi/?from=hero', ip='203.0.113.23', sfm='navigate', sfs='same-origin', sfu='?1', sp='prefetch'),
        ], [
            view('/', sfm='navigate', sfd='document', sp=''),
            view('/', sfm='', sfd='', sp=''),
            view('/', sfm='no-cors', sfd='empty', sp=''),
            view('/', sfm='navigate', sfd='document', sp='prefetch;prerender'),
        ], self.wanted)
        counted = days['2026-10-07'][HOST]
        self.assertEqual(sum(clicks for clicks, _ in counted['clicks'].values()), 1)
        self.assertEqual(sum(counted['views'].values()), 1)
        self.assertEqual(counted['bots'], {'views': 3, 'clicks': 3})

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
