#!/usr/bin/env node
// plugged.host storage agent (WP9).
//
// Runs on the storage box, NOT the panel host -- and, when there is one, on the backup server too:
// the same agent, with its own secret (the panel tells the two apart by whose secret signs a
// request). It PULLS: it initiates each rsync from the nodes,
// serves a tar of a server's mirror behind an HMAC-signed URL (streamed to browsers as it is
// built, complete with a size for wings restores, cached until the next mirror pass), answers node-reachability probes for the panel, reports mirror size and
// freshness back, and self-updates from a signed bundle the panel serves.
//
// Nothing is kept twice on the storage drive: a server that lives on this box (the limbo node)
// gets a mirror of hardlinks to its wings volume, not a second copy, and once an hour the agent
// asks the panel which folders belong to servers that are gone and removes those (see
// housekeepingTick; the panel keeps an admin-deleted server's copy 7 days).
//
// Uses only Node built-ins — no npm dependencies to install on the box.
//
// Config comes from the environment (see install.sh / the systemd unit):
//   PH_PANEL_URL           e.g. https://panel.plugged.host
//   PH_AGENT_SECRET        shared HMAC secret (STORAGE_AGENT_SECRET on the panel)
//   PH_STORAGE_ROOT        default /mnt/storage
//   PH_ARCHIVE_SOURCE      default download (source directory for downloads; can be 'mirror' or 'download')
//   PH_LISTEN_PORT         default 8687  (inbound: archives + reachability + health)
//   PH_MIN_FREE_PERCENT    default 10    (refuse a pass below this)
//   PH_SNAPSHOTS           default 3     (rotating --link-dest snapshots kept; the panel's setting wins)
//   PH_MIRROR_INTERVAL_MIN default 30
//   PH_UPDATE_INTERVAL_MIN default 15
//   PH_MAX_ARCHIVE_JOBS    default 3     (tar builds at once; more answer 503 + Retry-After)
//   PH_MIRROR_JOBS         default 1     (mirror passes at once, never two on one node)
//   PH_STREAM_STALL_SEC    default 60    (drop a streaming download that stops reading for this long)
//   PH_RELEASE_DIR         default /opt/plugged-host-storage   (for self-update symlink swap)
//   PH_VERSION             fallback version string, used only when not run from releases/<version>/

import http from 'node:http';
import https from 'node:https';
import net from 'node:net';
import crypto from 'node:crypto';
import fs from 'node:fs';
import fsp from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { pipeline } from 'node:stream/promises';

// The version this process runs is the release folder it was started from, which the updater
// names after the manifest version it unpacked. PH_VERSION in the env file is not rewritten by a
// self-update, so trusting it alone made the agent re-install the same release and exit on
// every start, forever.
function runningVersion() {
  try {
    const dir = path.dirname(fs.realpathSync(fileURLToPath(import.meta.url)));
    if (path.basename(path.dirname(dir)) === 'releases') return path.basename(dir);
  } catch { /* fall back */ }
  return process.env.PH_VERSION || '2.2.0';
}

const CFG = {
  panelUrl: (process.env.PH_PANEL_URL || '').replace(/\/+$/, ''),
  secret: process.env.PH_AGENT_SECRET || '',
  root: process.env.PH_STORAGE_ROOT || '/mnt/storage',
  archiveSource: process.env.PH_ARCHIVE_SOURCE || 'download',
  port: parseInt(process.env.PH_LISTEN_PORT || '8687', 10),
  minFreePercent: parseFloat(process.env.PH_MIN_FREE_PERCENT || '10'),
  snapshots: parseInt(process.env.PH_SNAPSHOTS || '3', 10),
  mirrorIntervalMin: parseInt(process.env.PH_MIRROR_INTERVAL_MIN || '30', 10),
  updateIntervalMin: parseInt(process.env.PH_UPDATE_INTERVAL_MIN || '15', 10),
  releaseDir: process.env.PH_RELEASE_DIR || '/opt/plugged-host-storage',
  version: runningVersion(),
  maxArchiveJobs: Math.max(1, parseInt(process.env.PH_MAX_ARCHIVE_JOBS || '3', 10)),
  mirrorJobs: Math.max(1, parseInt(process.env.PH_MIRROR_JOBS || '1', 10) || 1),
  streamStallMs: Math.max(10, parseInt(process.env.PH_STREAM_STALL_SEC || '60', 10)) * 1000,
  // How to turn a plan entry (host + path + uuid) into an rsync source. The pull credential
  // lives HERE, on the storage box, never on the panel. Placeholders: %HOST% %PATH% %UUID%.
  // Default is the SSH form; set to an rsync:// module URL if using an rsync daemon instead.
  rsyncTemplate: process.env.PH_RSYNC_TEMPLATE || '%USER%@%HOST%:%PATH%/',
  rsyncUser: process.env.PH_RSYNC_USER || 'ph-mirror',
  rsyncSshKey: process.env.PH_RSYNC_SSH_KEY || '',
};

const DIRS = {
  mirror: path.join(CFG.root, 'mirror'),
  snapshots: path.join(CFG.root, 'snapshots'),
  archives: path.join(CFG.root, 'archives'),
};

// Patterns excluded from replication & on-demand archives tailored to plugged.host eggs
const EXCLUDES = [
  // Caches & temp files
  '.cache',
  'cache',
  '.tmp',
  'tmp',
  'temp',
  '*.tmp',
  '*.temp',

  // Node.js generic (Egg 12)
  'node_modules',
  '.npm',
  '.yarn',
  '.pnpm-store',
  'npm-debug.log*',
  'yarn-debug.log*',
  'yarn-error.log*',

  // Python generic (Egg 4)
  '__pycache__',
  '*.py[cod]',
  '.pytest_cache',
  '.mypy_cache',
  '.ruff_cache',
  '.tox',
  'venv',
  '.venv',
  'env',
  '.env.bak',

  // FiveM / RedM (Egg 9)
  'server-data/cache',
  'alpine',

  // Minecraft Java / Velocity / Bedrock (Eggs 1, 7, 14)
  '.fabric',
  '.quilt',

  // Multi Theft Auto (Egg 13)
  'dumps',

  // Logs & crashes across all eggs
  'logs',
  'crash-reports',
  '*.log',
  '*.log.gz',
  '*.log.[0-9]*',

  // Core dumps & system clutter
  'core',
  'core.[0-9]*',
  '.DS_Store',
  'Thumbs.db',
  '.directory',
];

function log(level, msg, extra) {
  const line = JSON.stringify({ ts: new Date().toISOString(), level, msg, ...(extra || {}) });
  (level === 'error' ? process.stderr : process.stdout).write(line + '\n');
}

// ---- HMAC helpers -----------------------------------------------------------

function hmac(data) {
  return crypto.createHmac('sha256', CFG.secret).update(data).digest('hex');
}

// Constant-time compare that never throws on length mismatch.
function safeEqual(a, b) {
  const ba = Buffer.from(String(a));
  const bb = Buffer.from(String(b));
  if (ba.length !== bb.length) return false;
  return crypto.timingSafeEqual(ba, bb);
}

// Signature the panel must present to fetch an archive or a reachability probe:
//   sig = hmac(`${kind}:${arg}:${expires}`)
function verifySignedRequest(kind, arg, expires, sig) {
  if (!expires || !sig) return false;
  if (Number(expires) * 1000 < Date.now()) return false;
  return safeEqual(sig, hmac(`${kind}:${arg}:${expires}`));
}

// ---- Panel API (outbound, agent-signed) -------------------------------------

function panelRequest(method, apiPath, bodyObj) {
  return new Promise((resolve, reject) => {
    if (!CFG.panelUrl) return reject(new Error('PH_PANEL_URL not set'));
    const url = new URL(CFG.panelUrl + apiPath);
    const body = bodyObj ? JSON.stringify(bodyObj) : '';
    const ts = Math.floor(Date.now() / 1000).toString();
    const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
    const sig = hmac(`${ts}\n${method}\n${apiPath}\n${bodyHash}`);
    const lib = url.protocol === 'http:' ? http : https;
    const req = lib.request(
      url,
      {
        method,
        headers: {
          'Content-Type': 'application/json',
          'X-PH-Timestamp': ts,
          'X-PH-Signature': sig,
          'X-PH-Agent-Version': CFG.version,
          'Content-Length': Buffer.byteLength(body),
        },
        timeout: 30000,
      },
      (res) => {
        let data = '';
        res.on('data', (c) => (data += c));
        res.on('end', () => {
          if (res.statusCode < 200 || res.statusCode >= 300) {
            return reject(new Error(`panel ${method} ${apiPath} -> ${res.statusCode}: ${data.slice(0, 200)}`));
          }
          try {
            resolve(data ? JSON.parse(data) : {});
          } catch {
            resolve({});
          }
        });
      },
    );
    req.on('error', reject);
    req.on('timeout', () => req.destroy(new Error('panel request timeout')));
    if (body) req.write(body);
    req.end();
  });
}

// ---- filesystem helpers -----------------------------------------------------

async function ensureDirs() {
  for (const d of [DIRS.mirror, DIRS.snapshots, DIRS.archives]) {
    await fsp.mkdir(d, { recursive: true });
  }
  // Half-written archives from a build the agent did not live to finish.
  for (const name of await fsp.readdir(DIRS.archives).catch(() => [])) {
    if (name.endsWith('.tmp.tar.gz')) await fsp.rm(path.join(DIRS.archives, name), { force: true }).catch(() => {});
  }
  // Half-built or replaced trees of a local link pass (linkLocalServer); in the background, as a
  // big one takes a while to delete.
  for (const name of await fsp.readdir(DIRS.mirror).catch(() => [])) {
    if (/^\..+\.(link|old)$/.test(name)) removeTree(path.join(DIRS.mirror, name));
  }
}

// rm -rf at the lowest CPU priority. Deleting a server's tree is one unlink per file, on a disk
// replication already keeps busy.
function removeTree(dir) {
  return run('nice', ['-n', '19', 'rm', '-rf', dir]).catch((e) => log('error', 'remove failed', { dir, err: e.message }));
}

// Percent free on the storage filesystem, via `df`.
function freePercent() {
  return new Promise((resolve) => {
    run('df', ['-P', CFG.root])
      .then(({ stdout }) => {
        const line = stdout.trim().split('\n').pop() || '';
        const cols = line.split(/\s+/);
        const usePct = parseFloat((cols[4] || '100%').replace('%', ''));
        resolve(isNaN(usePct) ? 0 : 100 - usePct);
      })
      .catch(() => resolve(0));
  });
}

function dirSizeBytes(dir) {
  return run('du', ['-sb', dir])
    .then(({ stdout }) => parseInt(stdout.trim().split(/\s+/)[0] || '0', 10))
    .catch(() => 0);
}

function run(cmd, args, opts = {}) {
  return new Promise((resolve, reject) => {
    const child = spawn(cmd, args, { ...opts });
    let stdout = '';
    let stderr = '';
    child.stdout?.on('data', (c) => (stdout += c));
    child.stderr?.on('data', (c) => (stderr += c));
    child.on('error', reject);
    child.on('close', (code) => {
      if (code === 0) resolve({ code, stdout, stderr });
      else reject(new Error(`${cmd} exited ${code}: ${stderr.slice(0, 300)}`));
    });
  });
}

// The gzip used for archives: pigz (every core) when the box has it, else gzip. Level 1 either
// way: most of a game server is already compressed (world regions, jars), so a higher level
// costs minutes of CPU for a few percent of size -- and that wait is the download's start.
function findOnPath(bin) {
  for (const dir of String(process.env.PATH || '/usr/local/bin:/usr/bin:/bin').split(':')) {
    try {
      fs.accessSync(path.join(dir, bin), fs.constants.X_OK);
      return path.join(dir, bin);
    } catch { /* next */ }
  }
  return null;
}
const COMPRESSOR = findOnPath('pigz') ? 'pigz -1' : 'gzip -1';

// ---- Server Directory Resolution --------------------------------------------

// The newest non-empty snapshot of a server's mirror, or null. A snapshot is only taken once a
// pass has completed, so a mirror without one is a first copy still running (or one that died
// half-way): it must not be offered as a backup or restored from.
async function latestSnapshotDir(uuid) {
  const snapBase = path.join(DIRS.snapshots, uuid);
  const snaps = (await fsp.readdir(snapBase).catch(() => [])).sort();
  for (let i = snaps.length - 1; i >= 0; i--) {
    const dir = path.join(snapBase, snaps[i]);
    const files = await fsp.readdir(dir).catch(() => []);
    if (files.length > 0) return dir;
  }
  return null;
}

// A candidate directory is usable when it has files and, for a mirror, a completed pass.
async function usableServerDir(base, dir, uuid) {
  const files = await fsp.readdir(dir).catch(() => []);
  if (files.length === 0) return false;
  return base !== DIRS.mirror || (await latestSnapshotDir(uuid)) !== null;
}

// Resolve a UUID or short server ID (e.g. a1b2c3d4) to an existing non-empty directory.
// Checks DIRS.mirror (current servers), then download (legacy backups), then CFG.archiveSource.
async function resolveServerDir(id) {
  const safeId = String(id || '').trim().toLowerCase();
  if (!safeId || !/^[a-f0-9-]{6,}$/i.test(safeId)) return null;

  const candidateBases = Array.from(new Set([
    DIRS.mirror,
    path.join(CFG.root, 'download'),
    path.join(CFG.root, CFG.archiveSource),
  ]));

  for (const base of candidateBases) {
    try {
      if (!fs.existsSync(base)) continue;

      // 1. Direct exact match
      const exactPath = path.join(base, safeId);
      if (fs.existsSync(exactPath)) {
        const st = await fsp.stat(exactPath);
        if (st.isDirectory() && await usableServerDir(base, exactPath, safeId)) {
          return { dir: exactPath, uuid: safeId, source: path.basename(base) };
        }
      }

      // 2. Prefix match (e.g. 8-character short ID like a1b2c3d4)
      const entries = await fsp.readdir(base, { withFileTypes: true }).catch(() => []);
      for (const entry of entries) {
        if (entry.isDirectory() && entry.name.toLowerCase().startsWith(safeId)) {
          const dirPath = path.join(base, entry.name);
          if (await usableServerDir(base, dirPath, entry.name)) {
            return { dir: dirPath, uuid: entry.name, source: path.basename(base) };
          }
        }
      }
    } catch { /* proceed to next directory */ }
  }

  return null;
}

// ---- replication ------------------------------------------------------------

// One delta-mirror pass for a single server.
//   plan entry: { uuid, server_id, rsync_source }
// rsync_source is the storage box's read-only pull spec for that server's volume, e.g.
//   "rsync://mirror@node1/volumes/<uuid>/"  or  "user@node1:/var/lib/pelican/volumes/<uuid>/"
// held ON THE STORAGE BOX (never on the panel).
async function mirrorServer(entry) {
  const uuid = entry.uuid;
  if (!uuid || !entry.host || !entry.path) return;

  if (entry.local) {
    const linked = await linkLocalServer(entry);
    if (linked !== 'copy') return linked;
  }

  const source = CFG.rsyncTemplate
    .replace(/%USER%/g, CFG.rsyncUser)
    .replace(/%HOST%/g, entry.host)
    .replace(/%PATH%/g, entry.path)
    .replace(/%UUID%/g, uuid);

  const dest = path.join(DIRS.mirror, uuid);
  await fsp.mkdir(dest, { recursive: true });

  const free = await freePercent();
  if (free < CFG.minFreePercent) {
    // A truncated mirror is worse than a stale one — refuse and report.
    await report(entry, { state: 'error', last_error: `free space ${free.toFixed(1)}% below floor ${CFG.minFreePercent}%` });
    log('error', 'skipping pass: below free-space floor', { uuid, free });
    return;
  }

  await report(entry, { state: 'replicating' });

  // Rotating hardlink snapshots: link-dest against the most recent snapshot so unchanged files
  // cost only a directory entry. mirror/ and snapshots/ MUST be one filesystem or this silently
  // makes full copies.
  const snapBase = path.join(DIRS.snapshots, uuid);
  await fsp.mkdir(snapBase, { recursive: true });
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const linkDestArgs = [];
  const existing = (await fsp.readdir(snapBase).catch(() => [])).sort();
  const latest = existing[existing.length - 1];
  if (latest) linkDestArgs.push('--link-dest', path.join(snapBase, latest));

  // ConnectTimeout also bounds the banner exchange: a node that accepts TCP but never answers
  // (hung sshd) otherwise holds the one-at-a-time replication loop forever. ServerAlive drops a
  // session whose node stops answering mid-transfer. Per-node ports live in ~/.ssh/config.
  const sshArgs = ['-e', [
    'ssh',
    ...(CFG.rsyncSshKey ? [`-i ${CFG.rsyncSshKey}`] : []),
    '-o StrictHostKeyChecking=accept-new -o BatchMode=yes',
    '-o ConnectTimeout=20 -o ServerAliveInterval=30 -o ServerAliveCountMax=4',
  ].join(' ')];

  const excludeArgs = EXCLUDES.flatMap((pat) => ['--exclude', pat]);

  const rsyncArgs = [
    '-a', '--delete', '--delete-excluded', '--partial', '--timeout=120',
    ...sshArgs,
    ...excludeArgs,
    ...linkDestArgs,
    source,
    dest + '/',
  ];

  try {
    await run('rsync', rsyncArgs);
  } catch (e) {
    await report(entry, { state: 'error', last_error: String(e.message).slice(0, 500) });
    log('error', 'rsync failed', { uuid, err: e.message });
    return NODE_DOWN.test(String(e.message)) ? 'node-down' : 'failed';
  }

  // Snapshot the freshly-synced mirror by hardlinking it.
  const snapDir = path.join(snapBase, stamp);
  try {
    await run('cp', ['-al', dest, snapDir]);
  } catch (e) {
    log('error', 'snapshot failed (non-fatal)', { uuid, err: e.message });
  }

  await pruneSnapshots(uuid, keepSnapshots());
  await passComplete(entry, dest);
}

// Only the newest snapshot is ever read (archives, /info, restores); older ones keep the
// previous versions of every file that changed since, so the panel's setting decides how many.
function keepSnapshots() {
  return Number.isInteger(planSnapshots) && planSnapshots >= 1 ? planSnapshots : Math.max(1, CFG.snapshots);
}

// Keep the newest `keep` snapshots of a server. One an archive is being built from stays until a
// later pass: tar would lose files under it.
async function pruneSnapshots(uuid, keep) {
  const snapBase = path.join(DIRS.snapshots, uuid);
  const snaps = (await fsp.readdir(snapBase).catch(() => [])).sort();
  for (const name of snaps.slice(0, Math.max(0, snaps.length - keep))) {
    const dir = path.join(snapBase, name);
    if (!busySources.has(dir)) await removeTree(dir);
  }
}

async function passComplete(entry, dest) {
  const uuid = entry.uuid;
  const bytes = await dirSizeBytes(dest);
  sizeCache.set(uuid, { bytes, at: Date.now() });
  // The mirror changed, so the cached archive is out of date (freshCachedArchive would say so
  // too: new snapshot, or a pass newer than the build). A build writing it right now finishes
  // and is simply not trusted.
  lastPassAt.set(uuid, Date.now());
  if (!cacheWriters.has(uuid)) await dropCachedArchive(uuid);
  await report(entry, { state: 'ok', bytes, last_replicated_at: new Date().toISOString(), mirror_path: dest });
  log('info', 'mirror pass complete', { uuid, bytes, ...(entry.local ? { linked: true } : {}) });
}

// ---- servers that live on this box ------------------------------------------
//
// A server on the storage box's own wings node (the limbo node) already has its files on this
// drive, in its volume, so a second copy next to it protects against nothing. Its mirror is made
// of hardlinks to the volume instead: no extra space, and the copy it had from its old node goes.
// Wings does not touch a parked (suspended) server's files, and when it moves the server away it
// deletes the volume, which leaves the mirror as the only link, ready for the next pass from the
// new node. The panel leaves a local server out of the plan while it is being moved.

// Where `p` is on its filesystem's main mount. Wings' data here is a bind mount (/mnt/storage/
// pelican at /var/lib/pelican), and link() refuses to cross mount points even within one
// filesystem, so the volume has to be named by its /mnt/storage path.
function unbindPath(p) {
  let rows;
  try {
    rows = fs.readFileSync('/proc/self/mountinfo', 'utf8').trim().split('\n').map((line) => line.split(' '));
  } catch {
    return p;
  }
  const unescape = (s) => String(s).replace(/\\([0-7]{3})/g, (_, o) => String.fromCharCode(parseInt(o, 8)));
  const inside = (dir) => dir === '/' || p === dir || p.startsWith(dir + '/');
  // mountinfo fields: id, parent, major:minor, root (inside the filesystem), mount point. The
  // deepest mount point holding p wins, the later of two at the same point (mounted on top).
  let mount = null;
  for (const row of rows) {
    if (inside(unescape(row[4])) && (!mount || unescape(row[4]).length >= unescape(mount[4]).length)) mount = row;
  }
  if (!mount || unescape(mount[3]) === '/') return p;
  const main = rows.find((row) => row[2] === mount[2] && unescape(row[3]) === '/');
  if (!main) return p;
  return path.join(unescape(main[4]), unescape(mount[3]), path.relative(unescape(mount[4]), p));
}

// Whether the mirror is already the volume's files: a sample of the volume's files (breadth
// first) all present in the mirror as the same inode. A parked server's files do not change, so
// once linked a pass costs these few stats.
async function linkedTo(volume, mirror) {
  const queue = [''];
  let checked = 0;
  let dirs = 0;
  while (queue.length && checked < 50 && dirs < 200) {
    const rel = queue.shift();
    dirs++;
    const entries = await fsp.readdir(path.join(volume, rel), { withFileTypes: true }).catch(() => null);
    if (!entries) return false;
    for (const entry of entries) {
      const child = path.join(rel, entry.name);
      if (entry.isDirectory()) { queue.push(child); continue; }
      if (!entry.isFile()) continue;
      const [a, b] = await Promise.all([
        fsp.stat(path.join(volume, child)).catch(() => null),
        fsp.stat(path.join(mirror, child)).catch(() => null),
      ]);
      if (!a || !b || a.ino !== b.ino || a.dev !== b.dev) return false;
      if (++checked >= 50) break;
    }
  }
  // An empty volume: linked when the mirror is empty too.
  return checked > 0 || (await fsp.readdir(mirror).catch(() => ['?'])).length === 0;
}

// Returns undefined when the mirror is (now) linked, 'failed', or 'copy' when hardlinking is not
// possible here, so the caller copies as for any other node.
async function linkLocalServer(entry) {
  const uuid = entry.uuid;
  const volume = unbindPath(entry.path);
  const dest = path.join(DIRS.mirror, uuid);

  const st = await fsp.stat(volume).catch(() => null);
  if (!st || !st.isDirectory()) {
    await report(entry, { state: 'error', last_error: `volume ${volume} not found on the storage box` });
    log('error', 'local volume not found', { uuid, volume });
    return 'failed';
  }
  const mirrorDev = (await fsp.stat(DIRS.mirror).catch(() => null))?.dev;
  if (st.dev !== mirrorDev) {
    log('error', 'local volume is on another filesystem than the mirror; copying it instead', { uuid, volume });
    return 'copy';
  }

  // Unchanged since the last link pass: nothing to do, and no new snapshot.
  if ((await linkedTo(volume, dest)) && (await latestSnapshotDir(uuid)) !== null) return undefined;

  await report(entry, { state: 'replicating' });
  const tmp = path.join(DIRS.mirror, `.${uuid}.link`);
  const old = path.join(DIRS.mirror, `.${uuid}.old`);
  await removeTree(tmp);
  try {
    await run('cp', ['-al', volume, tmp]);
  } catch (e) {
    await removeTree(tmp);
    log('error', 'linking the local volume failed; copying it instead', { uuid, err: e.message });
    return 'copy';
  }

  // Swap the new tree in, then drop the old one: its files are the copy from the old node.
  await removeTree(old);
  await fsp.rename(dest, old).catch(() => {});
  await fsp.rename(tmp, dest);

  const snapBase = path.join(DIRS.snapshots, uuid);
  await fsp.mkdir(snapBase, { recursive: true });
  try {
    await run('cp', ['-al', dest, path.join(snapBase, new Date().toISOString().replace(/[:.]/g, '-'))]);
  } catch (e) {
    log('error', 'snapshot failed (non-fatal)', { uuid, err: e.message });
  }
  // Every older snapshot holds the old copy too.
  await pruneSnapshots(uuid, 1);
  await removeTree(old);

  await passComplete(entry, dest);
  return undefined;
}

async function report(entry, fields) {
  try {
    await panelRequest('POST', '/plugged-host/storage/agent/report', {
      server_id: entry.server_id,
      uuid: entry.uuid,
      ...fields,
    });
  } catch (e) {
    log('error', 'report failed', { uuid: entry.uuid, err: e.message });
  }
}

// crc32-style stagger so servers do not all replicate on the same tick.
function staggerOffsetMinutes(uuid) {
  let h = 0;
  for (const ch of uuid) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
  return h % Math.max(1, CFG.mirrorIntervalMin);
}

// ssh failures that are about the node, not the server: every other server on that node fails
// the same way, so the node is skipped for a while instead of costing a timeout per server.
const NODE_DOWN = /connect to host |banner exchange|Could not resolve hostname|No route to host|Permission denied \(publickey|Host key verification failed/;
const NODE_BACKOFF_MS = 5 * 60 * 1000;

// A server is due once an interval has passed since its last attempt, most overdue first.
// (Matching one exact minute bucket per server skipped every bucket behind a pass that ran long,
// so a few first copies or one hung node starved everyone else.) The first time a server is
// seen, its uuid offset spreads it over the interval so a restart does not pull every volume.
const lastAttemptAt = new Map(); // uuid -> ms
const nodeDownUntil = new Map(); // host -> ms

// What the last plan said (the cleanup never removes anything in it).
let planUuids = null;      // Set of uuids
let planLocal = new Set(); // uuids of servers on this box
let planAt = 0;
let planSnapshots = null;  // the panel's retention setting
let localVolumes = null;   // this box's wings volume folder, when it is also a node

let mirroring = false;
async function replicationTick() {
  if (mirroring) return;
  mirroring = true;
  try {
    const plan = await panelRequest('GET', '/plugged-host/storage/agent/plan');
    const servers = (Array.isArray(plan.servers) ? plan.servers : []).filter((e) => e && e.uuid);
    planUuids = new Set(servers.map((e) => String(e.uuid)));
    planLocal = new Set(servers.filter((e) => e.local).map((e) => String(e.uuid)));
    planAt = Date.now();
    planSnapshots = Number.isInteger(plan.snapshots) ? plan.snapshots : null;
    localVolumes = typeof plan.local_volumes === 'string' && plan.local_volumes.startsWith('/') ? plan.local_volumes : null;
    const intervalMs = Math.max(1, CFG.mirrorIntervalMin) * 60 * 1000;
    const now = Date.now();

    const inPlan = new Set(servers.map((e) => String(e.uuid)));
    for (const uuid of lastAttemptAt.keys()) if (!inPlan.has(uuid)) lastAttemptAt.delete(uuid);
    for (const uuid of inPlan) {
      if (!lastAttemptAt.has(uuid)) lastAttemptAt.set(uuid, now - intervalMs + staggerOffsetMinutes(uuid) * 60 * 1000);
    }

    const queue = servers
      .filter((e) => now - lastAttemptAt.get(String(e.uuid)) >= intervalMs)
      .sort((a, b) => lastAttemptAt.get(String(a.uuid)) - lastAttemptAt.get(String(b.uuid)));

    // PH_MIRROR_JOBS passes at once (1: one after another), most overdue first, never two from
    // the same node: each is an rsync over SSH that node serves, next to its game servers.
    const busyHosts = new Set();
    let waiters = [];
    const take = () => {
      for (let i = 0; i < queue.length; i++) {
        const entry = queue[i];
        if ((nodeDownUntil.get(entry.host) || 0) > Date.now()) { queue.splice(i--, 1); continue; }
        if (busyHosts.has(entry.host)) continue;
        queue.splice(i, 1);
        return entry;
      }
      return null;
    };
    const worker = async () => {
      for (;;) {
        const entry = take();
        if (!entry) {
          // What is left is on nodes another pass is using: wait for one to finish.
          if (queue.length === 0 || busyHosts.size === 0) return;
          await new Promise((resolve) => waiters.push(resolve));
          continue;
        }
        busyHosts.add(entry.host);
        lastAttemptAt.set(String(entry.uuid), Date.now());
        try {
          const result = await mirrorServer(entry).catch((e) => log('error', 'mirrorServer threw', { err: e.message }));
          if (result === 'node-down') {
            nodeDownUntil.set(entry.host, Date.now() + NODE_BACKOFF_MS);
            log('error', 'node unreachable, skipping its servers for a while', { host: entry.host });
          }
        } finally {
          busyHosts.delete(entry.host);
          const wake = waiters;
          waiters = [];
          wake.forEach((resolve) => resolve());
        }
      }
    };
    await Promise.all(Array.from({ length: CFG.mirrorJobs }, worker));
  } catch (e) {
    log('error', 'replication tick failed', { err: e.message });
  } finally {
    mirroring = false;
  }
}

// ---- inbound HTTP: archives, reachability, health, info ---------------------

// Archives are built from the newest snapshot when the server has one: it is the mirror as it
// was at the end of the last pass, hardlinked, so a pass running meanwhile cannot change files
// under tar. A built archive is kept in archives/ and reused until the source changes (the
// next mirror pass removes it), so a second download -- or a retry -- starts at once, with a
// size and resumable ranges.
async function archiveSource(resolved) {
  if (resolved.source === path.basename(DIRS.mirror)) {
    return (await latestSnapshotDir(resolved.uuid)) || resolved.dir;
  }
  return resolved.dir;
}

function cachedArchivePath(uuid) {
  return path.join(DIRS.archives, `${uuid}.tar.gz`);
}

// Next to each cached archive, which folder it was built from and when the build started.
// Snapshot folders are named by pass, so a new pass means a new source and the archive is out
// of date -- even across agent restarts. (Folder mtimes cannot tell: `cp -al` copies them.)
function cacheMetaPath(uuid) {
  return path.join(DIRS.archives, `${uuid}.json`);
}

const lastPassAt = new Map(); // uuid -> ms of the last completed mirror pass (this process)

// The cached archive when it was built from the current source, else null.
async function freshCachedArchive(uuid, srcDir) {
  const [archive, meta] = await Promise.all([
    fsp.stat(cachedArchivePath(uuid)).catch(() => null),
    fsp.readFile(cacheMetaPath(uuid), 'utf8').then(JSON.parse).catch(() => null),
  ]);
  if (!archive || !archive.size || !meta || meta.source !== srcDir) return null;
  // Built from the live mirror (no snapshot) while a pass ran: out of date.
  if ((lastPassAt.get(uuid) || 0) > Number(meta.started_at || 0)) return null;
  return { path: cachedArchivePath(uuid), size: archive.size };
}

async function writeCacheMeta(uuid, srcDir, startedAt) {
  await fsp.writeFile(cacheMetaPath(uuid), JSON.stringify({ source: srcDir, started_at: startedAt.getTime() })).catch(() => {});
}

async function dropCachedArchive(uuid) {
  await fsp.rm(cacheMetaPath(uuid), { force: true }).catch(() => {});
  await fsp.rm(cachedArchivePath(uuid), { force: true }).catch(() => {});
}

let archiveJobs = 0;
const busySources = new Map();    // folder -> tar builds reading it (snapshot pruning skips these)
const cacheWriters = new Set();   // uuids whose cached archive is being written right now
const completeBuilds = new Map(); // uuid -> Promise of a complete build (restores share one)
const streamingNow = new Set();   // uuids with a streaming download (one tar per server at a time)

function holdSource(dir) {
  busySources.set(dir, (busySources.get(dir) || 0) + 1);
}

function releaseSource(dir) {
  const n = (busySources.get(dir) || 0) - 1;
  if (n > 0) busySources.set(dir, n); else busySources.delete(dir);
}

function tarArgs(srcDir) {
  const excludeArgs = EXCLUDES.flatMap((pat) => ['--exclude', pat]);
  return ['-c', '-f', '-', '-I', COMPRESSOR, ...excludeArgs, '-C', srcDir, '.'];
}

// tar exits 1 when a file changed while it was read; the archive is still whole.
function tarOk(code) {
  return code === 0 || code === 1;
}

// Build the whole archive into the cache, then return it (restores: wings gets a complete file
// with a Content-Length, as it always has). Concurrent requests for one server share the build.
function buildCompleteArchive(uuid, srcDir) {
  if (completeBuilds.has(uuid)) return completeBuilds.get(uuid);

  const job = (async () => {
    const startedAt = new Date();
    const tmpOut = path.join(DIRS.archives, `${uuid}.${Date.now()}.tmp.tar.gz`);
    archiveJobs++;
    holdSource(srcDir);
    cacheWriters.add(uuid);
    await fsp.rm(cacheMetaPath(uuid), { force: true }).catch(() => {});
    try {
      const code = await new Promise((resolve, reject) => {
        const child = spawn('tar', tarArgs(srcDir), { stdio: ['ignore', 'pipe', 'ignore'] });
        const file = fs.createWriteStream(tmpOut);
        child.on('error', reject);
        file.on('error', reject);
        child.stdout.pipe(file);
        let exit = null;
        let flushed = false;
        const done = () => { if (exit !== null && flushed) resolve(exit); };
        child.on('close', (c) => { exit = c; done(); });
        file.on('finish', () => { flushed = true; done(); });
      });
      if (!tarOk(code)) throw new Error(`tar exited ${code}`);
      await fsp.rename(tmpOut, cachedArchivePath(uuid));
      await writeCacheMeta(uuid, srcDir, startedAt);
      const st = await fsp.stat(cachedArchivePath(uuid));
      return { path: cachedArchivePath(uuid), size: st.size };
    } catch (e) {
      await fsp.rm(tmpOut, { force: true }).catch(() => {});
      throw e;
    } finally {
      archiveJobs--;
      releaseSource(srcDir);
      cacheWriters.delete(uuid);
      completeBuilds.delete(uuid);
    }
  })();

  completeBuilds.set(uuid, job);
  return job;
}

// Stream the archive to the browser while tar writes it, so the download starts within a
// second instead of after the whole build. The first stream for a server also writes the
// cache; if the browser goes away, that build still finishes so a retry starts instantly.
function streamArchive(res, uuid, srcDir, headers) {
  const writesCache = !cacheWriters.has(uuid);
  const startedAt = new Date();
  const tmpOut = path.join(DIRS.archives, `${uuid}.${Date.now()}.tmp.tar.gz`);
  const child = spawn('tar', tarArgs(srcDir), { stdio: ['ignore', 'pipe', 'ignore'] });
  let file = null;

  archiveJobs++;
  holdSource(srcDir);
  streamingNow.add(uuid);
  if (writesCache) {
    cacheWriters.add(uuid);
    fsp.rm(cacheMetaPath(uuid), { force: true }).catch(() => {});
    file = fs.createWriteStream(tmpOut);
    file.on('error', () => { child.stdout.unpipe(file); file = null; });
    child.stdout.pipe(file);
  }

  res.writeHead(200, headers);
  res.on('error', () => {});
  // end: false -- the response is only ended once tar says the archive is whole (below).
  child.stdout.pipe(res, { end: false });

  // tar runs only as fast as the browser reads, so a client that stops reading would hold
  // this build (and its slot) for as long as it keeps the connection open. Drop it after a
  // stall; a cache build carries on without it.
  let lastDrain = Date.now();
  res.on('drain', () => { lastDrain = Date.now(); });
  const stallCheck = setInterval(() => {
    if (res.writableNeedDrain && Date.now() - lastDrain > CFG.streamStallMs) res.destroy();
  }, 5000);

  let responseDone = false;
  res.on('close', () => {
    clearInterval(stallCheck);
    if (responseDone) return;
    child.stdout.unpipe(res);
    // Nobody left to receive it and no cache to fill: stop the build.
    if (!file) child.kill('SIGTERM');
  });

  child.on('error', () => res.destroy());
  child.on('close', async (code) => {
    archiveJobs--;
    releaseSource(srcDir);
    streamingNow.delete(uuid);
    clearInterval(stallCheck);
    const ok = tarOk(code);
    responseDone = true;
    // A failed build must not look like a finished download: cut the connection so the
    // browser reports a failure instead of saving a truncated file.
    if (!ok) res.destroy(); else if (!res.writableEnded) res.end();

    if (!writesCache) return;
    try {
      if (file) {
        await new Promise((resolve) => (file.writableFinished ? resolve() : file.on('finish', resolve).on('close', resolve)));
      }
      if (ok && file) {
        await fsp.rename(tmpOut, cachedArchivePath(uuid));
        await writeCacheMeta(uuid, srcDir, startedAt);
      } else {
        await fsp.rm(tmpOut, { force: true });
      }
    } catch {
      await fsp.rm(tmpOut, { force: true }).catch(() => {});
    } finally {
      cacheWriters.delete(uuid);
    }
  });
}

// A built archive from disk, with a size and single-range support (resuming, download managers).
function sendFile(req, res, file, headers) {
  const range = /^bytes=(\d*)-(\d*)$/.exec(String(req.headers.range || ''));
  let start = 0;
  let end = file.size - 1;

  if (range && (range[1] !== '' || range[2] !== '')) {
    if (range[1] === '') {
      start = Math.max(0, file.size - Number(range[2]));
    } else {
      start = Number(range[1]);
      if (range[2] !== '') end = Math.min(end, Number(range[2]));
    }
    if (start > end || start >= file.size) {
      res.writeHead(416, { 'Content-Range': `bytes */${file.size}` });
      return res.end();
    }
    res.writeHead(206, { ...headers, 'Content-Length': end - start + 1, 'Content-Range': `bytes ${start}-${end}/${file.size}` });
  } else {
    res.writeHead(200, { ...headers, 'Content-Length': file.size });
  }

  return pipeline(fs.createReadStream(file.path, { start, end }), res).catch(() => res.destroy());
}

// What a browser gets: the stream. What wings gets for a restore (no browser User-Agent):
// the complete file, exactly as before. ?stream=1 / ?stream=0 force either.
function wantsStream(req, q) {
  if (q.get('stream') === '1') return true;
  if (q.get('stream') === '0') return false;
  return /^Mozilla\//.test(String(req.headers['user-agent'] || ''));
}

// Folder sizes for /info, remembered until the next mirror pass (du walks every file).
const sizeCache = new Map(); // uuid -> { bytes, at }
const SIZE_TTL_MS = 30 * 60 * 1000;

async function cachedDirSize(uuid, dir) {
  const hit = sizeCache.get(uuid);
  if (hit && Date.now() - hit.at < SIZE_TTL_MS) return hit.bytes;
  const bytes = await dirSizeBytes(dir);
  sizeCache.set(uuid, { bytes, at: Date.now() });
  return bytes;
}

function probeTcp(host, port, timeoutMs = 3000) {
  return new Promise((resolve) => {
    const sock = net.connect({ host, port: Number(port) });
    const done = (ok) => {
      sock.destroy();
      resolve(ok);
    };
    sock.setTimeout(timeoutMs);
    sock.once('connect', () => done(true));
    sock.once('timeout', () => done(false));
    sock.once('error', () => done(false));
  });
}

function startServer() {
  const server = http.createServer(async (req, res) => {
    try {
      const url = new URL(req.url, 'http://localhost');
      const q = url.searchParams;

      if (url.pathname === '/health') {
        const free = await freePercent();
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          ok: true,
          version: CFG.version,
          archive_source: CFG.archiveSource,
          free_percent: free,
          mirror_jobs: CFG.mirrorJobs,
        }));
      }

      // GET /info/<id>?expires=..&sig=.. -> inspect backup existence, size, last mod
      if (url.pathname.startsWith('/info/')) {
        const id = decodeURIComponent(url.pathname.slice('/info/'.length));
        if (!/^[a-f0-9-]{6,}$/i.test(id) || !verifySignedRequest('info', id, q.get('expires'), q.get('sig'))) {
          res.writeHead(403); return res.end('forbidden');
        }
        const resolved = await resolveServerDir(id);
        if (!resolved) {
          res.writeHead(200, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ ok: true, exists: false }));
        }
        const srcDir = await archiveSource(resolved);
        const [st, bytes, cached] = await Promise.all([
          fsp.stat(srcDir).catch(() => null),
          cachedDirSize(resolved.uuid, resolved.dir),
          freshCachedArchive(resolved.uuid, srcDir),
        ]);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({
          ok: true,
          exists: true,
          uuid: resolved.uuid,
          source: resolved.source,
          bytes,
          // When the copy was taken. A snapshot folder keeps the volume's own mtime (rsync -a and
          // cp -al copy it), but is never changed after the pass created it, so its ctime is.
          mtime: st ? (srcDir !== resolved.dir ? st.ctime : st.mtime).toISOString() : null,
          // A ready archive downloads at once, with a known size.
          archive_bytes: cached ? cached.size : null,
        }));
      }

      // GET /archive/<uuid>?expires=..&sig=..&filename=..  -> streams the on-demand tar directly
      if (url.pathname.startsWith('/archive/')) {
        const id = decodeURIComponent(url.pathname.slice('/archive/'.length));
        if (!/^[a-f0-9-]{6,}$/i.test(id) || !verifySignedRequest('archive', id, q.get('expires'), q.get('sig'))) {
          res.writeHead(403); return res.end('forbidden');
        }
        const resolved = await resolveServerDir(id);
        if (!resolved) { res.writeHead(404); return res.end('no mirror'); }
        const srcDir = await archiveSource(resolved);

        const customFilename = q.get('filename')
          ? q.get('filename').replace(/[^a-zA-Z0-9_.-]/g, '_')
          : `backup-${resolved.uuid.slice(0, 8)}.tar.gz`;

        const headers = {
          'Content-Type': 'application/octet-stream',
          'Content-Disposition': `attachment; filename="${customFilename}"`,
          'Cache-Control': 'no-store, no-cache, must-revalidate',
          'Pragma': 'no-cache',
          'X-Content-Type-Options': 'nosniff',
        };

        // Already built and still current: send it straight away, with a size and ranges.
        const cached = await freshCachedArchive(resolved.uuid, srcDir);
        if (cached) {
          return sendFile(req, res, cached, { ...headers, 'Accept-Ranges': 'bytes' });
        }

        if (archiveJobs >= CFG.maxArchiveJobs && !completeBuilds.has(resolved.uuid)) {
          res.writeHead(503, { 'Retry-After': '30', 'Content-Type': 'text/plain' });
          return res.end('busy, try again in a moment');
        }

        if (wantsStream(req, q)) {
          if (streamingNow.has(resolved.uuid)) {
            res.writeHead(503, { 'Retry-After': '30', 'Content-Type': 'text/plain' });
            return res.end('this server is already being downloaded, try again in a moment');
          }
          return streamArchive(res, resolved.uuid, srcDir, headers);
        }

        const built = await buildCompleteArchive(resolved.uuid, srcDir);
        return sendFile(req, res, built, { ...headers, 'Accept-Ranges': 'bytes' });
      }

      // GET /reachable?host=..&port=..&expires=..&sig=..  -> second-witness node probe.
      if (url.pathname === '/reachable') {
        const host = q.get('host') || '';
        const port = q.get('port') || '';
        if (!verifySignedRequest('reachable', `${host}:${port}`, q.get('expires'), q.get('sig'))) {
          res.writeHead(403); return res.end('forbidden');
        }
        const ok = await probeTcp(host, port);
        res.writeHead(200, { 'Content-Type': 'application/json' });
        return res.end(JSON.stringify({ reachable: ok }));
      }

      res.writeHead(404); res.end('not found');
    } catch (e) {
      log('error', 'request handler threw', { err: e.message });
      try { res.writeHead(500); res.end('error'); } catch { /* already sent */ }
    }
  });
  server.listen(CFG.port, () => log('info', 'agent listening', { port: CFG.port }));
}

// ---- housekeeping: copies of servers that are gone ---------------------------
//
// The agent never decides on its own what to delete. Once an hour it reports the server folders
// it holds (mirror, snapshots, cached archive, and this box's own wings volumes) to the panel,
// which answers with the ones whose server is gone and whose hold is over: 7 days after an admin
// deleted the server, at once when its owner did; a folder the panel has no record of, 7 days
// after its last copy and at least a day after it was first reported. Only those are removed,
// and never one in the current plan.

const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const HOUSEKEEPING_INTERVAL_MS = 60 * 60 * 1000;
// A cached archive is reused only until the next pass, so one older than this belongs to a
// server no longer mirrored (gone, or its node down) and is a whole extra copy.
const ARCHIVE_MAX_AGE_MS = 24 * 60 * 60 * 1000;

// Snapshot folder name (2026-10-10T07-03-25-200Z) -> ISO time.
function snapshotTime(name) {
  const m = /^(\d{4}-\d{2}-\d{2})T(\d{2})-(\d{2})-(\d{2})-(\d{3})Z$/.exec(name);
  return m ? `${m[1]}T${m[2]}:${m[3]}:${m[4]}.${m[5]}Z` : null;
}

// Every server with a folder here, with the time of its newest snapshot (its last copy).
async function inventory() {
  const uuids = new Set();
  for (const base of [DIRS.mirror, DIRS.snapshots]) {
    for (const name of await fsp.readdir(base).catch(() => [])) if (UUID_RE.test(name)) uuids.add(name);
  }
  for (const name of await fsp.readdir(DIRS.archives).catch(() => [])) {
    const m = /^(.+)\.(tar\.gz|json)$/.exec(name);
    if (m && UUID_RE.test(m[1])) uuids.add(m[1]);
  }
  const copies = [];
  for (const uuid of uuids) {
    const snaps = (await fsp.readdir(path.join(DIRS.snapshots, uuid)).catch(() => [])).sort();
    copies.push({ uuid, last_copy: snaps.length ? snapshotTime(snaps[snaps.length - 1]) : null });
  }
  return copies;
}

function copyInUse(uuid) {
  if (cacheWriters.has(uuid) || streamingNow.has(uuid) || completeBuilds.has(uuid)) return true;
  const snapBase = path.join(DIRS.snapshots, uuid) + '/';
  for (const dir of busySources.keys()) if (dir.startsWith(snapBase) || dir === path.join(DIRS.mirror, uuid)) return true;
  return false;
}

async function purgeCopy(uuid) {
  await removeTree(path.join(DIRS.mirror, uuid));
  await removeTree(path.join(DIRS.snapshots, uuid));
  await dropCachedArchive(uuid);
  sizeCache.delete(uuid);
  lastPassAt.delete(uuid);
  log('info', 'removed the copy of a deleted server', { uuid });
}

async function dropStaleArchives() {
  for (const name of await fsp.readdir(DIRS.archives).catch(() => [])) {
    const m = /^(.+)\.tar\.gz$/.exec(name);
    if (!m || name.endsWith('.tmp.tar.gz') || cacheWriters.has(m[1])) continue;
    const st = await fsp.stat(path.join(DIRS.archives, name)).catch(() => null);
    // An open download keeps reading the file it has open.
    if (st && Date.now() - st.mtimeMs > ARCHIVE_MAX_AGE_MS) {
      await dropCachedArchive(m[1]);
      log('info', 'dropped a stale cached archive', { uuid: m[1], bytes: st.size });
    }
  }
}

let housekeeping = false;
async function housekeepingTick() {
  if (housekeeping) return;
  housekeeping = true;
  try {
    await dropStaleArchives();

    // Without a plan from the last couple of hours there is no telling what is live: wait.
    if (!planUuids || Date.now() - planAt > 2 * HOUSEKEEPING_INTERVAL_MS) return;

    const copies = await inventory();
    const volumeBase = localVolumes;
    const volumes = volumeBase
      ? (await fsp.readdir(volumeBase).catch(() => [])).filter((name) => UUID_RE.test(name))
      : [];

    const answer = await panelRequest('POST', '/plugged-host/storage/agent/inventory', { copies, volumes });
    const purge = (Array.isArray(answer.purge) ? answer.purge : []).filter((u) => typeof u === 'string' && UUID_RE.test(u));
    const purgeVolumes = (Array.isArray(answer.purge_volumes) ? answer.purge_volumes : []).filter((u) => typeof u === 'string' && UUID_RE.test(u));
    if (purge.length || purgeVolumes.length) {
      log('info', 'housekeeping', { folders: copies.length, volumes: volumes.length, purge: purge.length, purge_volumes: purgeVolumes.length });
    }

    for (const uuid of purge) {
      // The plan is re-read every minute while this runs; anything in it stays.
      if (planUuids.has(uuid) || copyInUse(uuid)) continue;
      await purgeCopy(uuid);
    }
    // Volumes of servers that are gone, or that wings left behind when a server moved away.
    for (const uuid of purgeVolumes) {
      if (!volumes.includes(uuid) || planLocal.has(uuid) || localVolumes !== volumeBase) continue;
      await removeTree(path.join(volumeBase, uuid));
      log('info', 'removed a leftover wings volume', { uuid });
    }
  } catch (e) {
    log('error', 'housekeeping failed', { err: e.message });
  } finally {
    housekeeping = false;
  }
}

// ---- self-update (pull, verify signature + digest, swap symlink) ------------

async function fetchSigned(apiPath) {
  return new Promise((resolve, reject) => {
    const url = new URL(CFG.panelUrl + apiPath);
    const lib = url.protocol === 'http:' ? http : https;
    lib.get(url, { timeout: 60000 }, (res) => {
      if (res.statusCode !== 200) { res.resume(); return reject(new Error(`${apiPath} -> ${res.statusCode}`)); }
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => resolve({ buffer: Buffer.concat(chunks), headers: res.headers }));
    }).on('error', reject);
  });
}

async function updateTick() {
  try {
    const { buffer, headers } = await fetchSigned('/plugged-host/storage/agent/manifest.json');
    // Signed once per box (X-PH-Signature: the storage box, X-PH-Signature-Backup: the backup
    // server); one of them has to be this agent's own.
    const mine = hmac(buffer.toString());
    const signatures = Object.entries(headers).filter(([name]) => /^x-ph-signature(-[a-z]+)?$/.test(name)).map(([, value]) => String(value));
    if (!signatures.some((sig) => safeEqual(sig, mine))) {
      log('error', 'manifest signature mismatch, ignoring'); return;
    }
    const manifest = JSON.parse(buffer.toString());
    if (!manifest.version || manifest.version === CFG.version) return;

    // Versioned URL: the panel sits behind Cloudflare, which caches .tar.gz paths, so a plain
    // URL can serve the previous release's bundle and fail the digest check.
    const { buffer: bundle } = await fetchSigned(`/plugged-host/storage/agent/bundle.tar.gz?v=${encodeURIComponent(manifest.version)}`);
    const digest = crypto.createHash('sha256').update(bundle).digest('hex');
    if (!safeEqual(digest, manifest.sha256 || '')) {
      log('error', 'bundle digest mismatch, refusing update', { want: manifest.sha256, got: digest }); return;
    }

    const releaseDir = path.join(CFG.releaseDir, 'releases', String(manifest.version));
    await fsp.mkdir(releaseDir, { recursive: true });
    const tmpTar = path.join(CFG.releaseDir, `.bundle-${manifest.version}.tar.gz`);
    await fsp.writeFile(tmpTar, bundle);
    await run('tar', ['-xzf', tmpTar, '-C', releaseDir]);
    await fsp.unlink(tmpTar).catch(() => {});

    const currentLink = path.join(CFG.releaseDir, 'current');
    const tmpLink = currentLink + '.new';
    await fsp.rm(tmpLink, { force: true }).catch(() => {});
    await fsp.symlink(releaseDir, tmpLink);
    await fsp.rename(tmpLink, currentLink);

    log('info', 'updated, restarting for new version', { version: manifest.version });
    process.exit(0);
  } catch (e) {
    log('error', 'update tick failed', { err: e.message });
  }
}

// ---- main -------------------------------------------------------------------

async function main() {
  if (!CFG.secret) { log('error', 'PH_AGENT_SECRET not set; refusing to start'); process.exit(1); }
  if (CFG.secret === 'CHANGE_ME' || CFG.secret.length < 32) {
    // Anyone who knows the secret can sign archive downloads of every server: use a long random one.
    log('error', 'PH_AGENT_SECRET is the installer placeholder or shorter than 32 characters; set a long random secret here and as STORAGE_AGENT_SECRET on the panel');
  }
  await ensureDirs();
  startServer();

  const everyMinute = 60 * 1000;
  setInterval(() => replicationTick(), everyMinute);
  setInterval(() => updateTick(), CFG.updateIntervalMin * everyMinute);
  setInterval(() => housekeepingTick(), HOUSEKEEPING_INTERVAL_MS);
  replicationTick();
  updateTick();
  // Once the first plan is in.
  setTimeout(() => housekeepingTick(), 5 * everyMinute);

  log('info', 'plugged.host storage agent started', {
    version: CFG.version,
    root: CFG.root,
    archiveSource: CFG.archiveSource,
    mirrorJobs: CFG.mirrorJobs,
  });
}

main().catch((e) => { log('error', 'fatal', { err: e.message }); process.exit(1); });
