package main

import (
	"net"
	"sync"
	"sync/atomic"
	"time"
)

// Login hygiene for a port anyone on the internet can reach: bots scan every open port and try
// passwords, and each wrong guess would otherwise cost a panel request.
const (
	loginGrace       = 20 * time.Second // a connection must finish signing in within this
	maxUnauthed      = 8                // connections still signing in at once
	maxAuthTries     = 3                // per connection
	failuresPerIP    = 5                // wrong answers before an address is told to wait
	failureWindow    = 10 * time.Minute // how long a failure counts
	backoffPerFailed = 30 * time.Second // wait per failure over the limit, capped below
	maxBackoff       = 15 * time.Minute
)

// limiter counts wrong answers per remote address and makes repeat offenders wait.
type limiter struct {
	mu   sync.Mutex
	hits map[string][]time.Time
	now  func() time.Time
}

func newLimiter() *limiter {
	return &limiter{hits: map[string][]time.Time{}, now: time.Now}
}

func (l *limiter) prune(ip string, now time.Time) []time.Time {
	kept := l.hits[ip][:0]
	for _, t := range l.hits[ip] {
		if now.Sub(t) < failureWindow {
			kept = append(kept, t)
		}
	}
	if len(kept) == 0 {
		delete(l.hits, ip)

		return nil
	}
	l.hits[ip] = kept

	return kept
}

// blocked reports whether ip has to wait before it may try again.
func (l *limiter) blocked(ip string) bool {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := l.now()
	hits := l.prune(ip, now)
	if len(hits) < failuresPerIP {
		return false
	}

	wait := time.Duration(len(hits)-failuresPerIP+1) * backoffPerFailed
	if wait > maxBackoff {
		wait = maxBackoff
	}

	return now.Sub(hits[len(hits)-1]) < wait
}

func (l *limiter) fail(ip string) {
	l.mu.Lock()
	defer l.mu.Unlock()

	now := l.now()
	l.prune(ip, now)
	l.hits[ip] = append(l.hits[ip], now)
}

func (l *limiter) succeed(ip string) {
	l.mu.Lock()
	delete(l.hits, ip)
	l.mu.Unlock()
}

// guardedConn enforces the sign-in deadline and keeps the two connection counts: still signing
// in (capped) and signed in (reported to the panel, which stops the devbox when it stays 0).
type guardedConn struct {
	net.Conn
	unauthed, connected *atomic.Int64
	state               atomic.Int32 // 0 signing in, 1 signed in, 2 closed
	user                atomic.Value // string: who signed in
	onClose             func(*guardedConn)
}

// signedIn lifts the sign-in deadline and moves the connection to the signed-in count.
func (c *guardedConn) signedIn(user string) {
	if c.state.CompareAndSwap(0, 1) {
		c.user.Store(user)
		c.unauthed.Add(-1)
		c.connected.Add(1)
		_ = c.Conn.SetDeadline(time.Time{})
	}
}

func (c *guardedConn) Close() error {
	switch c.state.Swap(2) {
	case 0:
		c.unauthed.Add(-1)
	case 1:
		c.connected.Add(-1)
	}
	if c.onClose != nil {
		c.onClose(c)
	}

	return c.Conn.Close()
}

func (c *guardedConn) username() string {
	name, _ := c.user.Load().(string)

	return name
}

func remoteIP(addr net.Addr) string {
	if tcp, ok := addr.(*net.TCPAddr); ok {
		return tcp.IP.String()
	}
	host, _, err := net.SplitHostPort(addr.String())
	if err != nil {
		return addr.String()
	}

	return host
}

// loopback reports whether a forwarding destination stays inside the devbox. VS Code forwards
// to its own server on 127.0.0.1; anything else would turn the devbox into a proxy.
func loopback(host string) bool {
	if host == "localhost" {
		return true
	}
	ip := net.ParseIP(host)

	return ip != nil && ip.IsLoopback()
}
