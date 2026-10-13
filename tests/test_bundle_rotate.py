"""avian/scripts/bundle_rotate.py: the daily bird bundle switch."""

import datetime as dt
import importlib.util
import random
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location("bundle_rotate", ROOT / "avian/scripts/bundle_rotate.py")
rotate = importlib.util.module_from_spec(spec)
spec.loader.exec_module(rotate)

WOODBLOCK = {"id": "official-western-us-woodblock", "name": "Japanese Woodblock", "version": "1.0.0"}
IMPRESSIONIST = {"id": "official-western-us-impressionist", "name": "Evolutionary Impressionist", "version": "1.0.0"}
AMERICA = {"id": "official-birds-of-america", "name": "Birds of America", "version": "1.0.0"}


def test_day_turns_over_at_the_reset_hour():
    conf = {"RESET_AT_MIDNIGHT": "1", "RESET_HOUR": "4"}
    assert rotate.site_day(dt.datetime(2026, 10, 14, 3, 59), conf) == "2026-10-13"
    assert rotate.site_day(dt.datetime(2026, 10, 14, 4, 0), conf) == "2026-10-14"


def test_day_turns_over_at_midnight_without_the_daily_reset():
    conf = {"RESET_AT_MIDNIGHT": "0", "RESET_HOUR": "4"}
    assert rotate.site_day(dt.datetime(2026, 10, 14, 0, 30), conf) == "2026-10-14"


def test_never_picks_todays_bundle_or_an_unticked_one():
    rng = random.Random(1)
    installed = [WOODBLOCK, IMPRESSIONIST, AMERICA]
    for _ in range(50):
        pick = rotate.choose(installed, WOODBLOCK["id"], {AMERICA["id"]}, rng)
        assert pick == IMPRESSIONIST


def test_stays_put_with_nothing_else_ticked():
    rng = random.Random(1)
    assert rotate.choose([WOODBLOCK, IMPRESSIONIST], WOODBLOCK["id"], {IMPRESSIONIST["id"]}, rng) is None
    assert rotate.choose([WOODBLOCK], WOODBLOCK["id"], set(), rng) is None


def test_skip_list_ignores_anything_that_is_not_a_bundle_id(tmp_path):
    conf_path = tmp_path / "birdnet.conf"
    conf_path.write_text('BUNDLE_ROTATE=1\nBUNDLE_ROTATE_SKIP="official-birds-of-america,../etc,"\n')
    conf = rotate.read_conf(conf_path)
    assert conf["BUNDLE_ROTATE"] == "1"
    assert rotate.skipped(conf) == {"official-birds-of-america"}
