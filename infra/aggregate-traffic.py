#!/usr/bin/env python3
"""Turns the network's Nginx logs into one file of totals per day.

Installed root-owned at /usr/local/sbin/aigf-traffic by converge.sh and run by
aigf-traffic.timer every ten minutes. Reads three logs the vhosts write as
JSON lines (log formats in infra/nginx/http/traffic.conf):

  /var/log/nginx/aigf-clicks.log*   every request to /go/<product>/: a press of a partner button
  /var/log/nginx/aigf-views.log*    every page a reader was given
  /var/log/nginx/aigf-signals.log*  what a page's script says about the browser it runs in

and writes /var/lib/aigf-traffic/<YYYY-MM-DD>.json (days in UTC), which
ConvertStudio's traffic report reads (CSTUDIO_TRAFFIC). Today and yesterday
are counted again on every run; an older day is written once, when a log still
holds it and its file is missing.

Every view and press is counted with how sure the counter is that a person
made it — human, suspect or bot — and the one reason that decided it (see
REASONS). A press is counted with where its reader's visit came from.

No address, browser string, network or page id of a reader leaves this
script. They are used only in memory: to tie a reader's pages to their
presses and to the page's own script, to tell a second press from a first,
and to know a data centre's network.
"""
from bisect import bisect_right
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
import glob
import gzip
import ipaddress
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from urllib.parse import parse_qs, unquote, urlsplit

LOGS = os.environ.get('AIGF_TRAFFIC_LOGS', '/var/log/nginx')
OUTPUT = os.environ.get('AIGF_TRAFFIC_DIR', '/var/lib/aigf-traffic')
# iptoasn.com's table of who announces which addresses, fetched by aigf-asn.timer.
ASN_FILE = os.environ.get('AIGF_ASN_FILE', '/var/lib/aigf-asn/ip2asn-combined.tsv.gz')
# The moment every site had the script that reports on its browser. Before it,
# a page without a signal says nothing about its reader. Empty: not yet.
SIGNALS_SINCE = os.environ.get('AIGF_SIGNALS_SINCE', '')
# The countries of each time zone, as the system's tz database lists them.
ZONES = ('/usr/share/zoneinfo/zone1970.tab', '/usr/share/zoneinfo/zone.tab')
# Days a file is rewritten after the fact: today, and yesterday for the
# requests logged around midnight.
OPEN_DAYS = 2
# How far back a missing day is filled in: the logs keep two weeks.
BACKFILL_DAYS = 14

BOT, SUSPECT, HUMAN = 'bot', 'suspect', 'human'
# Why a view or a press is not a person's, strongest first: a row carries the
# first of these that holds. A bot is certainly not a person; a suspect may be
# one behind a VPN, on a journey, or in a browser that hides itself.
REASONS = {
    'declared': BOT,          # says it is a robot or a tool, or is no browser at all
    'forged-ua': BOT,         # a user-agent no browser sends
    'outdated-ua': BOT,       # a browser years out of date
    'prefetch': BOT,          # a browser fetching ahead of its reader
    'no-fetch-meta': BOT,     # no Sec-Fetch headers of a navigation, from a browser that sends them
    'no-referer': BOT,        # a press that names no page of this site
    'automation': BOT,        # the page's script found a driver, DevTools or a headless browser
    'synthetic-click': BOT,   # a press a script made, not a hand
    'spoofed': BOT,           # the browser contradicts itself: an antidetect browser or a borrowed user-agent
    'crawl-rate': BOT,        # pages opened faster than anyone reads
    'burst': BOT,             # partner buttons pressed one after another
    'hosting': SUSPECT,       # from a data centre's network
    'no-view': SUSPECT,       # a press by a reader who opened no page
    'no-signal': SUSPECT,     # the page's script never reported: it did not run
    'software-gpu': SUSPECT,  # drawn without a graphics card, as headless browsers and virtual machines do
    'tz-mismatch': SUSPECT,   # the browser's time zone is not one of its country's
    'instant-click': SUSPECT, # pressed within a second of the page being shown
    'no-movement': SUSPECT,   # pressed with a mouse that never moved to the button
}
ORDER = {reason: index for index, reason in enumerate(REASONS)}

GO = re.compile(r'/go/([A-Za-z0-9_-]{1,100})/(?:index\.html)?')
PLACEMENT = re.compile(r'[a-z-]{1,20}')
COUNTRY = re.compile(r'[A-Z0-9]{2}')
# Whatever is not a browser, or says it is a robot, is counted apart.
ROBOT = re.compile(
    r'bot|crawl|spider|slurp|preview|lighthouse|headless|facebookexternalhit|google-|mediapartners|'
    r'python|curl|wget|httpclient|okhttp|java/|go-http|axios|node-fetch|scrapy|libwww|phantom|'
    r'selenium|puppeteer|playwright|monitor|uptime|pingdom|\+https?:', re.I)
TABLET = re.compile(r'iPad|Tablet|PlayBook|Silk|Kindle|Android(?!.*Mobile)', re.I)
MOBILE = re.compile(r'Mobi|iPhone|iPod|Android|Windows Phone|IEMobile|Opera Mini', re.I)
# Where a reader came from, by the address of the page that sent them.
SOURCES = [
    ('google', r'(^|\.)google\.[a-z.]+$|(^|\.)googleusercontent\.com$'),
    ('bing', r'(^|\.)bing\.com$'),
    ('yandex', r'(^|\.)yandex\.[a-z.]+$|(^|\.)ya\.ru$'),
    ('duckduckgo', r'(^|\.)duckduckgo\.com$'),
    ('yahoo', r'(^|\.)yahoo\.[a-z.]+$'),
    ('ecosia', r'(^|\.)ecosia\.org$'),
    ('brave', r'^search\.brave\.com$'),
    ('naver', r'(^|\.)naver\.com$'),
    ('baidu', r'(^|\.)baidu\.com$'),
    ('seznam', r'(^|\.)seznam\.cz$'),
    ('ai', r'(^|\.)(chatgpt\.com|openai\.com|perplexity\.ai|claude\.ai|copilot\.microsoft\.com|gemini\.google\.com)$'),
    ('social', r'(^|\.)(facebook\.com|fb\.com|instagram\.com|t\.co|x\.com|twitter\.com|reddit\.com|threads\.net|'
               r'threads\.com|pinterest\.[a-z.]+|youtube\.com|tiktok\.com|linkedin\.com|t\.me|vk\.com|mastodon\.[a-z.]+)$'),
]
SOURCES = [(name, re.compile(pattern)) for name, pattern in SOURCES]
# A campaign's own name for itself, from utm_source or src in the address.
CAMPAIGN = re.compile(r'[^a-z0-9._-]')
# Names past these on one site in one day are one name: anyone can invent them.
CAMPAIGNS_PER_DAY = 50


# Browsers update themselves; a scraper copies one user-agent string from an
# old list and keeps it. Chrome and Firefox ship a major version every four
# weeks, so how far behind a version is can be told from the day it was seen.
CHROME = re.compile(r'(?:Chrome|CriOS)/(\d+)')
FIREFOX = re.compile(r'(?:Firefox|FxiOS)/(\d+)')
IOS = re.compile(r'(?:iPhone|iPad|iPod)[^)]* OS (\d+)_(\d+)')
SAFARI = re.compile(r'Version/(\d+)\.?(\d*)[.\d]* (?:Mobile/\S+ )?Safari/')
CHROME_ANCHOR = (128, date(2024, 8, 20))
FIREFOX_ANCHOR = (128, date(2024, 7, 9))
# About fifteen months of Chrome releases, room for a delayed update; two years of Firefox, as long as
# an extended-support release lives.
CHROME_LAG = 17
FIREFOX_LAG = 26
# iOS 15 is the last system of phones sold in 2015-2016; older is a copied string.
OLDEST_APPLE = 15


def current(anchor, day):
    version, released = anchor
    return version + (day - released).days // 28


def malformed(agent):
    """A user-agent no browser sends: assembled by hand."""
    if 'AppleWebKit/' in agent and '(KHTML, like Gecko)' not in agent:
        return True
    if 'Chrome/' in agent and 'AppleWebKit/' not in agent:
        return True
    return bool(re.search(r'Windows NT (?:5\.|6\.0)|Windows 9[58]', agent))


def outdated(agent, day):
    found = CHROME.search(agent)
    if found and int(found.group(1)) < current(CHROME_ANCHOR, day) - CHROME_LAG:
        return True
    found = FIREFOX.search(agent)
    if found and int(found.group(1)) < current(FIREFOX_ANCHOR, day) - FIREFOX_LAG:
        return True
    found = IOS.search(agent)
    if found and int(found.group(1)) < OLDEST_APPLE:
        return True
    found = SAFARI.search(agent)
    return bool(found and 'Chrome/' not in agent and int(found.group(1)) < OLDEST_APPLE)


def agent_reasons(agent, day=None):
    """What a user-agent alone says: no browser at all, a program that says what it is, a string no browser sends, or a browser years out of date."""
    reasons = []
    # "compatible;" is how a program borrows a browser's string and says what it is.
    if not agent.startswith('Mozilla/') or ROBOT.search(agent) or 'compatible;' in agent:
        reasons.append('declared')
    if malformed(agent):
        reasons.append('forged-ua')
    if day is not None and outdated(agent, day):
        reasons.append('outdated-ua')
    return reasons


def is_robot(agent, day=None):
    return bool(agent_reasons(agent, day))


def prefetched(record):
    """A browser fetching ahead of the reader, not the reader opening anything."""
    purpose = str(record.get('sp', '')).lower()
    return 'prefetch' in purpose or 'prerender' in purpose


def sends_fetch_metadata(agent):
    """
    Whether a browser sends Sec-Fetch headers at all: Chrome since 76, Firefox
    since 90, Safari since 16.4. On iPhones and iPads every browser is Safari
    underneath, so there the system's version decides.
    """
    found = IOS.search(agent)
    if found:
        return (int(found.group(1)), int(found.group(2))) >= (16, 4)
    found = re.search(r'Chrome/(\d+)', agent)
    if found:
        return int(found.group(1)) >= 76
    found = FIREFOX.search(agent)
    if found:
        return int(found.group(1)) >= 90
    found = SAFARI.search(agent)
    return bool(found) and (int(found.group(1)), int(found.group(2) or 0)) >= (16, 4)


def navigated(record, dest, agent=''):
    """
    A browser that sends Sec-Fetch headers says how a request came about: a
    page a person opens is a navigation to a document, a partner button a
    person presses is a navigation from the same site with user activation.
    Most scrapers send none of them; so do browsers older than the headers. A
    line logged before these fields were recorded has no opinion and passes.
    """
    if 'sfm' not in record:
        return True
    if not record.get('sfm'):
        return not sends_fetch_metadata(agent)
    if record.get('sfm') != 'navigate':
        return False
    if dest == 'document':
        return record.get('sfd') == 'document'
    return record.get('sfs') in ('same-origin', 'same-site') and record.get('sfu') == '?1'


def header_reasons(record, agent, day, dest):
    """What the request itself says about who made it."""
    reasons = agent_reasons(agent, day)
    if dest == 'button' and record.get('method') != 'GET' and 'declared' not in reasons:
        reasons.append('declared')
    if prefetched(record):
        reasons.append('prefetch')
    if not navigated(record, dest, agent):
        reasons.append('no-fetch-meta')
    return reasons


def verdict(reasons):
    """How sure the counter is, and the strongest reason it has: a row's quality and reason."""
    if not reasons:
        return HUMAN, ''
    strongest = min(reasons, key=ORDER.__getitem__)
    return REASONS[strongest], strongest


# Several partner buttons pressed one after another, a few seconds apart, is
# a program walking a page's links: no person opens four partners in a minute.
BURST_SECONDS = 60
BURST_CLICKS = 4
BURST_PRODUCTS = 3
DAILY_CLICKS = 10
# Nor does anyone read twenty pages in a minute, or a hundred and fifty in a day.
CRAWL_SECONDS = 60
CRAWL_VIEWS = 20
DAILY_VIEWS = 150


def bursts(presses):
    """Whether one reader's presses on one site in one day look like a program: (moment, product) pairs."""
    presses = sorted(presses)
    if len(presses) >= DAILY_CLICKS:
        return True
    for start, (moment, _) in enumerate(presses):
        window = [product for at, product in presses[start:] if (at - moment).total_seconds() <= BURST_SECONDS]
        if len(window) >= BURST_CLICKS and len(set(window)) >= BURST_PRODUCTS:
            return True
    return False


def crawls(moments):
    """Whether one reader's page views on one site in one day come faster than anyone reads."""
    moments = sorted(moments)
    if len(moments) >= DAILY_VIEWS:
        return True
    # Every run of CRAWL_VIEWS pages in a row. Fewer pages than that hold none; a
    # slice with a negative end would count from the back and read past the list.
    return any((moments[index + CRAWL_VIEWS - 1] - moments[index]).total_seconds() <= CRAWL_SECONDS
               for index in range(len(moments) - CRAWL_VIEWS + 1))


# Networks people browse from although a cloud company runs them: Cloudflare
# (WARP, and a third of iCloud Private Relay), Akamai and Fastly (the rest of
# Private Relay), and Apple itself.
NEVER_HOSTING = {13335, 20940, 16625, 54113, 714, 6185}
# Data centres that rent servers to anyone, scrapers included. A starting
# list: an owner's name that says hosting, cloud or servers also counts.
HOSTING_ASNS = {
    16509: 'Amazon AWS', 14618: 'Amazon AWS', 396982: 'Google Cloud', 8075: 'Microsoft Azure',
    31898: 'Oracle Cloud', 45102: 'Alibaba Cloud', 132203: 'Tencent Cloud', 136907: 'Huawei Cloud',
    14061: 'DigitalOcean', 24940: 'Hetzner', 213230: 'Hetzner Cloud', 16276: 'OVH', 63949: 'Linode',
    20473: 'Vultr', 51167: 'Contabo', 40021: 'Contabo', 60781: 'Leaseweb', 28753: 'Leaseweb',
    16265: 'Leaseweb', 30633: 'Leaseweb', 12876: 'Scaleway', 8560: 'IONOS', 9009: 'M247',
    60068: 'DataCamp', 197540: 'netcup', 47583: 'Hostinger', 36352: 'ColoCrossing', 53667: 'FranTech',
    49981: 'WorldStream', 62240: 'Clouvider', 8100: 'QuadraNet',
}
HOSTING_NAME = re.compile(r'\b(?:hosting|cloud|servers?|data ?cent(?:er|re)s?|colocation|vps|dedicated)\b', re.I)


class Networks:
    """
    The address ranges of data centres, from iptoasn.com's table of who
    announces which addresses. Only those ranges are kept, not the whole
    Internet, and only to answer one question: is this a data centre?
    """

    def __init__(self, ranges=()):
        self.ranges = {4: ([], []), 6: ([], [])}
        for start, end in sorted(ranges, key=lambda pair: (pair[0].version, int(pair[0]))):
            starts, ends = self.ranges[start.version]
            starts.append(int(start))
            ends.append(int(end))

    @classmethod
    def load(cls, path):
        ranges = []
        try:
            with gzip.open(path, 'rt', encoding='utf-8', errors='replace') as stream:
                for line in stream:
                    parts = line.rstrip('\n').split('\t', 4)
                    if len(parts) < 5 or not parts[2].isdigit():
                        continue
                    number = int(parts[2])
                    if number == 0 or number in NEVER_HOSTING:
                        continue
                    if number not in HOSTING_ASNS and not HOSTING_NAME.search(parts[4]):
                        continue
                    try:
                        ranges.append((ipaddress.ip_address(parts[0]), ipaddress.ip_address(parts[1])))
                    except ValueError:
                        continue
        except (OSError, EOFError, ValueError) as error:
            # Without the table nothing is a data centre: a missing judgement, not a broken count.
            print(f'aigf-traffic: no table of networks ({error}); data centres are not told apart', file=sys.stderr)
            return cls()
        return cls(ranges)

    def hosting(self, address):
        try:
            value = ipaddress.ip_address(address)
        except ValueError:
            return False
        starts, ends = self.ranges[value.version]
        index = bisect_right(starts, int(value)) - 1
        return index >= 0 and int(value) <= ends[index]


# What a page's script may say about its browser, and what it means.
FLAGS = {
    'wd': 'automation', 'drv': 'automation', 'cdp': 'automation', 'hl': 'automation',
    'mt': 'spoofed', 'os': 'spoofed', 'wk': 'spoofed', 'gl': 'spoofed',
    'sw': 'software-gpu',
}
PAGE_ID = re.compile(r'[0-9a-f]{16}')
ZONE = re.compile(r'[A-Za-z_]+(?:/[A-Za-z0-9_+-]+){0,2}')
# A press as the script appends it to /go/: page id, milliseconds since the
# page was shown, mouse moves before it, how it was pressed (mouse, touch,
# pen, keyboard) and whether the browser says a person did it.
PRESS = re.compile(r'([0-9a-f]{16})\.(\d{1,7})\.(\d{1,3})\.([mtpk])([01])')
# A page's script reports within seconds of the page arriving; past this it is another page.
SIGNAL_SECONDS = 600
INSTANT_MS = 1000


def load_zones(paths=ZONES):
    """Time zone -> the countries it is used in, from the system's tz tables."""
    zones = defaultdict(set)
    for path in paths:
        try:
            with open(path, encoding='utf-8') as stream:
                for line in stream:
                    if line.startswith('#'):
                        continue
                    parts = line.rstrip('\n').split('\t')
                    if len(parts) >= 3:
                        zones[parts[2]].update(code.upper() for code in parts[0].split(','))
        except OSError:
            continue
    return zones


def zone_mismatch(zone, code, zones):
    """
    A browser in a time zone none of whose countries is the one its address is
    in. UTC is what Tor and fingerprint-resisting browsers say, and an
    unknown zone or country says nothing.
    """
    if not zone or zone == 'UTC' or zone.startswith('Etc/') or not code or code in ('XX', 'T1'):
        return False
    countries = zones.get(zone)
    return bool(countries) and code not in countries


def device(agent):
    if TABLET.search(agent):
        return 'tablet'
    return 'mobile' if MOBILE.search(agent) else 'desktop'


def country(code):
    code = (code or '').upper()
    return code if COUNTRY.fullmatch(code) else ''


def path_of(address):
    """A page as the report names it: the decoded path, without a query."""
    return unquote(urlsplit(address).path)[:500]


def source(referer, host, network):
    if not referer:
        return 'direct'
    origin = (urlsplit(referer).hostname or '').lower()
    if origin.startswith('www.'):
        origin = origin[4:]
    if origin == host or origin in network:
        return 'internal'
    for name, pattern in SOURCES:
        if pattern.search(origin):
            return name
    return 'other'


def campaign(uri):
    """The source a link names for itself (utm_source, or src), as utm:<name>; None when it names none."""
    query = parse_qs(urlsplit(uri).query)
    name = CAMPAIGN.sub('', ((query.get('utm_source') or query.get('src') or [''])[0]).lower())[:30]
    return f'utm:{name}' if name else None


def day_of(stamp):
    """The UTC day of an Nginx $time_iso8601, or None for a line that has none."""
    moment = moment_of(stamp)
    return moment.date().isoformat() if moment else None


def moment_of(stamp):
    try:
        return datetime.fromisoformat(stamp).astimezone(timezone.utc)
    except (TypeError, ValueError):
        return None


def lines(pattern, since):
    """Every line of a log and its rotated copies modified since a moment."""
    for name in sorted(glob.glob(pattern)):
        try:
            if os.stat(name).st_mtime < since:
                continue
            opener = gzip.open if name.endswith('.gz') else open
            with opener(name, 'rt', encoding='utf-8', errors='replace') as stream:
                yield from stream
        except OSError:
            continue


def records(pattern, since):
    for line in lines(pattern, since):
        try:
            record = json.loads(line)
        except ValueError:
            continue
        if isinstance(record, dict):
            yield record


def reader_of(record, host, agent):
    """Who made a request, as far as one site can tell: their address and their browser. None when the line has no address."""
    address = str(record.get('cfip') or record.get('ip') or '')
    return (host, address, agent) if address else None


class Visit:
    """A page a reader was given, and what is known of who they are."""
    __slots__ = ('date', 'moment', 'host', 'reader', 'page', 'source', 'country', 'device', 'reasons', 'signal')


class Signal:
    """What one page's script said about the browser it ran in."""
    __slots__ = ('host', 'page_id', 'moment', 'reader', 'page', 'flags', 'zone')


class Press:
    """A press of a partner button."""
    __slots__ = ('date', 'moment', 'host', 'reader', 'unique_reader', 'record', 'product', 'placement',
                 'page', 'country', 'device', 'reasons', 'press', 'source')


def parse_signal(record):
    """A page script's report, or None for a line that is not one."""
    address = urlsplit(str(record.get('uri', '')))
    if address.path != '/_s':
        return None
    query = parse_qs(address.query)

    def one(name):
        return (query.get(name) or [''])[0]

    host = str(record.get('host', '')).lower()
    moment = moment_of(record.get('t'))
    referer = urlsplit(str(record.get('ref', '')))
    if (one('v') != '1' or one('e') != 'l' or not PAGE_ID.fullmatch(one('p')) or not host or not moment
            or (referer.hostname or '').lower() not in (host, f'www.{host}')):
        return None
    signal = Signal()
    signal.host, signal.page_id, signal.moment = host, one('p'), moment
    signal.reader = reader_of(record, host, str(record.get('ua', '')))
    signal.page = path_of(referer.geturl())
    signal.flags = {flag for flag in one('f').split(',') if flag in FLAGS}
    zone = one('z')
    signal.zone = zone if len(zone) <= 64 and ZONE.fullmatch(zone) else ''
    return signal


def signal_reasons(signal, code, zones):
    reasons = {FLAGS[flag] for flag in signal.flags}
    if zone_mismatch(signal.zone, code, zones):
        reasons.add('tz-mismatch')
    return reasons


def count(click_records, view_records, wanted, signal_records=(), networks=None, zones=None, since=None):
    """
    Totals per day and host for the days wanted, from parsed log records.
    `since` is the moment pages began to carry the script that reports on the
    browser: only what came after it is judged by what that script says.
    """
    networks = networks or Networks()
    zones = zones if zones is not None else {}
    judged = (lambda moment: since is not None and moment >= since)

    # Pages first: every one read, whatever day, because a press today may
    # belong to a visit that began yesterday.
    view_records = list(view_records)
    network = {str(record.get('host', '')).lower() for record in view_records}
    visits = []
    for record in view_records:
        moment = moment_of(record.get('t'))
        host = str(record.get('host', '')).lower()
        if not moment or not host:
            continue
        visit = Visit()
        visit.date, visit.moment, visit.host = moment.date().isoformat(), moment, host
        agent = str(record.get('ua', ''))
        visit.reader = reader_of(record, host, agent)
        uri = str(record.get('uri', ''))
        visit.page, visit.country, visit.device = path_of(uri), country(record.get('cc')), device(agent)
        visit.source = campaign(uri) or source(str(record.get('ref', '')), host, network)
        visit.reasons = set(header_reasons(record, agent, moment.date(), 'document'))
        visit.signal = None
        visits.append(visit)
    visits.sort(key=lambda visit: visit.moment)
    # Addresses are in the log of pages only from some moment on: before it, a
    # press with no page behind it says nothing about its reader.
    readers_since = min((visit.moment for visit in visits if visit.reader), default=None)

    by_reader = defaultdict(list)
    for visit in visits:
        if visit.reader:
            by_reader[visit.reader].append(visit)
    crawled = set()
    for reader, seen in by_reader.items():
        by_day = defaultdict(list)
        for visit in seen:
            by_day[visit.date].append(visit.moment)
        crawled.update((reader, day) for day, moments in by_day.items() if crawls(moments))
    for visit in visits:
        if (visit.reader, visit.date) in crawled:
            visit.reasons.add('crawl-rate')
        if visit.reader and networks.hosting(visit.reader[1]):
            visit.reasons.add('hosting')

    # What each page's script said, tied to the page it was said on: the same
    # reader, the same address, the nearest page given before it.
    signals = {}
    for record in signal_records:
        signal = parse_signal(record)
        if not signal:
            continue
        known = signals.get((signal.host, signal.page_id))
        if known:
            known.flags |= signal.flags
            known.zone = known.zone or signal.zone
            continue
        signals[(signal.host, signal.page_id)] = signal
    by_page = defaultdict(list)
    for visit in visits:
        if visit.reader:
            by_page[(visit.reader, visit.page)].append(visit)
    for signal in sorted(signals.values(), key=lambda signal: signal.moment):
        for visit in reversed(by_page.get((signal.reader, signal.page), [])):
            if visit.moment > signal.moment:
                continue
            if (signal.moment - visit.moment).total_seconds() > SIGNAL_SECONDS:
                break
            if visit.signal is None:
                visit.signal = signal
                break
    for visit in visits:
        if not judged(visit.moment):
            continue
        if visit.signal:
            visit.reasons |= signal_reasons(visit.signal, visit.country, zones)
        else:
            visit.reasons.add('no-signal')

    # Presses: each judged on its own, then with the reader's other presses of
    # the day, then against the pages the reader opened.
    presses = []
    for record in click_records:
        moment = moment_of(record.get('t'))
        host = str(record.get('host', '')).lower()
        address = urlsplit(str(record.get('uri', '')))
        product = GO.fullmatch(address.path)
        if not moment or not host or not product or str(record.get('status')) not in ('200', '302', '304'):
            continue
        press = Press()
        press.date, press.moment, press.host, press.record = moment.date().isoformat(), moment, host, record
        agent = str(record.get('ua', ''))
        press.reader = reader_of(record, host, agent)
        # Behind Cloudflare the connection comes from its edge; the reader's own
        # address is the one Cloudflare names.
        press.unique_reader = (press.date, host, str(record.get('cfip') or record.get('ip', '')), agent)
        query = parse_qs(address.query)
        press.product = product.group(1)
        placement = (query.get('from') or [''])[0]
        press.placement = placement if PLACEMENT.fullmatch(placement) else ''
        referer = urlsplit(str(record.get('ref', '')))
        press.page, press.country, press.device = path_of(referer.geturl()), country(record.get('cc')), device(agent)
        press.reasons = set(header_reasons(record, agent, moment.date(), 'button'))
        # A person presses a button on a page of this site, and the browser names the page.
        if (referer.hostname or '').lower() not in (host, f'www.{host}'):
            press.reasons.add('no-referer')
        press.press = PRESS.fullmatch((query.get('s') or [''])[0])
        presses.append(press)
    presses.sort(key=lambda press: press.moment)

    made = defaultdict(list)
    for press in presses:
        if not press.reasons:
            made[press.unique_reader].append((press.moment, press.product))
    automated = {reader for reader, pairs in made.items() if bursts(pairs)}

    for press in presses:
        if press.unique_reader in automated and not press.reasons:
            press.reasons.add('burst')
        if (press.reader, press.date) in crawled:
            press.reasons.add('crawl-rate')
        if press.reader and networks.hosting(press.reader[1]):
            press.reasons.add('hosting')
        # The visit this press belongs to began on the last page the reader came
        # to from outside the site, today or yesterday.
        press.source = 'unknown'
        earliest = press.moment.date() - timedelta(days=1)
        opened = [visit for visit in by_reader.get(press.reader, [])
                  if visit.moment <= press.moment and visit.moment.date() >= earliest]
        entry = next((visit for visit in reversed(opened) if visit.source != 'internal'), None)
        if entry:
            press.source = entry.source
        if (not opened and press.reader and readers_since is not None
                and press.moment >= readers_since + timedelta(minutes=10)):
            press.reasons.add('no-view')
        if not judged(press.moment):
            continue
        signal = press.press and signals.get((press.host, press.press.group(1)))
        if not signal:
            press.reasons.add('no-signal')
            continue
        press.reasons |= signal_reasons(signal, press.country, zones)
        shown, moves, kind, trusted = int(press.press.group(2)), int(press.press.group(3)), press.press.group(4), press.press.group(5)
        if trusted == '0':
            press.reasons.add('synthetic-click')
        if shown < INSTANT_MS:
            press.reasons.add('instant-click')
        if kind == 'm' and moves <= 1:
            press.reasons.add('no-movement')

    # A site's campaign names past the most read ones are one name.
    campaigns = defaultdict(lambda: defaultdict(int))
    for visit in visits:
        if visit.source.startswith('utm:'):
            campaigns[(visit.date, visit.host)][visit.source] += 1
    kept = {key: {name for name, _ in sorted(names.items(), key=lambda item: (-item[1], item[0]))[:CAMPAIGNS_PER_DAY]}
            for key, names in campaigns.items()}

    def named(day, host, origin):
        return origin if not origin.startswith('utm:') or origin in kept.get((day, host), ()) else 'utm:other'

    days = {}

    def host_of(day, host):
        return days.setdefault(day, {}).setdefault(host, {'views': {}, 'clicks': {}})

    for visit in visits:
        if visit.date not in wanted:
            continue
        quality, reason = verdict(visit.reasons)
        key = (visit.page, named(visit.date, visit.host, visit.source), visit.country, visit.device, quality, reason)
        counted = host_of(visit.date, visit.host)['views']
        counted[key] = counted.get(key, 0) + 1

    seen = set()
    for press in presses:
        if press.date not in wanted:
            continue
        quality, reason = verdict(press.reasons)
        key = (press.page, named(press.date, press.host, press.source), press.product, press.placement,
               press.country, press.device, quality, reason)
        first = (press.unique_reader, press.product) not in seen
        seen.add((press.unique_reader, press.product))
        counted = host_of(press.date, press.host)['clicks']
        clicks, unique = counted.get(key, (0, 0))
        counted[key] = (clicks + 1, unique + (1 if first else 0))
    return days


def serialise(date, hosts, generated):
    """A day as ConvertStudio reads it (trafficDaySchema, version 2), rows in a stable order."""
    return {
        'version': 2, 'date': date, 'generatedAt': generated,
        'hosts': {
            host: {
                'views': [{'page': page, 'source': origin, 'country': code, 'device': kind,
                           'quality': quality, 'reason': reason, 'views': total}
                          for (page, origin, code, kind, quality, reason), total in sorted(counted['views'].items())],
                'clicks': [{'page': page, 'source': origin, 'product': product, 'placement': placement,
                            'country': code, 'device': kind, 'quality': quality, 'reason': reason,
                            'clicks': clicks, 'unique': unique}
                           for (page, origin, product, placement, code, kind, quality, reason), (clicks, unique)
                           in sorted(counted['clicks'].items())],
            }
            for host, counted in sorted(hosts.items())
        },
    }


def write(directory, date, document):
    """One step: a reader of the directory sees the old day or the new one."""
    fd, tmp = tempfile.mkstemp(dir=directory, prefix=f'.{date}-', suffix='.tmp')
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as stream:
            json.dump(document, stream, ensure_ascii=False, separators=(',', ':'))
        os.chmod(tmp, 0o644)
        os.replace(tmp, Path(directory) / f'{date}.json')
    finally:
        Path(tmp).unlink(missing_ok=True)


def since_of(text):
    """The moment the script went live, from AIGF_SIGNALS_SINCE; None while it has not."""
    moment = moment_of(text.strip()) if text and text.strip() else None
    if text and text.strip() and moment is None:
        print(f'aigf-traffic: AIGF_SIGNALS_SINCE={text!r} is no moment; browser signals are not judged', file=sys.stderr)
    return moment


def run(now=None, logs=LOGS, output=OUTPUT, asn_file=ASN_FILE, zones=ZONES, since=None):
    now = now or datetime.now(timezone.utc)
    today = now.date()
    open_days = {(today - timedelta(days=offset)).isoformat() for offset in range(OPEN_DAYS)}
    missing = {
        (today - timedelta(days=offset)).isoformat() for offset in range(OPEN_DAYS, BACKFILL_DAYS + 1)
        if not (Path(output) / f'{(today - timedelta(days=offset)).isoformat()}.json').exists()
    }
    wanted = open_days | missing
    # A rotated log last written before the day before the oldest day wanted
    # holds none of it, nor any visit a press of it belongs to.
    oldest = (datetime.fromisoformat(min(wanted)).replace(tzinfo=timezone.utc) - timedelta(days=1)).timestamp()
    days = count(records(os.path.join(logs, 'aigf-clicks.log*'), oldest),
                 records(os.path.join(logs, 'aigf-views.log*'), oldest), wanted,
                 records(os.path.join(logs, 'aigf-signals.log*'), oldest),
                 Networks.load(asn_file), load_zones(zones), since if since is not None else since_of(SIGNALS_SINCE))
    Path(output).mkdir(mode=0o755, parents=True, exist_ok=True)
    generated = now.isoformat(timespec='seconds')
    written = []
    # An open day is written even when nothing happened yet: "counted, nothing
    # there" is not the same as "not counted".
    for date in sorted(open_days | set(days)):
        write(output, date, serialise(date, days.get(date, {}), generated))
        written.append(date)
    return written


if __name__ == '__main__':
    try:
        print('written: ' + ', '.join(run()))
    except Exception as error:  # A timer's failure is read in the journal.
        sys.exit(f'aigf-traffic: {error}')
