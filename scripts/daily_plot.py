import argparse
import gc
import os
import sqlite3
from datetime import datetime
from time import sleep

import matplotlib
matplotlib.use('Agg')
import matplotlib.font_manager as font_manager
import matplotlib.pyplot as plt
import pandas as pd
from matplotlib import rcParams

from utils.helpers import DB_PATH, FONT_DIR, get_settings, get_font


def get_data(now=None):
    uri = f"file:{DB_PATH}?mode=ro"
    conn = sqlite3.connect(uri, uri=True)
    if now is None:
        now = datetime.now()
    df = pd.read_sql_query(f"SELECT * from detections WHERE Date = DATE('{now.strftime('%Y-%m-%d')}')",
                           conn)
    conn.close()

    # Convert Date and Time Fields to Panda's format
    df['Date'] = pd.to_datetime(df['Date'])
    df['Time'] = pd.to_datetime(df['Time'], unit='ns')

    # Add round hours to dataframe
    df['Hour of Day'] = [r.hour for r in df.Time]

    return df, now


# Avian Visitors chart: a printed ledger in one ink. One row per species,
# most heard first: common name, a bar whose length is the day's count, and
# a 24-hour strip of squares sized by detections in that hour. The page
# shows it grayscale on paper (multiply) or inverted on charcoal (screen),
# so everything here is drawn as ink on white.
INK = '#1a1612'
QUIET = '#6b6050'
HAIRLINE = '#dedad2'
NOW_BAND = '#efede8'
ROW_IN = 0.34       # row height, inches
WIDTH_IN = 9.6      # rendered at 200 dpi, shown at ~960 CSS px


def chart_fonts():
    """Serif names and mono figures, unless the database language needs
    one of the bundled Noto faces to render its names."""
    family = get_font()['font.family']
    if family == 'Roboto Flex':
        return 'STIXGeneral', 'DejaVu Sans Mono'  # both ship with matplotlib
    return family, 'DejaVu Sans Mono'


def create_plot(df_plt_today, now, is_top=None):
    counts = df_plt_today['Sci_Name'].value_counts()
    if is_top is not None:
        counts = counts[:10] if is_top else counts[-10:]
    if is_top is False:
        name, scope = "Combo2", "least heard"
    else:
        name, scope = "Combo", "most heard" if is_top else "all species"

    df = df_plt_today[df_plt_today.Sci_Name.isin(counts.index)]
    order = list(counts.index)
    rows = len(order)
    names_key = df_plt_today.sort_values('Time', ascending=False).groupby('Sci_Name').first()['Com_Name']
    hours = pd.crosstab(df['Sci_Name'], df['Hour of Day']).reindex(index=order, columns=range(24), fill_value=0)

    serif, mono = chart_fonts()
    head_in, foot_in = 0.86, 0.46
    height = head_in + rows * ROW_IN + foot_in
    f = plt.figure(figsize=(WIDTH_IN, height), facecolor='white')

    # Column plan (fractions of the figure width).
    name_x, bar_x0, bar_x1, strip_x0, strip_x1 = 0.02, 0.255, 0.405, 0.43, 0.985
    bottom = foot_in / height
    top = 1 - head_in / height
    ax = f.add_axes([0, bottom, 1, top - bottom])
    ax.set_xlim(0, 1)
    ax.set_ylim(rows, 0)
    ax.axis('off')

    cell = (strip_x1 - strip_x0) / 24
    hour_x = [strip_x0 + cell * (h + 0.5) for h in range(24)]

    # Current hour: a faint band down the strip and a bold tick below.
    if now.date() == datetime.now().date():
        ax.add_patch(plt.Rectangle((strip_x0 + cell * now.hour, 0), cell, rows, facecolor=NOW_BAND, edgecolor='none', zorder=0))

    # Hairline hour grid and row rules.
    for h in range(25):
        x = strip_x0 + cell * h
        ax.plot([x, x], [0, rows], color=HAIRLINE, lw=0.6 if h % 6 else 1.0, zorder=1)
    for r in range(rows + 1):
        ax.plot([name_x, strip_x1], [r, r], color=HAIRLINE, lw=0.6, zorder=1)

    max_count = max(int(counts.max()), 1)
    max_hour = max(int(hours.values.max()), 1)
    for r, sci in enumerate(order):
        y = r + 0.5
        com = names_key.get(sci, sci)
        if len(com) > 28:
            com = com[:27] + '…'
        ax.text(name_x, y, com, ha='left', va='center', fontsize=11.5, family=serif, color=INK)
        n = int(counts[sci])
        length = (bar_x1 - bar_x0) * n / max_count
        ax.add_patch(plt.Rectangle((bar_x0, y - 0.13), max(length, 0.004), 0.26, facecolor=INK, edgecolor='none', zorder=2))
        ax.text(bar_x0 + length + 0.006, y, str(n), ha='left', va='center', fontsize=10.5, family=mono, color=INK)
        for h in range(24):
            k = int(hours.at[sci, h])
            if not k:
                continue
            # True squares (sized in inches); side grows with sqrt(count) so
            # area tracks detections.
            side_in = min(cell * WIDTH_IN, ROW_IN) * (0.34 + 0.5 * ((k / max_hour) ** 0.5))
            w, hgt = side_in / WIDTH_IN, side_in / ROW_IN
            ax.add_patch(plt.Rectangle((hour_x[h] - w / 2, y - hgt / 2), w, hgt, facecolor=INK, edgecolor='white', lw=0.6, zorder=3))

    # Header: what this is, in the Stats label voice.
    total = int(counts.sum())
    f.text(name_x, 1 - 0.3 / height, f"{now.strftime('%A %-d %B').upper()}  ·  {scope.upper()}",
           ha='left', va='center', fontsize=10, family=mono, color=INK, fontweight='bold')
    f.text(strip_x1, 1 - 0.3 / height, f"{rows} SPECIES  ·  {total} DETECTIONS  ·  UPDATED {now.strftime('%H:%M')}",
           ha='right', va='center', fontsize=10, family=mono, color=QUIET)
    f.text(bar_x0, top + 0.08 / height, "HEARD", ha='left', va='bottom', fontsize=9.5, family=mono, color=QUIET)
    f.text(strip_x0, top + 0.08 / height, "BY HOUR", ha='left', va='bottom', fontsize=9.5, family=mono, color=QUIET)

    # Hour ticks under the strip, every third hour plus the current one.
    is_today = now.date() == datetime.now().date()
    ticks = set(range(0, 24, 3)) | ({now.hour} if is_today else set())
    for h in sorted(ticks):
        current = is_today and h == now.hour
        f.text(hour_x[h], bottom - 0.16 / height, f"{h:02d}", ha='center', va='top', fontsize=10, family=mono,
               color=INK if current else QUIET, fontweight='bold' if current else 'normal')

    save_name = os.path.expanduser(f"~/BirdSongs/Extracted/Charts/{name}-{now.strftime('%Y-%m-%d')}.png")
    plt.savefig(save_name, dpi=200, facecolor='white')
    plt.close(f)
    gc.collect()


def load_fonts():
    # Add every font at the specified location
    font_dir = [FONT_DIR]
    for font in font_manager.findSystemFonts(font_dir, fontext='ttf'):
        font_manager.fontManager.addfont(font)
    # Set font family globally
    rcParams['font.family'] = get_font()['font.family']


def main(daemon, sleep_m):
    load_fonts()
    last_run = None
    while True:
        now = datetime.now()
        # now = datetime.strptime('2023-12-13T23:59:59', "%Y-%m-%dT%H:%M:%S")
        # now = datetime.strptime('2024-01-02T23:59:59', "%Y-%m-%dT%H:%M:%S")
        # now = datetime.strptime('2024-02-26T23:59:59', "%Y-%m-%dT%H:%M:%S")
        # now = datetime.strptime('2024-04-03T23:59:59', "%Y-%m-%dT%H:%M:%S")
        # now = datetime.strptime('2024-04-07T23:59:59', "%Y-%m-%dT%H:%M:%S")
        if last_run and now.day != last_run.day:
            print("getting yesterday's dataset")
            yesterday = last_run.replace(hour=23, minute=59)
            data, time = get_data(yesterday)
        else:
            data, time = get_data(now)
        if not data.empty:
            create_plot(data, time)
        else:
            print('empty dataset')
        if daemon:
            last_run = now
            sleep(60 * sleep_m)
        else:
            break


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--daemon', action='store_true')
    parser.add_argument('--sleep', default=2, type=int, help='Time between runs (minutes)')
    args = parser.parse_args()
    main(args.daemon, args.sleep)
