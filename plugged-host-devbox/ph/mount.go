package main

import (
	"bufio"
	"io"
	"os"
	"strings"
)

// serverDir is where the game server's volume is mounted (Wings refuses targets under
// /home/container, which is the devbox's own home).
const serverDir = "/server"

// mounted reports whether dir is a mount point, read from a /proc/self/mountinfo stream. Wings
// silently skips a mount it is not allowed to make (allowed_mounts) or whose source is missing,
// so the directory existing (it is baked into the image) proves nothing.
func mounted(mountinfo io.Reader, dir string) bool {
	scanner := bufio.NewScanner(mountinfo)
	scanner.Buffer(make([]byte, 64*1024), 1024*1024)
	for scanner.Scan() {
		// 36 35 98:0 /mnt1 /mnt2 rw,noatime master:1 - ext3 /dev/root rw,errors=continue
		fields := strings.Fields(scanner.Text())
		if len(fields) < 5 {
			continue
		}
		if unescapeMountPath(fields[4]) == dir {
			return true
		}
	}

	return false
}

// unescapeMountPath undoes the octal escapes mountinfo uses for space, tab, newline and \.
func unescapeMountPath(s string) string {
	if !strings.Contains(s, `\`) {
		return s
	}
	var b strings.Builder
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' && i+4 <= len(s) {
			if v, ok := octal(s[i+1 : i+4]); ok {
				b.WriteByte(v)
				i += 3

				continue
			}
		}
		b.WriteByte(s[i])
	}

	return b.String()
}

func octal(s string) (byte, bool) {
	if len(s) != 3 {
		return 0, false
	}
	var v int
	for _, c := range s {
		if c < '0' || c > '7' {
			return 0, false
		}
		v = v*8 + int(c-'0')
	}
	if v > 255 {
		return 0, false
	}

	return byte(v), true
}

// mountStatus is what the boot report says about /server.
func mountStatus() (string, string) {
	f, err := os.Open("/proc/self/mountinfo")
	if err != nil {
		return "unknown", err.Error()
	}
	defer f.Close()

	if !mounted(f, serverDir) {
		return "mount_missing", serverDir + " is not mounted (is /var/lib/pelican/volumes in this node's Wings allowed_mounts?)"
	}

	entries, err := os.ReadDir(serverDir)
	if err != nil {
		return "mount_unreadable", err.Error()
	}

	if len(entries) == 0 {
		return "ok_empty", serverDir + " is mounted but empty"
	}

	return "ok", ""
}
