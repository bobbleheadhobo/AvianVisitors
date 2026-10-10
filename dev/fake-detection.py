"""Throwaway detections for testing, marked so they always get cleaned up.

    birdnet/bin/python dev/fake-detection.py add [--sci "Cygnus olor"] [--conf 0.87] [--notify]
    birdnet/bin/python dev/fake-detection.py list
    birdnet/bin/python dev/fake-detection.py clean

Every fake's recording name starts with FAKE- (the real clip it copies keeps
its audio and spectrogram), so `clean` removes exactly these and nothing
else; dev/sync-live.sh warns while any remain. `add --notify` sends a real
alert for it through notifications.py, so the Discord links ($birdurl,
$friendlyurl) can be tested end to end. Run as the BirdNET-Pi user with the
birdnet venv (it has apprise).

The default species, Mute Swan, has a bundled illustration and is out of
range for this station, so it reads plainly as "a bird that can't be here".
"""
import argparse
import datetime
import os
import shutil
import sqlite3
import sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(REPO, 'scripts'))
from utils.helpers import get_settings  # noqa: E402

DB = os.path.join(REPO, 'scripts', 'birds.db')


def folder(conf, date, com):
    return os.path.join(conf['EXTRACTED'], 'By_Date', date, com.replace("'", '').replace(' ', '_'))


def label_for(sci):
    with open(os.path.join(REPO, 'model', 'labels.txt')) as f:
        for line in f:
            s, _, com = line.strip().partition('_')
            if s == sci:
                return com
    sys.exit('%s is not in model/labels.txt' % sci)


def add(a, conf, db):
    com = label_for(a.sci)
    src = None
    for date, src_com, name in db.execute(
            "SELECT Date, Com_Name, File_Name FROM detections WHERE File_Name NOT LIKE 'FAKE-%' "
            "ORDER BY Date DESC, Time DESC LIMIT 50"):
        path = os.path.join(folder(conf, date, src_com), name)
        if os.path.isfile(path):
            src = path
            break
    if not src:
        sys.exit('no real recording on disk to copy')
    now = datetime.datetime.now()
    date, time = now.strftime('%Y-%m-%d'), now.strftime('%H:%M:%S')
    pct = round(a.conf * 100)
    name = 'FAKE-%s-%d-%s-birdnet-%s.mp3' % (com.replace("'", '').replace(' ', '_'), pct, date, time)
    dst = folder(conf, date, com)
    os.makedirs(dst, exist_ok=True)
    shutil.copy(src, os.path.join(dst, name))
    if os.path.isfile(src + '.png'):
        shutil.copy(src + '.png', os.path.join(dst, name + '.png'))
    with db:
        db.execute('INSERT INTO detections (Date, Time, Sci_Name, Com_Name, Confidence, Lat, Lon, Cutoff, Week, Sens, Overlap, File_Name) '
                   'VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                   (date, time, a.sci, com, a.conf, conf.getfloat('LATITUDE'), conf.getfloat('LONGITUDE'),
                    conf.getfloat('CONFIDENCE'), now.isocalendar()[1], conf.getfloat('SENSITIVITY'),
                    conf.getfloat('OVERLAP'), name))
    print(name)
    if a.notify:
        from utils import notifications
        # get_settings() is cached, so this forces an alert for this one call.
        conf['APPRISE_NOTIFY_EACH_DETECTION'] = '1'
        conf['APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY'] = '0'
        conf['APPRISE_NOTIFY_NEW_SPECIES'] = '0'
        notifications.sendAppriseNotifications(a.sci, com, str(a.conf), str(pct), name, date, time,
                                               str(now.isocalendar()[1]), conf['LATITUDE'], conf['LONGITUDE'],
                                               conf['CONFIDENCE'], conf['SENSITIVITY'], conf['OVERLAP'])
        print('alert sent')


def clean(conf, db):
    rows = db.execute("SELECT Date, Com_Name, File_Name FROM detections WHERE File_Name LIKE 'FAKE-%'").fetchall()
    for date, com, name in rows:
        base = folder(conf, date, com)
        for path in (os.path.join(base, name), os.path.join(base, name + '.png')):
            if os.path.isfile(path):
                os.remove(path)
        try:
            os.rmdir(base)
        except OSError:
            pass
    with db:
        db.execute("DELETE FROM detections WHERE File_Name LIKE 'FAKE-%'")
    print('removed %d fake detection(s)' % len(rows))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    p = sub.add_parser('add')
    p.add_argument('--sci', default='Cygnus olor')
    p.add_argument('--conf', type=float, default=0.87)
    p.add_argument('--notify', action='store_true', help='send a real alert for it')
    sub.add_parser('list')
    sub.add_parser('clean')
    a = ap.parse_args()
    conf = get_settings()
    db = sqlite3.connect(DB, timeout=15)
    if a.cmd == 'add':
        add(a, conf, db)
    elif a.cmd == 'clean':
        clean(conf, db)
    else:
        for row in db.execute("SELECT Date, Time, Com_Name, File_Name FROM detections WHERE File_Name LIKE 'FAKE-%'"):
            print(*row)


if __name__ == '__main__':
    main()
