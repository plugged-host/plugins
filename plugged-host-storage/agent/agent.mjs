#!/usr/bin/env node
// plugged.host storage agent (WP9).
//
// Runs on the storage box, NOT the panel host. It PULLS: it initiates each rsync from the nodes,
// serves a tar of a server's mirror behind an HMAC-signed URL (streamed to browsers as it is
// built, complete with a size for wings restores, cached until the next mirror pass), answers node-reachability probes for the panel, reports mirror size and
// freshness back, and self-updates from a signed bundle the panel serves.
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
//   PH_SNAPSHOTS           default 3     (rotating --link-dest snapshots kept)
//   PH_MIRROR_INTERVAL_MIN default 30
//   PH_UPDATE_INTERVAL_MIN default 15
//   PH_MAX_ARCHIVE_JOBS    default 3     (tar builds at once; more answer 503 + Retry-After)
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
  return process.env.PH_VERSION || '1.3.2';
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
        if (st.isDirectory()) {
          const files = await fsp.readdir(exactPath).catch(() => []);
          if (files.length > 0) {
            return { dir: exactPath, uuid: safeId, source: path.basename(base) };
          }
        }
      }

      // 2. Prefix match (e.g. 8-character short ID like a1b2c3d4)
      const entries = await fsp.readdir(base, { withFileTypes: true }).catch(() => []);
      for (const entry of entries) {
        if (entry.isDirectory() && entry.name.toLowerCase().startsWith(safeId)) {
          const dirPath = path.join(base, entry.name);
          const files = await fsp.readdir(dirPath).catch(() => []);
          if (files.length > 0) {
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

  // Rotate: keep the newest N snapshots.
  const snaps = (await fsp.readdir(snapBase).catch(() => [])).sort();
  while (snaps.length > CFG.snapshots) {
    const victim = snaps.shift();
    await run('rm', ['-rf', path.join(snapBase, victim)]).catch(() => {});
  }

  const bytes = await dirSizeBytes(dest);
  sizeCache.set(uuid, { bytes, at: Date.now() });
  // The mirror changed, so the cached archive is out of date (freshCachedArchive would say so
  // too: new snapshot, or a pass newer than the build). A build writing it right now finishes
  // and is simply not trusted.
  lastPassAt.set(uuid, Date.now());
  if (!cacheWriters.has(uuid)) await dropCachedArchive(uuid);
  await report(entry, { state: 'ok', bytes, last_replicated_at: new Date().toISOString(), mirror_path: dest });
  log('info', 'mirror pass complete', { uuid, bytes });
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

let mirroring = false;
async function replicationTick() {
  if (mirroring) return;
  mirroring = true;
  try {
    const plan = await panelRequest('GET', '/plugged-host/storage/agent/plan');
    const servers = (Array.isArray(plan.servers) ? plan.servers : []).filter((e) => e && e.uuid);
    const intervalMs = Math.max(1, CFG.mirrorIntervalMin) * 60 * 1000;
    const now = Date.now();

    const inPlan = new Set(servers.map((e) => String(e.uuid)));
    for (const uuid of lastAttemptAt.keys()) if (!inPlan.has(uuid)) lastAttemptAt.delete(uuid);
    for (const uuid of inPlan) {
      if (!lastAttemptAt.has(uuid)) lastAttemptAt.set(uuid, now - intervalMs + staggerOffsetMinutes(uuid) * 60 * 1000);
    }

    const due = servers
      .filter((e) => now - lastAttemptAt.get(String(e.uuid)) >= intervalMs)
      .sort((a, b) => lastAttemptAt.get(String(a.uuid)) - lastAttemptAt.get(String(b.uuid)));

    for (const entry of due) {
      if ((nodeDownUntil.get(entry.host) || 0) > Date.now()) continue;
      lastAttemptAt.set(String(entry.uuid), Date.now());
      const result = await mirrorServer(entry).catch((e) => log('error', 'mirrorServer threw', { err: e.message }));
      if (result === 'node-down') {
        nodeDownUntil.set(entry.host, Date.now() + NODE_BACKOFF_MS);
        log('error', 'node unreachable, skipping its servers for a while', { host: entry.host });
      }
    }
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
    const snapBase = path.join(DIRS.snapshots, resolved.uuid);
    const snaps = (await fsp.readdir(snapBase).catch(() => [])).sort();
    for (let i = snaps.length - 1; i >= 0; i--) {
      const dir = path.join(snapBase, snaps[i]);
      const files = await fsp.readdir(dir).catch(() => []);
      if (files.length > 0) return dir;
    }
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
const cacheWriters = new Set();   // uuids whose cached archive is being written right now
const completeBuilds = new Map(); // uuid -> Promise of a complete build (restores share one)
const streamingNow = new Set();   // uuids with a streaming download (one tar per server at a time)

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
          mtime: st ? st.mtime.toISOString() : null,
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
    if (!safeEqual(headers['x-ph-signature'] || '', hmac(buffer.toString()))) {
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
  replicationTick();
  updateTick();

  log('info', 'plugged.host storage agent started', {
    version: CFG.version,
    root: CFG.root,
    archiveSource: CFG.archiveSource,
  });
}

main().catch((e) => { log('error', 'fatal', { err: e.message }); process.exit(1); });
