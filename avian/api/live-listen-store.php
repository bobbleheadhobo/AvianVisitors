<?php
// Live listening: the remote-listen switch, the listening session log, the
// listener count and Discord (Apprise) alerts. Used by live-listen.php.
//
// A "session" is one person's continuous listening: stream connections from
// the same admin session that follow each other within LISTEN_SESSION_GAP
// seconds (the 30 minute auto-stop, a dropped connection, a reload) are one
// session, so alerts fire once per session rather than once per connection.

declare(strict_types=1);

const LISTEN_DIR = '/var/lib/avian-visitors/listen';
const LISTEN_DB = LISTEN_DIR . '/listen.db';
const LISTEN_SESSION_GAP = 600;        // seconds that still count as the same session
const LISTEN_HEARTBEAT_STALE = 20;     // a session not seen for this long is not listening now
const LISTEN_LONG_ALERT_EVERY = 1200;  // "still listening" every 20 minutes
const LISTEN_BUSY_DAY = 5;             // "opened N times today" once a day at this count
const LISTEN_MAX_SECONDS = 1800;       // one connection's hard cap (the 30 minute auto-stop)
const LISTEN_SLOTS = 2;                // concurrent remote relays (each holds a PHP worker)

function listen_db(): ?PDO {
    static $db = null;
    if ($db instanceof PDO) return $db;
    if (!is_dir(LISTEN_DIR) || !is_writable(LISTEN_DIR)) return null;
    try {
        $db = new PDO('sqlite:' . LISTEN_DB, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout = 2000');
        $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $db->exec('CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sid TEXT NOT NULL,
            ip TEXT NOT NULL,
            agent TEXT NOT NULL,
            started INTEGER NOT NULL,
            last_seen INTEGER NOT NULL,
            long_alerts INTEGER NOT NULL DEFAULT 0
        )');
        $db->exec('CREATE INDEX IF NOT EXISTS sessions_seen ON sessions (last_seen)');
    } catch (Throwable $e) {
        $db = null;
    }
    return $db;
}

function listen_remote_enabled(): bool {
    $db = listen_db();
    if (!$db) return false;
    try {
        $v = $db->query("SELECT value FROM settings WHERE key = 'remote_enabled'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
    return $v === '1';
}

function listen_set_remote_enabled(bool $on): bool {
    $db = listen_db();
    if (!$db) return false;
    $st = $db->prepare("INSERT INTO settings (key, value) VALUES ('remote_enabled', :v)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    return $st->execute([':v' => $on ? '1' : '0']);
}

/** The visitor's address for the log and alerts. Behind the reverse proxy
 *  this comes from its forwarding header; it is shown, never trusted. */
function listen_client_ip(array $server): string {
    foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $k) {
        $v = trim(explode(',', (string)($server[$k] ?? ''))[0]);
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
    $v = (string)($server['REMOTE_ADDR'] ?? '');
    return filter_var($v, FILTER_VALIDATE_IP) ? $v : 'unknown';
}

/** "Firefox on Android" from a user agent, for a readable alert. */
function listen_agent_label(string $ua): string {
    $browser = 'a browser';
    foreach ([
        'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet',
        'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome',
        'Chrome/' => 'Chrome', 'Safari/' => 'Safari',
    ] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $browser = $name; break; }
    }
    $os = '';
    foreach (['Android' => 'Android', 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Windows' => 'Windows',
              'Mac OS X' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux'] as $needle => $name) {
        if (stripos($ua, $needle) !== false) { $os = $name; break; }
    }
    return $os !== '' ? "$browser on $os" : $browser;
}

/** Fire-and-forget alert through the station's Apprise targets (Discord
 *  once its webhook is in apprise.txt). With no targets it does nothing;
 *  the session log still records everything. */
function listen_notify(string $title, string $body): void {
    $home = dirname(__DIR__, 2);
    $config = $home . '/apprise.txt';
    $apprise = $home . '/birdnet/bin/apprise';
    if (!is_readable($config) || filesize($config) === 0 || !is_executable($apprise)) return;
    $cmd = 'nohup ' . escapeshellarg($apprise)
        . ' -t ' . escapeshellarg($title)
        . ' -b ' . escapeshellarg($body)
        . ' --config ' . escapeshellarg($config)
        . ' > /dev/null 2>&1 &';
    @exec($cmd);
}

function listen_clock(int $t): string {
    return date('H:i', $t);
}

/** A stream connection starts: join the visitor's open session or begin a
 *  new one (alerting), and return the session id. */
function listen_session_begin(array $server, string $sessionKey): ?int {
    $db = listen_db();
    if (!$db) return null;
    $now = time();
    $sid = hash('sha256', $sessionKey);
    $ip = listen_client_ip($server);
    $agent = substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 300);
    try {
        $db->beginTransaction();
        $st = $db->prepare('SELECT id FROM sessions WHERE sid = :sid AND last_seen >= :since ORDER BY id DESC LIMIT 1');
        $st->execute([':sid' => $sid, ':since' => $now - LISTEN_SESSION_GAP]);
        $id = $st->fetchColumn();
        if ($id !== false) {
            $db->prepare('UPDATE sessions SET last_seen = :now WHERE id = :id')->execute([':now' => $now, ':id' => $id]);
            $db->commit();
            return (int)$id;
        }
        $db->prepare('INSERT INTO sessions (sid, ip, agent, started, last_seen) VALUES (:sid, :ip, :agent, :now, :now)')
            ->execute([':sid' => $sid, ':ip' => $ip, ':agent' => $agent, ':now' => $now]);
        $id = (int)$db->lastInsertId();
        $today = (int)$db->query("SELECT COUNT(*) FROM sessions WHERE started >= " . strtotime('today'))->fetchColumn();
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return null;
    }
    $who = listen_agent_label($agent);
    listen_notify('Live mic: someone started listening',
        "Someone started listening to the live microphone from $ip ($who) at " . listen_clock($now) . '.');
    if ($today === LISTEN_BUSY_DAY) {
        listen_notify('Live mic: busy day',
            'The live microphone has been opened remotely ' . LISTEN_BUSY_DAY . ' times today.');
    }
    return $id;
}

/** Called every few seconds while audio flows: keep the session current
 *  and send the "still listening" alert every LISTEN_LONG_ALERT_EVERY. */
function listen_session_beat(int $id): void {
    $db = listen_db();
    if (!$db) return;
    $now = time();
    try {
        $st = $db->prepare('SELECT ip, agent, started, long_alerts FROM sessions WHERE id = :id');
        $st->execute([':id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return;
        $db->prepare('UPDATE sessions SET last_seen = :now WHERE id = :id')->execute([':now' => $now, ':id' => $id]);
        $due = intdiv($now - (int)$row['started'], LISTEN_LONG_ALERT_EVERY);
        if ($due > (int)$row['long_alerts']) {
            $db->prepare('UPDATE sessions SET long_alerts = :n WHERE id = :id')->execute([':n' => $due, ':id' => $id]);
            $mins = intdiv($now - (int)$row['started'], 60);
            listen_notify('Live mic: still listening',
                "Someone has been listening to the live microphone for $mins minutes, from {$row['ip']} ("
                . listen_agent_label((string)$row['agent']) . ').');
        }
    } catch (Throwable $e) {
        // A missed beat only delays the next one.
    }
}

/** Remote sessions heard from in the last few seconds. */
function listen_remote_active(): int {
    $db = listen_db();
    if (!$db) return 0;
    try {
        $st = $db->prepare('SELECT COUNT(*) FROM sessions WHERE last_seen >= :since');
        $st->execute([':since' => time() - LISTEN_HEARTBEAT_STALE]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Everyone on the stream right now. Icecast counts every client, the
 *  remote relays included; local listeners are the rest. */
function listen_counts(): array {
    $total = null;
    $ctx = stream_context_create(['http' => ['timeout' => 1.5]]);
    $raw = @file_get_contents('http://127.0.0.1:8000/status-json.xsl', false, $ctx);
    if (is_string($raw)) {
        $j = json_decode($raw, true);
        $src = $j['icestats']['source'] ?? null;
        if (is_array($src) && isset($src[0])) $src = $src[0];
        if (is_array($src) && isset($src['listeners'])) $total = (int)$src['listeners'];
    }
    $remote = listen_remote_active();
    if ($total === null) return ['listening' => $remote, 'remote' => $remote, 'local' => 0, 'exact' => false];
    $remote = min($remote, $total);
    return ['listening' => $total, 'remote' => $remote, 'local' => $total - $remote, 'exact' => true];
}

function listen_log(int $limit = 60): array {
    $db = listen_db();
    if (!$db) return [];
    try {
        $st = $db->prepare('SELECT id, ip, agent, started, last_seen FROM sessions ORDER BY id DESC LIMIT :n');
        $st->bindValue(':n', $limit, PDO::PARAM_INT);
        $st->execute();
        $now = time();
        return array_map(function ($r) use ($now) {
            return [
                'started' => (int)$r['started'],
                'minutes' => max(1, (int)round(((int)$r['last_seen'] - (int)$r['started']) / 60)),
                'active' => (int)$r['last_seen'] >= $now - LISTEN_HEARTBEAT_STALE,
                'ip' => $r['ip'],
                'device' => listen_agent_label((string)$r['agent']),
            ];
        }, $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
        return [];
    }
}
