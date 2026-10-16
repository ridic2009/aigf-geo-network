from collections import Counter
from datetime import datetime, timezone
import gzip
import importlib.util
import ipaddress
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
# A phone that still works and was never updated past iOS 16.1: Safari sends no Sec-Fetch headers yet.
IOS16 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.1 Mobile/15E148 Safari/604.1'
IOS16_4 = 'Mozilla/5.0 (iPhone; CPU iPhone OS 16_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.4 Mobile/15E148 Safari/604.1'
# What the scrapers of the network's first days sent.
OLD_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 13_2_3 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/13.0.3 Mobile/15E148 Safari/604.1'
VISTA = 'Mozilla/5.0 (Windows NT 6.0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2272.89 Safari/537.36'
OLD_CHROME = 'Mozilla/5.0 (Linux; Android 12; Pixel 6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Mobile Safari/537.36'
HANDMADE = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/141.0 Safari/537.36'
GOOGLE_OTHER = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/153.0.8010.52 Mobile Safari/537.36 (compatible; GoogleOther)'
GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'
HOST = 'aigirlfriendranking-germany.site'
PAGE_ID = '3f9a0c51d2e8b7a4'
OTHER_ID = '0123456789abcdef'


def click(uri, ip='203.0.113.1', ua=PHONE, ref=f'https://{HOST}/reviews/candy-ai/', t='2026-10-07T12:00:00+00:00', **extra):
    return {'t': t, 'host': HOST, 'uri': uri, 'status': '302', 'method': 'GET', 'ref': ref, 'ip': ip, 'ua': ua, 'cc': 'DE', **extra}


def view(uri, ref='https://www.google.de/', ua=PHONE, t='2026-10-07T11:59:00+00:00', host=HOST, cc='DE', **extra):
    return {'t': t, 'host': host, 'uri': uri, 'ref': ref, 'ua': ua, 'cc': cc, **extra}


def signal(page_id=PAGE_ID, flags='', zone='Europe/Berlin', page='/reviews/candy-ai/', ip='203.0.113.1', ua=PHONE,
           t='2026-10-07T11:59:02+00:00', host=HOST, **extra):
    query = f'v=1&e=l&p={page_id}&f={flags}&z={zone}'
    return {'t': t, 'host': host, 'uri': f'/_s?{query}', 'ref': f'https://{host}{page}', 'ip': ip, 'ua': ua, 'cc': 'DE', **extra}


def rows(days, kind, day='2026-10-07', host=HOST):
    return traffic.serialise(day, days[day], 'now')['hosts'][host][kind]


def verdicts(days, kind, day='2026-10-07', host=HOST):
    """How many views or presses each (quality, reason) holds."""
    totals = Counter()
    for row in rows(days, kind, day, host):
        totals[(row['quality'], row['reason'])] += row['views' if kind == 'views' else 'clicks']
    return dict(totals)


HUMAN = ('human', '')


class CountTests(unittest.TestCase):
    wanted = {'2026-10-07'}

    def test_clicks_by_page_product_and_button_with_repeat_presses_counted_once(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero'),
            click('/go/candy-ai/?from=hero', t='2026-10-07T12:00:30+00:00'),  # the same reader again
            click('/go/candy-ai/?from=sticky', ip='203.0.113.2'),
            click('/go/candy-ai/index.html?from=card', ip='203.0.113.3', ua=DESKTOP, status='200'),
        ], [], self.wanted)
        common = {'page': '/reviews/candy-ai/', 'source': 'unknown', 'product': 'candy-ai', 'country': 'DE',
                  'quality': 'human', 'reason': ''}
        self.assertEqual(rows(days, 'clicks'), [
            {**common, 'placement': 'card', 'device': 'desktop', 'clicks': 1, 'unique': 1},
            {**common, 'placement': 'hero', 'device': 'mobile', 'clicks': 2, 'unique': 1},
            {**common, 'placement': 'sticky', 'device': 'mobile', 'clicks': 1, 'unique': 1},
        ])

    def test_readers_behind_one_cloudflare_edge_are_told_apart(self):
        edge = '104.23.170.126'
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.1'),
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.2'),
            click('/go/candy-ai/?from=hero', ip=edge, cfip='198.51.100.2'),
        ], [], self.wanted)
        self.assertEqual([(row['clicks'], row['unique']) for row in rows(days, 'clicks')], [(3, 2)])

    def test_robots_unknown_addresses_and_misses_are_counted_as_what_they_are(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ua=GOOGLEBOT),
            click('/go/candy-ai/?from=hero', method='HEAD'),
            click('/go/candy-ai/?from=hero', ua='curl/8.5.0'),
            click('/go/nothing/', status='404'),
            click('/go/', status='200'),
            click('/go/candy-ai/', t='2026-10-06T23:59:59+00:00'),  # another day
        ], [view('/', ua=GOOGLEBOT)], self.wanted)
        self.assertEqual(verdicts(days, 'clicks'), {('bot', 'declared'): 3})
        self.assertEqual(verdicts(days, 'views'), {('bot', 'declared'): 1})

    def test_a_strange_button_or_country_is_left_blank(self):
        days = traffic.count([
            click('/go/candy-ai/?from=%3Cscript%3E', cc='zz9'),
        ], [], self.wanted)
        self.assertEqual([(row['page'], row['placement'], row['country']) for row in rows(days, 'clicks')],
                         [('/reviews/candy-ai/', '', '')])

    def test_a_press_that_names_no_page_of_this_site_is_no_press_of_a_person(self):
        # A person presses a button on a page, and the browser says which; a program asks /go/ directly.
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ref=''),
            click('/go/candy-ai/?from=hero', ip='203.0.113.4', ref='https://evil.example/reviews/'),
        ], [], self.wanted)
        self.assertEqual(verdicts(days, 'clicks'), {('bot', 'no-referer'): 2})

    def test_old_handmade_and_self_declared_browsers_are_programs(self):
        day = traffic.date(2026, 10, 7)
        for agent in (OLD_IPHONE, VISTA, OLD_CHROME, HANDMADE, GOOGLE_OTHER):
            with self.subTest(agent=agent[:60]):
                self.assertTrue(traffic.is_robot(agent, day))
        for agent in (PHONE, DESKTOP, TABLET, FIREFOX, IOS16):
            with self.subTest(agent=agent[:60]):
                self.assertFalse(traffic.is_robot(agent, day))
        # The same Chrome was a person's a year ago.
        self.assertFalse(traffic.is_robot(OLD_CHROME, traffic.date(2023, 7, 1)))
        # Each says what it is: the strongest reason decides.
        self.assertEqual(traffic.verdict(traffic.agent_reasons(GOOGLE_OTHER, day)), ('bot', 'declared'))
        self.assertEqual(traffic.verdict(traffic.agent_reasons(VISTA, day)), ('bot', 'forged-ua'))
        self.assertEqual(traffic.verdict(traffic.agent_reasons(OLD_IPHONE, day)), ('bot', 'outdated-ua'))
        self.assertEqual(traffic.verdict([]), HUMAN)

    def test_a_burst_across_partners_is_a_program_and_a_double_press_is_a_person(self):
        burst = [click(f'/go/{product}/?from=card', ip='198.51.100.7', t=f'2026-10-07T12:00:{second:02d}+00:00')
                 for second, product in ((0, 'candy-ai'), (2, 'nomi'), (5, 'replika'), (7, 'kupid'), (9, 'joi'))]
        double = [click('/go/candy-ai/?from=hero', t='2026-10-07T13:00:00+00:00'),
                  click('/go/candy-ai/?from=hero', t='2026-10-07T13:00:01+00:00'),
                  click('/go/nomi/?from=card', t='2026-10-07T13:05:00+00:00')]
        days = traffic.count(burst + double, [], self.wanted)
        self.assertEqual(verdicts(days, 'clicks'), {('bot', 'burst'): 5, HUMAN: 3})
        self.assertEqual(sum(row['unique'] for row in rows(days, 'clicks') if row['quality'] == 'human'), 2)

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
        self.assertEqual(verdicts(days, 'clicks'), {HUMAN: 1, ('bot', 'no-fetch-meta'): 2, ('bot', 'prefetch'): 1})
        self.assertEqual(verdicts(days, 'views'), {HUMAN: 1, ('bot', 'no-fetch-meta'): 2, ('bot', 'prefetch'): 1})

    def test_a_safari_too_old_to_send_sec_fetch_is_still_a_person(self):
        none = {'sfm': '', 'sfs': '', 'sfu': '', 'sfd': '', 'sp': ''}
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ua=IOS16, **none),
            click('/go/candy-ai/?from=hero', ip='203.0.113.31', ua=IOS16_4, **none),
        ], [
            view('/', ua=IOS16, **none),
            view('/', ua=DESKTOP, **none),
            view('/', ua=IOS16_4, **none),
        ], self.wanted)
        self.assertEqual(verdicts(days, 'clicks'), {HUMAN: 1, ('bot', 'no-fetch-meta'): 1})
        self.assertEqual(verdicts(days, 'views'), {HUMAN: 1, ('bot', 'no-fetch-meta'): 2})
        self.assertFalse(traffic.sends_fetch_metadata(IOS16))
        self.assertTrue(traffic.sends_fetch_metadata(FIREFOX))

    def test_the_strongest_reason_decides(self):
        days = traffic.count([], [view('/', ua=GOOGLEBOT, sp='prefetch')], self.wanted)
        self.assertEqual(verdicts(days, 'views'), {('bot', 'declared'): 1})

    def test_views_by_page_source_country_and_device(self):
        days = traffic.count([], [
            view('/'), view('/?utm_source=Telegram!'), view('/?src=reddit_ads'), view('/', ua=DESKTOP, ref=''),
            view('/reviews/candy-ai/', ref=f'https://{HOST}/', ua=TABLET),
            view('/reviews/candy-ai/', ref='https://aigirlfriendranking.site/'),
            view('/reviews/candy-ai/', ref='https://chatgpt.com/'),
            view('/', host='aigirlfriendranking.site', cc='US'),
            view('/%C3%BCber/', ref='https://www.reddit.com/r/x'),
        ], self.wanted)
        self.assertEqual([(row['page'], row['source'], row['device'], row['views']) for row in rows(days, 'views')], [
            ('/', 'direct', 'desktop', 1),
            ('/', 'google', 'mobile', 1),
            # A link's own name for its source beats where the browser says it came from.
            ('/', 'utm:reddit_ads', 'mobile', 1),
            ('/', 'utm:telegram', 'mobile', 1),
            ('/reviews/candy-ai/', 'ai', 'mobile', 1),
            # A neighbouring site of the network is not a search engine.
            ('/reviews/candy-ai/', 'internal', 'mobile', 1),
            ('/reviews/candy-ai/', 'internal', 'tablet', 1),
            ('/über/', 'social', 'mobile', 1),
        ])

    def test_a_site_has_at_most_fifty_campaign_names_a_day(self):
        days = traffic.count([], [view(f'/?utm_source=n{index:02d}') for index in range(51)], self.wanted)
        sources = {row['source'] for row in rows(days, 'views')}
        self.assertEqual(len(sources), 51)
        self.assertIn('utm:n49', sources)
        self.assertNotIn('utm:n50', sources)
        self.assertIn('utm:other', sources)

    def test_a_press_comes_from_where_its_visit_began(self):
        reader = {'ip': '203.0.113.1'}
        days = traffic.count([
            click('/go/candy-ai/?from=hero', t='2026-10-07T11:06:00+00:00'),
            # After midnight, from a visit that began the evening before.
            click('/go/candy-ai/?from=hero', ip='203.0.113.9', t='2026-10-07T00:05:00+00:00'),
        ], [
            view('/', t='2026-10-07T11:00:00+00:00', **reader),
            view('/reviews/candy-ai/', ref=f'https://{HOST}/', t='2026-10-07T11:05:00+00:00', **reader),
            view('/', ref='https://www.bing.com/', t='2026-10-06T23:50:00+00:00', ip='203.0.113.9'),
        ], self.wanted)
        self.assertEqual(sorted(row['source'] for row in rows(days, 'clicks')), ['bing', 'google'])

    def test_a_press_by_a_reader_who_opened_no_page_is_in_doubt(self):
        days = traffic.count([
            click('/go/candy-ai/?from=hero', ip='198.51.100.50', t='2026-10-07T12:00:00+00:00'),
            # Too soon after addresses began to be logged to tell.
            click('/go/candy-ai/?from=hero', ip='198.51.100.51', t='2026-10-07T10:05:00+00:00'),
        ], [view('/', t='2026-10-07T10:00:00+00:00', ip='203.0.113.1')], self.wanted)
        self.assertEqual(verdicts(days, 'clicks'), {('suspect', 'no-view'): 1, HUMAN: 1})
        self.assertEqual({row['source'] for row in rows(days, 'clicks')}, {'unknown'})

    def test_twenty_pages_a_minute_is_a_program_and_its_press_too(self):
        fast = [view(f'/p{index}/', ref=f'https://{HOST}/', ip='198.51.100.60', t=f'2026-10-07T12:00:{index * 2:02d}+00:00')
                for index in range(20)]
        slow = [view(f'/p{index}/', ref=f'https://{HOST}/', ip='198.51.100.61', t=f'2026-10-07T12:00:{index * 3:02d}+00:00')
                for index in range(19)]
        days = traffic.count([click('/go/candy-ai/?from=card', ip='198.51.100.60', t='2026-10-07T12:01:00+00:00')],
                             fast + slow, self.wanted)
        self.assertEqual(verdicts(days, 'views'), {('bot', 'crawl-rate'): 20, HUMAN: 19})
        self.assertEqual(verdicts(days, 'clicks'), {('bot', 'crawl-rate'): 1})

    def test_fewer_pages_than_a_crawl_are_a_reader_whatever_their_number(self):
        # Ten to eighteen pages of one reader once stopped the count of every day (13-16 Oct 2026).
        for total in range(1, traffic.CRAWL_VIEWS):
            with self.subTest(pages=total):
                pages = [view(f'/p{index}/', ref=f'https://{HOST}/', ip='198.51.100.62', t=f'2026-10-07T12:00:{index:02d}+00:00')
                         for index in range(total)]
                self.assertEqual(verdicts(traffic.count([], pages, self.wanted), 'views'), {HUMAN: total})

    def test_a_data_centre_is_in_doubt(self):
        networks = traffic.Networks([(ipaddress.ip_address('198.51.100.0'), ipaddress.ip_address('198.51.100.255'))])
        days = traffic.count([click('/go/candy-ai/?from=hero', ip='198.51.100.7')],
                             [view('/', ip='198.51.100.7'), view('/', ip='203.0.113.1')], self.wanted, networks=networks)
        self.assertEqual(verdicts(days, 'views'), {('suspect', 'hosting'): 1, HUMAN: 1})
        self.assertEqual(verdicts(days, 'clicks'), {('suspect', 'hosting'): 1})

    def test_a_day_is_the_utc_day(self):
        self.assertEqual(traffic.day_of('2026-10-08T01:30:00+03:00'), '2026-10-07')
        self.assertIsNone(traffic.day_of('yesterday'))


class NetworkTests(unittest.TestCase):
    def test_only_data_centres_are_kept_and_found(self):
        table = '\n'.join([
            '0.0.0.0\t0.255.255.255\t0\tNone\tNot routed',
            '3.0.0.0\t3.255.255.255\t16509\tUS\tAMAZON-02 - Amazon.com, Inc.',
            '104.16.0.0\t104.31.255.255\t13335\tUS\tCLOUDFLARENET - Cloudflare, Inc.',
            '190.0.0.0\t190.0.255.255\t64502\tCO\tCOLOMBIA MOVIL',
            '198.51.100.0\t198.51.100.255\t64500\tNL\tXYZ Hosting LLC',
            '203.0.113.0\t203.0.113.255\t64501\tDE\tDeutsche Telekom AG',
            '2a01:4f8::\t2a01:4f8:ffff:ffff:ffff:ffff:ffff:ffff\t24940\tDE\tHETZNER-AS',
            'broken line',
        ]) + '\n'
        with tempfile.TemporaryDirectory() as directory:
            path = os.path.join(directory, 'ip2asn-combined.tsv.gz')
            with gzip.open(path, 'wt', encoding='utf-8') as stream:
                stream.write(table)
            networks = traffic.Networks.load(path)
        for address, hosting in (('3.1.2.3', True), ('104.16.1.1', False), ('190.0.0.5', False),
                                 ('198.51.100.7', True), ('203.0.113.9', False), ('2a01:4f8::1', True),
                                 ('2a02::1', False), ('2.255.255.255', False), ('not an address', False)):
            with self.subTest(address=address):
                self.assertEqual(networks.hosting(address), hosting)

    def test_without_a_table_nothing_is_a_data_centre(self):
        with tempfile.TemporaryDirectory() as directory:
            missing = traffic.Networks.load(os.path.join(directory, 'none.tsv.gz'))
            broken = Path(directory, 'broken.tsv.gz')
            broken.write_text('not gzip', encoding='utf-8')
            self.assertFalse(missing.hosting('3.1.2.3'))
            self.assertFalse(traffic.Networks.load(str(broken)).hosting('3.1.2.3'))


class SignalTests(unittest.TestCase):
    wanted = {'2026-10-07'}
    since = datetime(2026, 10, 7, tzinfo=timezone.utc)
    zones = {'Europe/Berlin': {'DE'}, 'Europe/Moscow': {'RU'}}
    page = '/reviews/candy-ai/'

    def count(self, clicks, views, signals, since=since):
        return traffic.count(clicks, views, self.wanted, signals, zones=self.zones, since=since)

    def opened(self, ip='203.0.113.1', t='2026-10-07T11:59:00+00:00'):
        return view(self.page, ip=ip, t=t)

    def test_a_page_whose_script_found_a_driver_is_a_program(self):
        days = self.count([], [self.opened(), self.opened('203.0.113.2'), self.opened('203.0.113.3')], [
            signal(flags='wd'), signal(OTHER_ID, flags='mt,sw', ip='203.0.113.2'), signal('aaaaaaaaaaaaaaaa', ip='203.0.113.3'),
        ])
        self.assertEqual(verdicts(days, 'views'), {('bot', 'automation'): 1, ('bot', 'spoofed'): 1, HUMAN: 1})

    def test_a_page_whose_script_never_reported_is_in_doubt_once_scripts_report(self):
        self.assertEqual(verdicts(self.count([], [self.opened()], []), 'views'), {('suspect', 'no-signal'): 1})
        later = datetime(2026, 10, 7, 12, 30, tzinfo=timezone.utc)
        self.assertEqual(verdicts(self.count([], [self.opened()], [], since=later), 'views'), {HUMAN: 1})
        self.assertEqual(verdicts(self.count([], [self.opened()], [], since=None), 'views'), {HUMAN: 1})

    def test_a_signal_belongs_to_a_page_opened_shortly_before_on_the_same_site(self):
        days = self.count([], [self.opened(), self.opened('203.0.113.2')], [
            signal(t='2026-10-07T12:10:00+00:00'),  # eleven minutes later
            signal(OTHER_ID, ip='203.0.113.2', host='aigirlfriendranking.site'),
        ])
        self.assertEqual(verdicts(days, 'views'), {('suspect', 'no-signal'): 2})

    def test_a_signal_that_is_not_one_is_ignored(self):
        for uri in ('/_s?v=2&e=l&p=3f9a0c51d2e8b7a4', '/_s?v=1&e=i&p=3f9a0c51d2e8b7a4', '/_s?v=1&e=l&p=XYZ',
                    '/elsewhere?v=1&e=l&p=3f9a0c51d2e8b7a4'):
            with self.subTest(uri=uri):
                self.assertIsNone(traffic.parse_signal({**signal(), 'uri': uri}))
        self.assertIsNone(traffic.parse_signal({**signal(), 'ref': 'https://evil.example/reviews/candy-ai/'}))
        parsed = traffic.parse_signal(signal(flags='wd,zz,gl', zone='<script>'))
        self.assertEqual(parsed.flags, {'wd', 'gl'})
        self.assertEqual(parsed.zone, '')

    def test_a_press_is_judged_by_how_it_was_made(self):
        def press(s, ip):
            return click(f'/go/candy-ai/?from=hero&s={s}', ip=ip, t='2026-10-07T12:00:00+00:00')
        cases = {
            '203.0.113.11': (f'{PAGE_ID}.5000.40.m1', HUMAN),
            '203.0.113.12': (f'{PAGE_ID}.0.40.m1', ('suspect', 'instant-click')),
            '203.0.113.13': (f'{PAGE_ID}.5000.1.m1', ('suspect', 'no-movement')),
            '203.0.113.14': (f'{PAGE_ID}.5000.0.k1', HUMAN),
            '203.0.113.15': (f'{PAGE_ID}.5000.0.t1', HUMAN),
            '203.0.113.16': (f'{PAGE_ID}.5000.40.m0', ('bot', 'synthetic-click')),
            '203.0.113.17': (f'{OTHER_ID}.5000.40.m1', ('suspect', 'no-signal')),
            '203.0.113.18': ('nonsense', ('suspect', 'no-signal')),
        }
        for ip, (s, expected) in cases.items():
            with self.subTest(s=s):
                days = self.count([press(s, ip)], [self.opened(ip)], [signal(ip=ip)])
                self.assertEqual(verdicts(days, 'clicks'), {expected: 1})

    def test_a_press_on_a_page_a_program_opened_is_the_program_s(self):
        days = self.count([click(f'/go/candy-ai/?from=hero&s={PAGE_ID}.5000.40.m1')], [self.opened()], [signal(flags='cdp')])
        self.assertEqual(verdicts(days, 'clicks'), {('bot', 'automation'): 1})

    def test_a_time_zone_from_another_country_is_in_doubt(self):
        for zone, expected in (('Europe/Moscow', ('suspect', 'tz-mismatch')), ('Europe/Berlin', HUMAN),
                               ('UTC', HUMAN), ('Mars/Olympus', HUMAN)):
            with self.subTest(zone=zone):
                days = self.count([], [self.opened()], [signal(zone=zone)])
                self.assertEqual(verdicts(days, 'views'), {expected: 1})

    def test_time_zones_are_read_from_the_system_tables(self):
        with tempfile.TemporaryDirectory() as directory:
            table = Path(directory, 'zone1970.tab')
            table.write_text('# comment\nDE,DK,NO\t+5230+01322\tEurope/Berlin\nRU\t+554521+0373704\tEurope/Moscow\tMSK\n', encoding='utf-8')
            zones = traffic.load_zones((str(table), os.path.join(directory, 'missing.tab')))
        self.assertEqual(zones['Europe/Berlin'], {'DE', 'DK', 'NO'})
        self.assertTrue(traffic.zone_mismatch('Europe/Moscow', 'DE', zones))
        self.assertFalse(traffic.zone_mismatch('Etc/GMT+3', 'DE', zones))
        self.assertFalse(traffic.zone_mismatch('Europe/Moscow', 'T1', zones))

    def test_the_start_of_signals_is_a_moment_or_nothing(self):
        self.assertIsNone(traffic.since_of(''))
        self.assertIsNone(traffic.since_of('soon'))
        self.assertEqual(traffic.since_of('2026-10-14T10:00:00+00:00'), datetime(2026, 10, 14, 10, tzinfo=timezone.utc))


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

            log('aigf-clicks.log', [click(f'/go/candy-ai/?from=hero&s={PAGE_ID}.5000.40.m1')])
            log('aigf-clicks.log.2.gz', [click('/go/candy-ai/?from=card', ip='203.0.113.5', t='2026-10-06T10:00:00+00:00')], packed=True)
            log('aigf-views.log', [view('/reviews/candy-ai/', ip='203.0.113.1')])
            log('aigf-signals.log', [signal(flags='wd')])
            written = traffic.run(datetime(2026, 10, 7, 12, 30, tzinfo=timezone.utc), logs, output,
                                  asn_file=os.path.join(logs, 'none.tsv.gz'), zones=(),
                                  since=datetime(2026, 10, 7, tzinfo=timezone.utc))
            self.assertEqual(written, ['2026-10-06', '2026-10-07'])
            today = json.loads(Path(output, '2026-10-07.json').read_text(encoding='utf-8'))
            self.assertEqual(today['version'], 2)
            self.assertEqual(today['generatedAt'], '2026-10-07T12:30:00+00:00')
            self.assertEqual(today['hosts'][HOST]['views'][0]['views'], 1)
            self.assertEqual(today['hosts'][HOST]['views'][0]['reason'], 'automation')
            pressed = today['hosts'][HOST]['clicks'][0]
            self.assertEqual((pressed['placement'], pressed['source'], pressed['reason']), ('hero', 'google', 'automation'))
            yesterday = json.loads(Path(output, '2026-10-06.json').read_text(encoding='utf-8'))
            self.assertEqual(yesterday['hosts'][HOST]['clicks'][0]['placement'], 'card')
            # Nobody's address, browser or page id leaves the script.
            for name in os.listdir(output):
                text = Path(output, name).read_text(encoding='utf-8')
                for secret in ('203.0.113', 'iPhone', PAGE_ID, 'Europe/Berlin'):
                    self.assertNotIn(secret, text)

    def test_an_open_day_with_nothing_logged_is_still_written_as_counted(self):
        with tempfile.TemporaryDirectory() as logs, tempfile.TemporaryDirectory() as output:
            written = traffic.run(datetime(2026, 10, 7, 0, 5, tzinfo=timezone.utc), logs, output,
                                  asn_file=os.path.join(logs, 'none.tsv.gz'), zones=())
            self.assertEqual(written, ['2026-10-06', '2026-10-07'])
            self.assertEqual(json.loads(Path(output, '2026-10-07.json').read_text())['hosts'], {})


if __name__ == '__main__':
    unittest.main()
