#!/usr/bin/env python3
"""Turns the network's Nginx logs into one file of totals per day.

Installed root-owned at /usr/local/sbin/aigf-traffic by converge.sh and run by
aigf-traffic.timer every ten minutes. Reads two logs the vhosts write as JSON
lines (log formats in infra/nginx/http/traffic.conf):

  /var/log/nginx/aigf-clicks.log*  every request to /go/<product>/: a press of a partner button
  /var/log/nginx/aigf-views.log*   every page a reader was given

and writes /var/lib/aigf-traffic/<YYYY-MM-DD>.json (days in UTC), which
ConvertStudio's traffic report reads (CSTUDIO_TRAFFIC). Today and yesterday
are counted again on every run; an older day is written once, when a log still
holds it and its file is missing.

No address or browser string of a reader leaves this script. An address is
used only in memory, to tell a reader's second press of the same button on
the same day from a new reader's first.
"""
from collections import defaultdict
from datetime import date, datetime, timedelta, timezone
import glob
import gzip
import json
import os
from pathlib import Path
import re
import sys
import tempfile
from urllib.parse import parse_qs, unquote, urlsplit

LOGS = os.environ.get('AIGF_TRAFFIC_LOGS', '/var/log/nginx')
OUTPUT = os.environ.get('AIGF_TRAFFIC_DIR', '/var/lib/aigf-traffic')
# Days a file is rewritten after the fact: today, and yesterday for the
# requests logged around midnight.
OPEN_DAYS = 2
# How far back a missing day is filled in: the logs keep two weeks.
BACKFILL_DAYS = 14

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


# Browsers update themselves; a scraper copies one user-agent string from an
# old list and keeps it. Chrome and Firefox ship a major version every four
# weeks, so how far behind a version is can be told from the day it was seen.
CHROME = re.compile(r'(?:Chrome|CriOS)/(\d+)')
FIREFOX = re.compile(r'(?:Firefox|FxiOS)/(\d+)')
IOS = re.compile(r'(?:iPhone|iPad|iPod)[^)]* OS (\d+)_')
SAFARI = re.compile(r'Version/(\d+)[.\d]* (?:Mobile/\S+ )?Safari/')
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
    """A user-agent no browser sends: assembled by hand, or borrowed by a program that says what it is."""
    if 'compatible;' in agent:
        return True
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


def is_robot(agent, day=None):
    """Not a person: no browser at all, a program that says what it is, a string no browser sends, or a browser years out of date."""
    if not agent.startswith('Mozilla/') or ROBOT.search(agent) or malformed(agent):
        return True
    return day is not None and outdated(agent, day)


def prefetched(record):
    """A browser fetching ahead of the reader, not the reader opening anything."""
    purpose = str(record.get('sp', '')).lower()
    return 'prefetch' in purpose or 'prerender' in purpose


def navigated(record, dest):
    """
    A browser that sends Sec-Fetch headers says how a request came about: a
    page a person opens is a navigation to a document, a partner button a
    person presses is a navigation from the same site with user activation.
    Most scrapers send none of them. A line logged before these fields were
    recorded has no opinion and passes.
    """
    if 'sfm' not in record:
        return True
    if record.get('sfm') != 'navigate':
        return False
    if dest == 'document':
        return record.get('sfd') == 'document'
    return record.get('sfs') in ('same-origin', 'same-site') and record.get('sfu') == '?1'


# Several partner buttons pressed one after another, a few seconds apart, is
# a program walking a page's links: no person opens four partners in a minute.
BURST_SECONDS = 60
BURST_CLICKS = 4
BURST_PRODUCTS = 3
DAILY_CLICKS = 10


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


def day_of(stamp):
    """The UTC day of an Nginx $time_iso8601, or None for a line that has none."""
    try:
        return datetime.fromisoformat(stamp).astimezone(timezone.utc).date().isoformat()
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


def new_host():
    return {'views': {}, 'clicks': {}, 'bots': {'views': 0, 'clicks': 0}}


def count(click_records, view_records, wanted):
    """Totals per day and host for the days wanted, from parsed log records."""
    days = {}
    seen = set()
    views = list(view_records)
    network = {str(record.get('host', '')).lower() for record in views}

    def host_of(date, host):
        return days.setdefault(date, {}).setdefault(host, new_host())

    for record in views:
        date = day_of(record.get('t'))
        host = str(record.get('host', '')).lower()
        if date not in wanted or not host:
            continue
        counted = host_of(date, host)
        agent = str(record.get('ua', ''))
        if is_robot(agent, datetime.fromisoformat(date).date()) or prefetched(record) or not navigated(record, 'document'):
            counted['bots']['views'] += 1
            continue
        key = (path_of(str(record.get('uri', ''))), source(str(record.get('ref', '')), host, network),
               country(record.get('cc')), device(agent))
        counted['views'][key] = counted['views'].get(key, 0) + 1

    # A press is judged on its own first, then with the reader's other presses
    # of the day: one that passes alone may still be part of a burst.
    pressed = []
    for record in click_records:
        date = day_of(record.get('t'))
        host = str(record.get('host', '')).lower()
        address = urlsplit(str(record.get('uri', '')))
        product = GO.fullmatch(address.path)
        if date not in wanted or not host or not product or str(record.get('status')) not in ('200', '302', '304'):
            continue
        counted = host_of(date, host)
        agent = str(record.get('ua', ''))
        referer = urlsplit(str(record.get('ref', '')))
        # A person presses a button on a page of this site, and the browser names the page.
        from_site = (referer.hostname or '').lower() in (host, f'www.{host}')
        if (is_robot(agent, datetime.fromisoformat(date).date()) or record.get('method') != 'GET' or not from_site
                or prefetched(record) or not navigated(record, 'button')):
            counted['bots']['clicks'] += 1
            continue
        # Behind Cloudflare the connection comes from its edge; the reader's own
        # address is the one Cloudflare names.
        reader = (date, host, str(record.get('cfip') or record.get('ip', '')), agent)
        pressed.append((reader, datetime.fromisoformat(str(record.get('t'))), record, address, product.group(1), path_of(referer.geturl()), agent))

    presses = defaultdict(list)
    for reader, moment, _, _, product, _, _ in pressed:
        presses[reader].append((moment, product))
    automated = {reader for reader, made in presses.items() if bursts(made)}

    for reader, _, record, address, product, page, agent in pressed:
        counted = host_of(reader[0], reader[1])
        if reader in automated:
            counted['bots']['clicks'] += 1
            continue
        placement = (parse_qs(address.query).get('from') or [''])[0]
        key = (page, product, placement if PLACEMENT.fullmatch(placement) else '', country(record.get('cc')), device(agent))
        first = (reader, product) not in seen
        seen.add((reader, product))
        clicks, unique = counted['clicks'].get(key, (0, 0))
        counted['clicks'][key] = (clicks + 1, unique + (1 if first else 0))
    return days


def serialise(date, hosts, generated):
    """A day as ConvertStudio reads it (trafficDaySchema), rows in a stable order."""
    return {
        'version': 1, 'date': date, 'generatedAt': generated,
        'hosts': {
            host: {
                'views': [{'page': page, 'source': origin, 'country': code, 'device': kind, 'views': total}
                          for (page, origin, code, kind), total in sorted(counted['views'].items())],
                'clicks': [{'page': page, 'product': product, 'placement': placement, 'country': code,
                            'device': kind, 'clicks': clicks, 'unique': unique}
                           for (page, product, placement, code, kind), (clicks, unique) in sorted(counted['clicks'].items())],
                'bots': counted['bots'],
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


def run(now=None, logs=LOGS, output=OUTPUT):
    now = now or datetime.now(timezone.utc)
    today = now.date()
    open_days = {(today - timedelta(days=offset)).isoformat() for offset in range(OPEN_DAYS)}
    missing = {
        (today - timedelta(days=offset)).isoformat() for offset in range(OPEN_DAYS, BACKFILL_DAYS + 1)
        if not (Path(output) / f'{(today - timedelta(days=offset)).isoformat()}.json').exists()
    }
    wanted = open_days | missing
    # A rotated log last written before the oldest day wanted holds none of it.
    oldest = datetime.fromisoformat(min(wanted)).replace(tzinfo=timezone.utc).timestamp()
    days = count(records(os.path.join(logs, 'aigf-clicks.log*'), oldest),
                 records(os.path.join(logs, 'aigf-views.log*'), oldest), wanted)
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
