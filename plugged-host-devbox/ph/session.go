package main

import (
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"os/user"
	"strings"
	"syscall"
	"time"

	"github.com/creack/pty"
	"github.com/gliderlabs/ssh"
	"github.com/pkg/sftp"
)

// session runs a shell (interactive, with a terminal) or a command (what VS Code's Remote-SSH
// uses to install and start its server). Always bash: Wings' /etc/passwd gives the container user
// /usr/sbin/nologin as its shell.
func (d *devbox) session(s ssh.Session) {
	var cmd *exec.Cmd
	if raw := s.RawCommand(); raw != "" {
		cmd = exec.Command("/bin/bash", "-c", raw)
	} else {
		cmd = exec.Command("/bin/bash", "-l")
	}
	cmd.Dir = d.workdir()
	cmd.Env = d.sessionEnv(s.User(), s.Environ())

	if ptyReq, winCh, isPty := s.Pty(); isPty {
		d.runPty(s, cmd, ptyReq, winCh)

		return
	}

	d.runPlain(s, cmd)
}

func (d *devbox) runPty(s ssh.Session, cmd *exec.Cmd, req ssh.Pty, winCh <-chan ssh.Window) {
	cmd.Env = append(cmd.Env, "TERM="+req.Term)

	f, err := pty.StartWithSize(cmd, &pty.Winsize{Cols: uint16(req.Window.Width), Rows: uint16(req.Window.Height)})
	if err != nil {
		fmt.Fprintln(s.Stderr(), "could not start a shell:", err)
		_ = s.Exit(1)

		return
	}
	defer f.Close()

	go func() {
		for w := range winCh {
			_ = pty.Setsize(f, &pty.Winsize{Cols: uint16(w.Width), Rows: uint16(w.Height)})
		}
	}()
	go func() { _, _ = io.Copy(f, s) }()

	// Like sshd: a terminal session that goes away hangs up its shell's process group.
	done := make(chan struct{})
	go func() {
		select {
		case <-s.Context().Done():
			_ = syscall.Kill(-cmd.Process.Pid, syscall.SIGHUP)
		case <-done:
		}
	}()

	_, _ = io.Copy(s, f)
	err = cmd.Wait()
	close(done)
	_ = s.Exit(exitStatus(err, cmd.ProcessState))
}

func (d *devbox) runPlain(s ssh.Session, cmd *exec.Cmd) {
	stdin, err := cmd.StdinPipe()
	if err != nil {
		_ = s.Exit(1)

		return
	}
	cmd.Stdout = s
	cmd.Stderr = s.Stderr()
	// VS Code's install script leaves its server running in the background; if that server held
	// on to our stdout, Wait would never return. Give the output 2 s after the command exits.
	cmd.WaitDelay = 2 * time.Second

	if err := cmd.Start(); err != nil {
		fmt.Fprintln(s.Stderr(), err)
		_ = s.Exit(127)

		return
	}

	go func() {
		_, _ = io.Copy(stdin, s)
		_ = stdin.Close()
	}()

	err = cmd.Wait()
	_ = s.Exit(exitStatus(err, cmd.ProcessState))
}

func (d *devbox) sftp(s ssh.Session) {
	server, err := sftp.NewServer(s, sftp.WithServerWorkingDirectory(d.workdir()))
	if err != nil {
		log.Printf("sftp: %v", err)

		return
	}
	if err := server.Serve(); err != nil && !errors.Is(err, io.EOF) {
		log.Printf("sftp: %v", err)
	}
	_ = server.Close()
}

// workdir is the server's files when they are mounted, else the devbox's home.
func (d *devbox) workdir() string {
	if info, err := os.Stat(serverDir); err == nil && info.IsDir() {
		return serverDir
	}

	return d.home
}

// sessionEnv is the devbox's environment without its secrets, the identity bash expects, and
// what the client sent (LANG, TERM, VS Code's variables…).
func (d *devbox) sessionEnv(person string, client []string) []string {
	name := "container"
	if u, err := user.Current(); err == nil && u.Username != "" {
		name = u.Username
	}

	env := map[string]string{}
	var order []string
	set := func(k, v string) {
		if _, seen := env[k]; !seen {
			order = append(order, k)
		}
		env[k] = v
	}

	for _, kv := range os.Environ() {
		if k, v, ok := strings.Cut(kv, "="); ok && !hiddenEnv(k) {
			set(k, v)
		}
	}
	set("HOME", d.home)
	set("USER", name)
	set("LOGNAME", name)
	set("SHELL", "/bin/bash")
	set("PH_DEVBOX", "1")
	set("PH_USER", person)

	for _, kv := range client {
		if k, v, ok := strings.Cut(kv, "="); ok && !hiddenEnv(k) && !fixedEnv(k) {
			set(k, v)
		}
	}

	out := make([]string, 0, len(order))
	for _, k := range order {
		out = append(out, k+"="+env[k])
	}

	return out
}

// hiddenEnv keeps the devbox's own secrets (DEVBOX_TOKEN…) and Wings' internals out of sessions.
func hiddenEnv(k string) bool {
	return strings.HasPrefix(k, "DEVBOX_") || k == "STARTUP"
}

func fixedEnv(k string) bool {
	switch k {
	case "HOME", "USER", "LOGNAME", "SHELL", "PH_DEVBOX", "PH_USER":
		return true
	}

	return false
}

func exitStatus(err error, state *os.ProcessState) int {
	if state != nil {
		if ws, ok := state.Sys().(syscall.WaitStatus); ok {
			if ws.Signaled() {
				return 128 + int(ws.Signal())
			}

			return ws.ExitStatus()
		}

		return state.ExitCode()
	}
	if err != nil {
		return 1
	}

	return 0
}
