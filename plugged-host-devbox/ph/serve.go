package main

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/subtle"
	"encoding/pem"
	"errors"
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"syscall"
	"time"

	"github.com/gliderlabs/ssh"
	gossh "golang.org/x/crypto/ssh"
)

// readyLine is what the egg waits for (config.startup.done) to call the devbox running.
const readyLine = "devbox ready"

type connKey struct{}

type devbox struct {
	panel  *panelClient // nil when testing locally with --static-user
	people *people
	codes  *codeCache
	limit  *limiter

	staticUser, staticCode string

	home      string
	hostKeyFP string

	unauthed  atomic.Int64
	connected atomic.Int64

	web *webSide // nil when the panel has no web version

	connsMu sync.Mutex
	conns   map[*guardedConn]struct{}
}

// serve runs the devbox: an SSH server on the devbox's allocation port that only the people the
// panel names may sign in to (a connect code from the panel, or a key on their account). It never
// exits on its own: Wings would take a clean exit for a crash and start it again, so the panel
// stops an idle devbox through Wings instead.
func serve(args []string) error {
	fs := flag.NewFlagSet("serve", flag.ContinueOnError)
	listen := fs.String("listen", "", "address to listen on (default :$SERVER_PORT)")
	staticUser := fs.String("static-user", "", "local testing only: the one username allowed, no panel")
	staticCode := fs.String("static-code", "", "local testing only: the connect code for --static-user")
	staticKeys := fs.String("static-keys", "", "local testing only: an authorized_keys file for --static-user")
	if err := fs.Parse(args); err != nil {
		return err
	}

	log.SetFlags(0)
	log.SetOutput(os.Stdout)

	home := os.Getenv("HOME")
	if home == "" {
		home = "/home/container"
	}

	// npm, pip and gradle overflow Wings' 100 MB /tmp; TMPDIR points into the home instead.
	if tmp := os.Getenv("TMPDIR"); tmp != "" {
		_ = os.MkdirAll(tmp, 0o700)
	}

	addr := *listen
	if addr == "" {
		port := os.Getenv("SERVER_PORT")
		if port == "" {
			return errors.New("SERVER_PORT is not set (or pass --listen)")
		}
		addr = ":" + port
	}

	d := &devbox{
		conns:  map[*guardedConn]struct{}{},
		people: &people{},
		codes:  newCodeCache(15 * time.Minute),
		limit:  newLimiter(),
		home:   home,
	}

	if *staticUser != "" {
		d.staticUser, d.staticCode = *staticUser, *staticCode
		a := access{}
		a.Users = append(a.Users, struct {
			Username string   `json:"username"`
			Keys     []string `json:"keys"`
		}{Username: *staticUser, Keys: readKeys(*staticKeys)})
		d.people.set(a)
	} else {
		panelURL, token := os.Getenv("DEVBOX_PANEL_URL"), os.Getenv("DEVBOX_TOKEN")
		if panelURL == "" || token == "" {
			return errors.New("DEVBOX_PANEL_URL and DEVBOX_TOKEN are required")
		}
		d.panel = newPanelClient(panelURL, token)
		if err := writeAgentFile(home, panelURL, token); err != nil {
			log.Printf("warning: could not write %s: %v", agentPath(home), err)
		}
	}
	seedHome(home)

	signer, err := hostKey(filepath.Join(home, ".ph", "ssh_host_ed25519_key"))
	if err != nil {
		return fmt.Errorf("host key: %w", err)
	}
	d.hostKeyFP = gossh.FingerprintSHA256(signer.PublicKey())

	srv := &ssh.Server{
		Addr:                        addr,
		Version:                     "pluggedhost-devbox",
		Handler:                     d.session,
		PasswordHandler:             d.password,
		PublicKeyHandler:            d.publicKey,
		LocalPortForwardingCallback: d.forwardOK,
		ConnCallback:                d.accept,
		ServerConfigCallback: func(ssh.Context) *gossh.ServerConfig {
			return &gossh.ServerConfig{MaxAuthTries: maxAuthTries}
		},
		ChannelHandlers: map[string]ssh.ChannelHandler{
			"session": func(srv *ssh.Server, conn *gossh.ServerConn, ch gossh.NewChannel, ctx ssh.Context) {
				d.settle(ctx)
				ssh.DefaultSessionHandler(srv, conn, ch, ctx)
			},
			"direct-tcpip": func(srv *ssh.Server, conn *gossh.ServerConn, ch gossh.NewChannel, ctx ssh.Context) {
				d.settle(ctx)
				ssh.DirectTCPIPHandler(srv, conn, ch, ctx)
			},
		},
		SubsystemHandlers: map[string]ssh.SubsystemHandler{"sftp": d.sftp},
	}
	srv.AddHostKey(signer)

	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return err
	}

	web, err := newWebSide(d)
	if err != nil {
		log.Printf("warning: VS Code in the browser is off: %v", err)
		web = nil
	}
	d.web = web
	split := newSplitListener(ln, web != nil)
	if web != nil {
		go func() {
			if err := web.serve(split.web); err != nil {
				log.Printf("web: %v", err)
			}
		}()
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	mount, detail := mountStatus()
	log.Printf("%s on %s, ph %s, host key %s", readyLine, addr, version, d.hostKeyFP)
	if mount != "ok" {
		log.Printf("warning: %s: %s", mount, detail)
	}
	go d.report(ctx, mount, detail)

	sigs := make(chan os.Signal, 1)
	signal.Notify(sigs, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		s := <-sigs
		log.Printf("stopping (%s)", s)
		cancel()
		sctx, scancel := context.WithTimeout(context.Background(), 3*time.Second)
		defer scancel()
		if d.web != nil {
			d.web.stop()
		}
		_ = srv.Shutdown(sctx)
		_ = srv.Close()
	}()

	if err := srv.Serve(split.ssh); err != nil && !errors.Is(err, ssh.ErrServerClosed) && !errors.Is(err, net.ErrClosed) {
		return err
	}

	return nil
}

// connections is what keeps the devbox from being stopped as idle: SSH sessions (VS Code,
// terminals) and open browser connections (VS Code in a tab holds a websocket).
func (d *devbox) connections() int64 {
	n := d.connected.Load()
	if d.web != nil {
		n += d.web.active.Load()
	}

	return n
}

// accept caps connections that are still signing in and gives each a sign-in deadline.
func (d *devbox) accept(ctx ssh.Context, conn net.Conn) net.Conn {
	ip := remoteIP(conn.RemoteAddr())
	if d.unauthed.Add(1) > maxUnauthed || d.limit.blocked(ip) {
		d.unauthed.Add(-1)

		return nil // gliderlabs closes it
	}

	_ = conn.SetDeadline(time.Now().Add(loginGrace))
	gc := &guardedConn{Conn: conn, unauthed: &d.unauthed, connected: &d.connected, onClose: d.forgetConn}
	ctx.SetValue(connKey{}, gc)

	d.connsMu.Lock()
	d.conns[gc] = struct{}{}
	d.connsMu.Unlock()

	return gc
}

func (d *devbox) forgetConn(gc *guardedConn) {
	d.connsMu.Lock()
	delete(d.conns, gc)
	d.connsMu.Unlock()
}

// settle marks a connection signed in once it opens its first channel (after authentication).
func (d *devbox) settle(ctx ssh.Context) {
	if gc, ok := ctx.Value(connKey{}).(*guardedConn); ok {
		gc.signedIn(ctx.User())
	}
}

// dropRevoked closes the connections of people who are no longer on the access list: someone
// removed from a shared devbox's server loses their open sessions, not only new sign-ins.
func (d *devbox) dropRevoked() {
	d.connsMu.Lock()
	var gone []*guardedConn
	for gc := range d.conns {
		if name := gc.username(); name != "" && !d.people.known(name) {
			gone = append(gone, gc)
		}
	}
	d.connsMu.Unlock()

	for _, gc := range gone {
		log.Printf("closing %s's connection: no longer allowed in", gc.username())
		_ = gc.Close()
	}
}

func (d *devbox) password(ctx ssh.Context, code string) bool {
	ip := remoteIP(ctx.RemoteAddr())
	user := ctx.User()

	if d.limit.blocked(ip) {
		return false
	}

	// Usernames nobody is bound to (bots trying root, admin, ubuntu…) never reach the panel.
	if !d.people.known(user) || code == "" || len(code) > 256 {
		d.limit.fail(ip)

		return false
	}

	ok := false
	switch {
	case d.staticUser != "":
		ok = d.staticCode != "" && subtle.ConstantTimeCompare([]byte(code), []byte(d.staticCode)) == 1
	case d.codes.ok(user, code):
		ok = true
	default:
		ok = d.checkCode(ctx, user, code, ip)
	}

	if !ok {
		d.limit.fail(ip)
		log.Printf("refused %s from %s (wrong connect code)", user, ip)

		return false
	}

	d.limit.succeed(ip)
	d.codes.remember(user, code)
	log.Printf("%s signed in from %s (connect code)", user, ip)

	return true
}

func (d *devbox) checkCode(ctx context.Context, user, code, ip string) bool {
	var answer struct {
		OK bool `json:"ok"`
	}

	cctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	if err := d.panel.post(cctx, "auth", map[string]string{"username": user, "code": code, "ip": ip}, &answer); err != nil {
		log.Printf("could not check a connect code for %s: %v", user, err)

		return false
	}

	return answer.OK
}

// publicKey accepts keys on the bound person's account. Clients offer several keys per
// connection, so a key that doesn't match is not counted as a failure; an unknown name is.
func (d *devbox) publicKey(ctx ssh.Context, key ssh.PublicKey) bool {
	ip := remoteIP(ctx.RemoteAddr())
	if d.limit.blocked(ip) {
		return false
	}

	if !d.people.known(ctx.User()) {
		d.limit.fail(ip)

		return false
	}

	return d.people.hasKey(ctx.User(), key)
}

func (d *devbox) forwardOK(ctx ssh.Context, host string, port uint32) bool {
	if loopback(host) {
		return true
	}
	log.Printf("refused forwarding to %s:%d for %s", host, port, ctx.User())

	return false
}

// report tells the panel the devbox is up (and whether /server is really mounted), then sends a
// heartbeat every minute. Both answers carry who may sign in; until the first one arrives nobody
// can, so a devbox that can't reach the panel fails closed.
func (d *devbox) report(ctx context.Context, mount, detail string) {
	if d.panel == nil {
		return
	}

	for attempt := 0; ; attempt++ {
		var a access
		err := d.panel.post(ctx, "boot", map[string]string{
			"version":  version,
			"mount":    mount,
			"detail":   detail,
			"host_key": d.hostKeyFP,
		}, &a)
		if err == nil {
			d.people.set(a)

			break
		}
		log.Printf("boot report failed: %v", err)

		wait := time.Duration(1<<min(attempt, 5)) * time.Second
		select {
		case <-ctx.Done():
			return
		case <-time.After(wait):
		}
	}

	ticker := time.NewTicker(time.Minute)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}

		var a access
		err := d.panel.post(ctx, "heartbeat", map[string]int64{"connections": d.connections()}, &a)
		if err != nil {
			log.Printf("heartbeat failed: %v", err)

			continue
		}
		d.people.set(a)
		d.dropRevoked()
	}
}

// hostKey loads the devbox's host key from its home, or makes one on first boot. It lives as
// long as the devbox, so VS Code's known_hosts stays happy across restarts.
func hostKey(path string) (gossh.Signer, error) {
	if data, err := os.ReadFile(path); err == nil {
		return gossh.ParsePrivateKey(data)
	}

	_, priv, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		return nil, err
	}
	block, err := gossh.MarshalPrivateKey(priv, "plugged.host devbox")
	if err != nil {
		return nil, err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return nil, err
	}
	if err := os.WriteFile(path, pem.EncodeToMemory(block), 0o600); err != nil {
		return nil, err
	}

	return gossh.NewSignerFromKey(priv)
}

func readKeys(path string) []string {
	if path == "" {
		return nil
	}
	data, err := os.ReadFile(path)
	if err != nil {
		return nil
	}
	var keys []string
	for _, line := range strings.Split(string(data), "\n") {
		if line = strings.TrimSpace(line); line != "" && !strings.HasPrefix(line, "#") {
			keys = append(keys, line)
		}
	}

	return keys
}
