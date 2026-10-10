"""Workflow metrics from this project's Claude Code transcripts.

    python3 dev/workflow-metrics.py [--since 2026-10-09] [--dir ~/.claude/projects/-home-avian-BirdNET-Pi]

The Control step of the 2026-10-09 workflow review (see CLAUDE.md, "Working
agreement"). Rerun every couple of weeks and compare with the baseline:

    baseline, 2026-09-27 to 2026-10-09 (this script's output that day):
    193 owner messages, 60 commits, 13 owner-found defects (0.2 per
    commit), 39 messages (20%) mentioning commit/push/docs, 13 bare "yes"
    answers to "want me to commit?", 4 compactions, longest session
    25 h / 767 tool calls. Use --since 2026-10-10 for the period after.

Owner-found defects and ceremony are matched on wording, so treat them as a
trend, not an exact count; read the listed messages.
"""
import argparse
import glob
import json
import os
import re
from datetime import datetime

SKIP = re.compile(r'(<command-|<local-command|Base directory for this skill|<task-notification|'
                  r'This session is being continued|\[Request interrupted)')
DEFECT = re.compile(r"(not working|doesn.t work|didn.t work|neither of them worked|no longer work|go away|"
                    r"not showing|\b401\b|buggy|glitch|\bbug\b|still shows|broke)", re.I)
CEREMONY = re.compile(r'\b(commit|push|update (the |any )?doc)', re.I)
BARE_YES = re.compile(r'(yes|yeah|ok)[^a-z]*(commit|push|to both|to all \d|go ahead|please)?[ .!]*', re.I)


def stamp(ts):
    return datetime.fromisoformat(ts.replace('Z', '+00:00'))


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--dir', default=os.path.expanduser('~/.claude/projects/-home-avian-BirdNET-Pi'))
    ap.add_argument('--since', help='only sessions that start on or after this date (YYYY-MM-DD)')
    a = ap.parse_args()

    total = dict(msgs=0, commits=0, defects=0, ceremony=0, yes_commit=0, compactions=0)
    defects = []
    print('%-9s %-10s %6s %5s %6s %7s %7s' % ('session', 'start', 'hours', 'msgs', 'tools', 'commits', 'compact'))
    for f in sorted(glob.glob(os.path.join(a.dir, '*.jsonl')), key=os.path.getmtime):
        times, msgs, tools, commits, compactions = [], 0, 0, 0, 0
        found, ceremony, yes_commit = [], 0, 0
        last_reply = ''
        for line in open(f):
            try:
                d = json.loads(line)
            except ValueError:
                continue
            if d.get('timestamp'):
                times.append(d['timestamp'])
            if d.get('isSidechain'):
                continue
            content = (d.get('message') or {}).get('content')
            if d.get('type') == 'assistant' and isinstance(content, list):
                text = ' '.join(b.get('text', '') for b in content if b.get('type') == 'text')
                if text.strip():
                    last_reply = text
                for b in content:
                    if b.get('type') != 'tool_use':
                        continue
                    tools += 1
                    if b.get('name') == 'Bash' and re.search(r'git (-c \S+ )*commit', b['input'].get('command', '')):
                        commits += 1
            elif d.get('type') == 'user' and isinstance(content, str):
                if content.startswith('This session is being continued'):
                    compactions += 1
                if d.get('isMeta') or SKIP.match(content.strip()) or "hasn't heard from you" in content:
                    continue
                msgs += 1
                one = ' '.join(content.split())
                if DEFECT.search(one):
                    found.append(one[:120])
                if CEREMONY.search(one):
                    ceremony += 1
                if BARE_YES.fullmatch(one) and re.search(r'commit|push|merge', last_reply[-400:], re.I):
                    yes_commit += 1
        if not times or (a.since and times[0][:10] < a.since):
            continue
        hours = (stamp(times[-1]) - stamp(times[0])).total_seconds() / 3600
        print('%-9s %-10s %6.1f %5d %6d %7d %7d' % (os.path.basename(f)[:8], times[0][:10], hours, msgs, tools, commits, compactions))
        total['msgs'] += msgs
        total['commits'] += commits
        total['compactions'] += compactions
        total['defects'] += len(found)
        total['ceremony'] += ceremony
        total['yes_commit'] += yes_commit
        defects += found
    print()
    print('owner messages %(msgs)d, commits %(commits)d, compactions %(compactions)d' % total)
    print('owner-found defects %d (%.1f per commit)' % (total['defects'], total['defects'] / max(total['commits'], 1)))
    print('messages on commit/push/docs %d (%.0f%%), bare yes to a commit question %d'
          % (total['ceremony'], 100 * total['ceremony'] / max(total['msgs'], 1), total['yes_commit']))
    if defects:
        print('\nowner-found defects:')
        for d in defects:
            print('  - ' + d)


if __name__ == '__main__':
    main()
