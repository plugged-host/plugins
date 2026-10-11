package main

import (
	"os"
	"path/filepath"
)

// claudeNotes tells AI coding tools (Claude Code reads ~/.claude/CLAUDE.md in every session)
// where they are and how to look after the game server. Written once; never over someone's own.
const claudeNotes = `# plugged.host devbox

You are in a plugged.host devbox: a Linux box next to a game server, not the game server itself.

- The game server's files are in /server. They are live: editing them changes the real server.
- The game runs in its own container, so it can't be started from here. Use ` + "`ph`" + `:
  - ` + "`ph status`" + `: running or not, CPU, memory, disk
  - ` + "`ph logs -n 200`" + `: recent console output (` + "`ph logs -f`" + ` to follow)
  - ` + "`ph cmd \"<console command>\"`" + `: run a console command and see the answer
  - ` + "`ph restart`" + ` / ` + "`ph start`" + ` / ` + "`ph stop`" + `
- After changing configs, plugins or scripts: ` + "`ph restart`" + `, then ` + "`ph logs -f`" + ` to check it
  came up cleanly. What ph may do follows the person's permissions on the server.
- No root or sudo. Install tools per user: ` + "`pip install --user`" + `, ` + "`npm i -g`" + ` (goes to ~/.local).
- /tmp is small (100 MB); $TMPDIR points into the home folder.
`

// seedHome writes the defaults a fresh devbox home starts with.
func seedHome(home string) {
	dir := filepath.Join(home, ".claude")
	path := filepath.Join(dir, "CLAUDE.md")
	if _, err := os.Stat(path); err == nil {
		return
	}
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return
	}
	_ = os.WriteFile(path, []byte(claudeNotes), 0o600)
}
