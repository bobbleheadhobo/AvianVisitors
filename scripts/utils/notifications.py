import apprise
import hashlib
import os
import socket
import requests
import html
from urllib.parse import quote
import time

from .db import get_todays_count_for, get_this_weeks_count_for
from .helpers import get_settings

userDir = os.path.expanduser('~')
APPRISE_CONFIG = userDir + '/BirdNET-Pi/apprise.txt'
APPRISE_BODY = userDir + '/BirdNET-Pi/body.txt'
PHOTO_CACHE = userDir + '/.cache/avian/notification-photos'
# Wikimedia asks for a descriptive User-Agent and throttles generic ones.
PHOTO_USER_AGENT = 'AvianVisitors/1.0 (BirdNET-Pi bird alert photos; https://github.com/Twarner491/AvianVisitors)'

apobj = None
images = {}
species_last_notified = {}


def notify(body, title, attached=""):
    global apobj
    if apobj is None:
        asset = apprise.AppriseAsset(
            plugin_paths=[
                userDir + "/.apprise/plugins",
                userDir + "/.config/apprise/plugins",
            ]
        )
        apobj = apprise.Apprise(asset=asset)
        config = apprise.AppriseConfig()
        config.add(APPRISE_CONFIG)
        apobj.add(config)

    if attached != "":
        apobj.notify(
            body=body,
            title=title,
            attach=attached,
        )
    else:
        apobj.notify(
            body=body,
            title=title,
        )


def local_photo(url):
    """The alert photo as a local file, downloaded once per URL and kept.

    Given a URL, Apprise downloads the attachment itself, tries once and sends
    the alert without it on any error. Wikimedia answers a thumbnail it has
    not cached yet with a 429 or a slow reply, so photos went missing (12 of
    78 Discord alerts on 2026-10-09). Returns "" when the photo can't be had.
    """
    if not url.startswith(('http://', 'https://')):
        return url
    ext = os.path.splitext(url.split('?', 1)[0])[1].lower()
    if ext not in ('.jpg', '.jpeg', '.png', '.gif', '.webp'):
        ext = '.jpg'
    path = os.path.join(PHOTO_CACHE, hashlib.sha1(url.encode()).hexdigest()[:16] + ext)
    if os.path.isfile(path) and os.path.getsize(path) > 0:
        return path

    for attempt in range(2):
        try:
            resp = requests.get(url, headers={'User-Agent': PHOTO_USER_AGENT}, timeout=10)
        except requests.RequestException as e:
            print("PHOTO DOWNLOAD ERROR:", e)
            continue
        if resp.status_code == 200 and resp.headers.get('Content-Type', '').startswith('image/'):
            os.makedirs(PHOTO_CACHE, exist_ok=True)
            tmp = path + '.part'
            with open(tmp, 'wb') as f:
                f.write(resp.content)
            os.replace(tmp, path)
            return path
        print("PHOTO DOWNLOAD ERROR: HTTP", resp.status_code, url)
        if resp.status_code != 429 and resp.status_code < 500:
            break
        if attempt == 0:
            # Wikimedia renders the thumbnail meanwhile; it says how long to wait.
            try:
                wait = int(resp.headers.get('Retry-After', 5))
            except ValueError:
                wait = 5
            time.sleep(min(max(wait, 1), 15))
    return ""


def sendAppriseNotifications(sci_name, com_name, confidence, confidencepct, path, date, time_of_day, week, latitude, longitude, cutoff, sens, overlap):
    def render_template(template, reason=""):
        ret = template.replace("$sciname", sci_name) \
            .replace("$comname", com_name) \
            .replace("$confidencepct", str(confidencepct)) \
            .replace("$confidence", str(confidence)) \
            .replace("$listenurl", listenurl) \
            .replace("$friendlyurl", friendlyurl) \
            .replace("$birdurl", birdurl) \
            .replace("$date", str(date)) \
            .replace("$time", str(time_of_day)) \
            .replace("$week", str(week)) \
            .replace("$latitude", str(latitude)) \
            .replace("$longitude", str(longitude)) \
            .replace("$cutoff", str(cutoff)) \
            .replace("$sens", str(sens)) \
            .replace("$flickrimage", image_url if "{" in body else "") \
            .replace("$image", image_url if "{" in body else "") \
            .replace("$overlap", str(overlap)) \
            .replace("$reason", reason)
        return ret

    if not should_notify(com_name):
        return

    settings_dict = get_settings()
    title = html.unescape(settings_dict.get('APPRISE_NOTIFICATION_TITLE'))
    f = open(APPRISE_BODY, 'r')
    body = f.read()

    websiteurl = settings_dict.get('BIRDNETPI_URL')
    if websiteurl is None or len(websiteurl) == 0:
        websiteurl = f"http://{socket.gethostname()}.local"

    listenurl = f"{websiteurl}?filename={path}"
    friendlyurl = f"[Listen here]({listenurl})"
    # The bird's postcard in the station's Atlas, this recording expanded.
    birdurl = f"{websiteurl.rstrip('/')}/#sci={quote(sci_name)}&rec={quote(os.path.basename(path))}"

    image_url = ""
    if "$flickrimage" in body or "$image" in body:
        if com_name not in images:
            try:
                url = f"http://localhost/api/v1/image/{sci_name}"
                resp = requests.get(url=url, timeout=10).json()
                images[com_name] = resp['data']['image_url']
            except Exception as e:
                print("IMAGE API ERROR:", e)
        image_url = images.get(com_name, "")

    # One alert per detection, however many reasons apply: the first bird of
    # the day is usually also rare this week, and that sent two alerts.
    reasons = []
    APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT = 1  # Notifies the first N per day.
    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY') == "1":
        numberDetections = get_todays_count_for(sci_name)
        if 0 < numberDetections <= APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT:
            reasons.append("first time today")

    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES') == "1":
        numberDetections = get_this_weeks_count_for(sci_name)
        if 0 < numberDetections <= 5:
            reasons.append(f"only seen {numberDetections} times in last 7d")

    if not reasons and settings_dict.get('APPRISE_NOTIFY_EACH_DETECTION') == "1":
        reasons.append("detection")

    if not reasons:
        return

    reason = ", ".join(reasons)
    notify_body = render_template(body, reason)
    notify_title = render_template(title, reason)
    notify(notify_body, notify_title, local_photo(image_url) if image_url else "")
    species_last_notified[com_name] = int(time.time())

def should_notify(com_name):
    settings_dict = get_settings()
    if not (os.path.exists(APPRISE_CONFIG) and os.path.getsize(APPRISE_CONFIG) > 0):
        return False

    # check if this is an excluded species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES.strip() != "":
        excluded_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES.split(",")]
        if com_name.lower().replace(" ", "") in excluded_species:
            return False

    # check if this is an included species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.strip() != "":
        included_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.split(",")]
        if com_name.lower().replace(" ", "") not in included_species:
            return False

    # is it still too soon?
    APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES = settings_dict.get('APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES')
    if APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES != "0":
        if species_last_notified.get(com_name) is not None:
            try:
                if int(time.time()) - species_last_notified[com_name] < int(APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES):
                    return False
            except Exception as e:
                print("APPRISE NOTIFICATION EXCEPTION: " + str(e))
                return False

    return True


if __name__ == "__main__":
    print("notfications")
