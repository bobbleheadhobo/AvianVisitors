"""Screenshot pages of the local site and report page errors.

Run through dev/shot.sh, which supplies the venv:

    dev/shot.sh '/#admin=settings' '/#sci=Haemorhous%20mexicanus'
    dev/shot.sh --device desktop --theme dark /
    dev/shot.sh --click '.rec-review-open' '/#sci=...&rec=...'

Pages load from the station's own Caddy (http://127.0.0.1 by default), so a
direct LAN request: admin pages open without a password unless the LAN gate
is on. HTTPS-only features (web app install, service workers) still need the
public proxy URL and a real phone.

Prints one line per screenshot and every page error or console error; exits
1 if there were any, so a clean run is a real signal.
"""
import argparse
import glob
import os
import re
import sys
import time

from playwright.sync_api import sync_playwright

DEVICES = {
    # Galaxy S25-ish, the owner's phone.
    'phone': {'viewport': {'width': 412, 'height': 915}, 'device_scale_factor': 2,
              'is_mobile': True, 'has_touch': True},
    # The owner's 1080p monitors at 1x.
    'desktop': {'viewport': {'width': 1920, 'height': 1080}, 'device_scale_factor': 1},
}


def chromium():
    found = sorted(glob.glob(os.path.expanduser(
        '~/.cache/ms-playwright/chromium_headless_shell-*/chrome-headless-shell-linux64/chrome-headless-shell')))
    if not found:
        sys.exit('no headless Chromium under ~/.cache/ms-playwright; ask the owner before downloading one')
    return found[-1]


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('paths', nargs='+', help="page paths, e.g. '/#admin=settings'")
    ap.add_argument('--base', default='http://127.0.0.1')
    ap.add_argument('--device', choices=['phone', 'desktop', 'both'], default='phone')
    ap.add_argument('--theme', choices=['light', 'dark'], default='light')
    ap.add_argument('--wait', type=int, default=3000, help='ms to let the page settle (default 3000)')
    ap.add_argument('--full', action='store_true', help='capture the full scrolling page')
    ap.add_argument('--click', action='append', default=[], help='CSS selector to click before the shot (repeatable)')
    ap.add_argument('--scroll-to', help='CSS selector to scroll into view before the shot')
    ap.add_argument('--out', default=os.environ.get('AVIAN_SHOT_DIR', '/tmp/avian-shots'))
    a = ap.parse_args()

    os.makedirs(a.out, exist_ok=True)
    devices = ['phone', 'desktop'] if a.device == 'both' else [a.device]
    problems = 0
    with sync_playwright() as p:
        browser = p.chromium.launch(executable_path=chromium())
        for dev in devices:
            ctx = browser.new_context(color_scheme=a.theme, **DEVICES[dev])
            for path in a.paths:
                page = ctx.new_page()
                errors = []
                page.on('pageerror', lambda e, errors=errors: errors.append('pageerror: %s' % e))
                page.on('console', lambda m, errors=errors: m.type == 'error' and errors.append('console: %s' % m.text))
                # 'load', not 'networkidle': the site polls and streams, so it is never idle.
                page.goto(a.base + path, wait_until='load')
                page.wait_for_timeout(a.wait)
                for sel in a.click:
                    page.click(sel)
                    page.wait_for_timeout(400)
                if a.scroll_to:
                    el = page.query_selector(a.scroll_to)
                    if el:
                        el.scroll_into_view_if_needed()
                        page.wait_for_timeout(400)
                    else:
                        errors.append('scroll-to: no element matches %s' % a.scroll_to)
                name = re.sub(r'[^A-Za-z0-9]+', '-', path).strip('-') or 'home'
                file = os.path.join(a.out, '%s-%s-%s-%d.png' % (name[:60], dev, a.theme, int(time.time())))
                page.screenshot(path=file, full_page=a.full)
                print(file)
                for e in errors:
                    print('  ' + e)
                problems += len(errors)
                page.close()
            ctx.close()
        browser.close()
    sys.exit(1 if problems else 0)


if __name__ == '__main__':
    main()
