#!/usr/bin/env python3
"""plugged.host abuse agent: what htop shows on a Wings node, reported to the panel every minute.

Runs on each Wings node as root (systemd, see install.sh). Every INTERVAL seconds it reads /proc:
each process's name, executable, command line, CPU use and container (Wings names a server's
container by the server's uuid), each server container's outgoing connections and packet rate,
and the host's own processes. Executables using real CPU are checked once for strings a renamed
miner still carries (XMRig, stratum+tcp, RandomX).

It only collects. Every rule lives on the panel (plugged-host-core, ProcessInspector), so a new
signature needs no agent update, and the panel decides what to suspend.

The report is signed with the node's own Wings token from /etc/pelican/config.yml (HMAC-SHA256
over the timestamp and the body hash), so there is no secret to provision. Standard library only.

    abuse-agent.py            run forever
    abuse-agent.py --once     one report, then exit
    abuse-agent.py --print    collect once and print the report instead of sending it
"""

import gzip
import hashlib
import hmac
import json
import os
import re
import socket
import sys
import time
import urllib.error
import urllib.request

# The plugins release (the VERSION file; bump them together) and the start of this file's sha256,
# e.g. 2.2.0.1a2b3c4: the same shape as the panel's v2.2.0.<commit>.
RELEASE = "2.2.1"
try:
    with open(os.path.abspath(__file__), "rb") as _fh:
        VERSION = RELEASE + "." + hashlib.sha256(_fh.read()).hexdigest()[:7]
except OSError:
    VERSION = RELEASE

INTERVAL = max(30, int(os.environ.get("PH_ABUSE_INTERVAL", "60")))
DOCKER_ROOT = os.environ.get("PH_DOCKER_ROOT", "/var/lib/docker")
CONFIG_PATHS = [os.environ.get("PH_WINGS_CONFIG"), "/etc/pelican/config.yml", "/etc/pterodactyl/config.yml"]
CLK_TCK = os.sysconf("SC_CLK_TCK")

CONTAINER_ID = re.compile(r"[0-9a-f]{64}")
MAX_CONTAINER_PROCS = 40
MAX_HOST_PROCS = 400
MAX_CMD = 1024

# Executables are fingerprinted once per binary (device, inode, size, mtime), and only when the
# process uses real CPU or hides its binary, so idle game servers cost nothing.
FINGERPRINT_MIN_CPU = 15.0
FINGERPRINT_MAX_BYTES = 64 * 1024 * 1024
FINGERPRINT = re.compile(rb"xmrig|stratum\+(?:tcp|ssl)|donate-level|randomx|cryptonight", re.I)
TEMP_DIR = re.compile(r"^(?:/tmp/|/var/tmp/|/dev/shm/|/run/shm/)")

# Remote ports always reported when a container connects to them, whatever else is busier.
WATCHED_PORTS = {22, 23, 25, 465, 587, 2323, 3389, 3333, 4444, 5555, 6666, 7777, 9999, 10128,
                 10343, 13333, 14433, 14444, 17777, 20128, 45560, 45700}


def log(message):
    print(time.strftime("%Y-%m-%d %H:%M:%S"), message, flush=True)


def read_wings_config():
    for path in CONFIG_PATHS:
        if not path or not os.path.isfile(path):
            continue
        values = {}
        with open(path) as handle:
            for line in handle:
                match = re.match(r"^(token_id|token|remote):\s*['\"]?([^'\"\s#]+)['\"]?\s*$", line)
                if match:
                    values[match.group(1)] = match.group(2)
        if all(key in values for key in ("token_id", "token", "remote")):
            values["path"] = path
            return values
    return None


class Collector:
    def __init__(self):
        self.previous = {}      # (pid, start) -> cpu ticks
        self.previous_at = None
        self.host_previous = None
        self.net_previous = {}  # container id -> (time, tx_packets, tx_bytes)
        self.names = {}         # container id -> name
        self.fingerprints = {}  # binary key -> tags

    # ---------------------------------------------------------------- processes

    def read_procs(self):
        procs = {}
        for entry in os.listdir("/proc"):
            if not entry.isdigit():
                continue
            pid = int(entry)
            base = "/proc/" + entry
            try:
                with open(base + "/stat", "rb") as handle:
                    stat = handle.read().decode("utf-8", "replace")
                exe = os.readlink(base + "/exe")
            except OSError:
                continue  # gone, or a kernel thread (no executable)
            left, right = stat.find("("), stat.rfind(")")
            fields = stat[right + 2:].split()
            if left < 0 or right < 0 or len(fields) < 20:
                continue
            try:
                with open(base + "/cmdline", "rb") as handle:
                    cmd = handle.read(MAX_CMD * 2).replace(b"\0", b" ").decode("utf-8", "replace").strip()
            except OSError:
                cmd = ""
            try:
                with open(base + "/cgroup") as handle:
                    match = CONTAINER_ID.search(handle.read())
                container = match.group(0) if match else None
            except OSError:
                container = None
            procs[pid] = {
                "pid": pid,
                "ppid": int(fields[1]),
                "comm": stat[left + 1:right],
                "exe": exe,
                "cmd": cmd[:MAX_CMD],
                "ticks": int(fields[11]) + int(fields[12]),
                "start": int(fields[19]),
                "container": container,
            }
        return procs

    def container_name(self, container):
        if container not in self.names:
            name = None
            try:
                with open(os.path.join(DOCKER_ROOT, "containers", container, "config.v2.json")) as handle:
                    name = (json.load(handle).get("Name") or "").lstrip("/") or None
            except (OSError, ValueError):
                pass
            self.names[container] = name
        return self.names[container]

    def fingerprint(self, pid):
        path = "/proc/%d/exe" % pid
        try:
            info = os.stat(path)
        except OSError:
            return []
        key = (info.st_dev, info.st_ino, info.st_size, int(info.st_mtime))
        if key in self.fingerprints:
            return self.fingerprints[key]
        tags = set()
        try:
            with open(path, "rb") as handle:
                tail, done = b"", 0
                while done < FINGERPRINT_MAX_BYTES:
                    chunk = handle.read(1 << 20)
                    if not chunk:
                        break
                    done += len(chunk)
                    for match in FINGERPRINT.finditer(tail + chunk):
                        word = match.group(0).lower()
                        tags.add("stratum" if word.startswith(b"stratum") else word.decode())
                    tail = chunk[-32:]
        except OSError:
            pass
        if len(self.fingerprints) > 5000:
            self.fingerprints.clear()
        self.fingerprints[key] = sorted(tags)
        return self.fingerprints[key]

    # ---------------------------------------------------------------- network

    @staticmethod
    def parse_address(text):
        address, port = text.split(":")
        raw = bytes.fromhex(address)
        if len(raw) == 4:
            ip = socket.inet_ntop(socket.AF_INET, raw[::-1])
        else:
            raw = b"".join(raw[i:i + 4][::-1] for i in range(0, 16, 4))
            ip = socket.inet_ntop(socket.AF_INET6, raw)
            if ip.startswith("::ffff:"):
                ip = ip[7:]
        return ip, int(port, 16)

    def container_net(self, container, pid, now):
        listening, connections = set(), []
        for table in ("tcp", "tcp6"):
            try:
                with open("/proc/%d/net/%s" % (pid, table)) as handle:
                    next(handle, None)
                    for line in handle:
                        parts = line.split()
                        if len(parts) < 4 or parts[3] not in ("01", "02", "0A"):
                            continue
                        _, local_port = self.parse_address(parts[1])
                        if parts[3] == "0A":
                            listening.add(local_port)
                        else:
                            connections.append((parts[3], local_port) + self.parse_address(parts[2]))
            except (OSError, ValueError):
                pass

        established, connecting, ports = set(), set(), {}
        for state, local_port, remote_ip, remote_port in connections:
            if local_port in listening or remote_ip.startswith("127.") or remote_ip == "::1":
                continue  # someone connecting in, or the container talking to itself
            (connecting if state == "02" else established).add(remote_ip)
            ports.setdefault(remote_port, set()).add(remote_ip)

        busiest = sorted(ports, key=lambda port: len(ports[port]), reverse=True)[:25]
        reported = {str(port): len(ports[port]) for port in ports if port in WATCHED_PORTS or port in busiest}

        tx_packets = tx_bytes = 0
        try:
            with open("/proc/%d/net/dev" % pid) as handle:
                for line in handle.readlines()[2:]:
                    name, _, data = line.partition(":")
                    fields = data.split()
                    if name.strip() != "lo" and len(fields) >= 10:
                        tx_bytes += int(fields[8])
                        tx_packets += int(fields[9])
        except (OSError, ValueError):
            pass

        net = {
            "listen": sorted(listening)[:20],
            "established_ips": len(established),
            "syn_sent_ips": len(connecting),
            "ports": reported,
        }
        previous = self.net_previous.get(container)
        if previous and now > previous[0] and tx_packets >= previous[1]:
            elapsed = now - previous[0]
            net["tx_pps"] = round((tx_packets - previous[1]) / elapsed)
            net["tx_bps"] = round((tx_bytes - previous[2]) * 8 / elapsed)
        self.net_previous[container] = (now, tx_packets, tx_bytes)
        return net

    def host_cpu(self):
        try:
            with open("/proc/stat") as handle:
                values = [int(v) for v in handle.readline().split()[1:]]
        except (OSError, ValueError):
            return 0.0
        idle, total = values[3] + (values[4] if len(values) > 4 else 0), sum(values)
        previous, self.host_previous = self.host_previous, (idle, total)
        if not previous or total <= previous[1]:
            return 0.0
        return round(100.0 * (1 - (idle - previous[0]) / (total - previous[1])), 1)

    # ---------------------------------------------------------------- report

    def collect(self):
        started = time.time()
        procs = self.read_procs()
        now = time.time()
        elapsed = (now - self.previous_at) if self.previous_at else None

        current = {}
        for proc in procs.values():
            key = (proc["pid"], proc["start"])
            current[key] = proc["ticks"]
            before = self.previous.get(key)
            proc["cpu"] = round(100.0 * (proc["ticks"] - before) / CLK_TCK / elapsed, 1) if elapsed and before is not None else 0.0
        self.previous, self.previous_at = current, now

        own = os.getpid()
        containers, host = {}, []
        for proc in procs.values():
            if proc["pid"] == own:
                continue
            exe = proc["exe"]
            deleted = exe.endswith(" (deleted)")
            hidden = deleted or exe.startswith("/memfd:") or bool(TEMP_DIR.match(exe))
            entry = {
                "pid": proc["pid"],
                "ppid": proc["ppid"],
                "comm": proc["comm"],
                "exe": exe,
                "cmd": proc["cmd"],
                "cpu": proc["cpu"],
                "deleted": deleted,
                "tags": self.fingerprint(proc["pid"]) if proc["cpu"] >= FINGERPRINT_MIN_CPU or hidden else [],
            }
            if proc["container"]:
                containers.setdefault(proc["container"], []).append(entry)
            else:
                host.append(entry)

        report_containers = []
        for container, entries in containers.items():
            entries.sort(key=lambda e: e["cpu"], reverse=True)
            report_containers.append({
                "id": container[:12],
                "name": self.container_name(container),
                "procs": entries[:MAX_CONTAINER_PROCS],
                "net": self.container_net(container, min(e["pid"] for e in entries), now),
            })

        for gone in set(self.names) - set(containers):
            self.names.pop(gone, None)
            self.net_previous.pop(gone, None)

        host.sort(key=lambda e: e["cpu"], reverse=True)
        return {
            "agent": {
                "version": VERSION,
                "hostname": socket.gethostname(),
                "interval": INTERVAL,
                "scan_ms": round((time.time() - started) * 1000),
            },
            "host": {
                "load": list(os.getloadavg()),
                "cpus": os.cpu_count(),
                "cpu_percent": self.host_cpu(),
            },
            "containers": report_containers,
            "host_procs": host[:MAX_HOST_PROCS],
        }


def send(config, report):
    url = os.environ.get("PH_PANEL_URL", config["remote"]).rstrip("/") + "/plugged-host/abuse/agent/report"
    body = gzip.compress(json.dumps(report, separators=(",", ":")).encode())
    timestamp = str(int(time.time()))
    signature = hmac.new(config["token"].encode(), (timestamp + "\n" + hashlib.sha256(body).hexdigest()).encode(), hashlib.sha256).hexdigest()
    request = urllib.request.Request(url, data=body, method="POST", headers={
        "Content-Type": "application/json",
        "Accept": "application/json",
        "User-Agent": "plugged-host-abuse-agent/" + VERSION,
        "X-PH-Node": config["token_id"],
        "X-PH-Timestamp": timestamp,
        "X-PH-Signature": signature,
        "X-PH-Encoding": "gzip",
    })
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read() or b"{}")


def main(argv):
    printing, once = "--print" in argv, "--once" in argv or "--print" in argv
    config = read_wings_config()
    if config is None and not printing:
        log("no Wings config with token_id, token and remote (looked in %s)" % ", ".join(p for p in CONFIG_PATHS if p))
        return 1

    collector = Collector()
    collector.collect()  # baseline for CPU and packet rates
    time.sleep(5 if once else min(INTERVAL, 15))

    if config:
        log("plugged.host abuse agent %s reporting to %s every %ds" % (VERSION, os.environ.get("PH_PANEL_URL", config["remote"]), INTERVAL))
    told_update = False
    while True:
        cycle = time.time()
        try:
            report = collector.collect()
            if printing:
                print(json.dumps(report, indent=1))
                return 0
            reply = send(config, report)
            latest = reply.get("latest")
            if latest and latest != VERSION and not told_update:
                log("agent %s is available (running %s): re-run the installer to update" % (latest, VERSION))
                told_update = True
            if reply.get("suspended"):
                log("panel suspended %s server(s) from this report" % reply["suspended"])
        except urllib.error.HTTPError as error:
            log("panel answered HTTP %s: %s" % (error.code, error.read(200).decode("utf-8", "replace").strip()))
        except Exception as error:  # keep reporting through any one bad cycle
            log("report failed: %r" % error)
        if once:
            return 0
        time.sleep(max(1.0, INTERVAL - (time.time() - cycle)))


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
