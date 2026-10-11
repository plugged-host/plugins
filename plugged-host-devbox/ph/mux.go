package main

import (
	"bufio"
	"errors"
	"net"
	"sync"
	"sync/atomic"
	"time"
)

// splitListener shares the devbox's one port between SSH (VS Code Remote-SSH, terminals) and the
// web side (VS Code in the browser, reached only through the panel's edge): a TLS connection opens
// with a handshake record (0x16), an SSH client with its banner. Clients that wait for the server
// to speak first send nothing, so silence also means SSH.
type splitListener struct {
	base     net.Listener
	ssh, web *chanListener
	webOn    bool
}

func newSplitListener(base net.Listener, webOn bool) *splitListener {
	s := &splitListener{
		base:  base,
		ssh:   newChanListener(base.Addr()),
		web:   newChanListener(base.Addr()),
		webOn: webOn,
	}
	go s.loop()

	return s
}

func (s *splitListener) loop() {
	for {
		conn, err := s.base.Accept()
		if err != nil {
			s.ssh.close()
			s.web.close()

			return
		}
		go s.route(conn)
	}
}

func (s *splitListener) route(conn net.Conn) {
	_ = conn.SetReadDeadline(time.Now().Add(1500 * time.Millisecond))
	reader := bufio.NewReaderSize(conn, 64)
	first, err := reader.Peek(1)
	_ = conn.SetReadDeadline(time.Time{})

	var timeout net.Error
	switch {
	case err == nil && first[0] == 0x16:
		if !s.webOn {
			_ = conn.Close()

			return
		}
		s.web.push(&peekedConn{Conn: conn, reader: reader})
	case err == nil, errors.As(err, &timeout) && timeout.Timeout():
		s.ssh.push(&peekedConn{Conn: conn, reader: reader})
	default:
		_ = conn.Close()
	}
}

// peekedConn replays the bytes looked at while routing.
type peekedConn struct {
	net.Conn
	reader *bufio.Reader
}

func (c *peekedConn) Read(p []byte) (int, error) {
	return c.reader.Read(p)
}

// chanListener is a net.Listener fed by the splitter.
type chanListener struct {
	addr  net.Addr
	conns chan net.Conn
	done  chan struct{}
	once  sync.Once
}

func newChanListener(addr net.Addr) *chanListener {
	return &chanListener{addr: addr, conns: make(chan net.Conn, 16), done: make(chan struct{})}
}

func (l *chanListener) push(conn net.Conn) {
	select {
	case l.conns <- conn:
	case <-l.done:
		_ = conn.Close()
	}
}

func (l *chanListener) Accept() (net.Conn, error) {
	select {
	case conn := <-l.conns:
		return conn, nil
	case <-l.done:
		return nil, net.ErrClosed
	}
}

func (l *chanListener) close() {
	l.once.Do(func() { close(l.done) })
}

func (l *chanListener) Close() error {
	l.close()

	return nil
}

func (l *chanListener) Addr() net.Addr {
	return l.addr
}

// countedConn keeps a count of open web connections (VS Code in the browser holds a websocket
// while the tab is open), which the heartbeat reports so the panel doesn't stop a busy devbox.
type countedConn struct {
	net.Conn
	count  *atomic.Int64
	closed atomic.Bool
}

func (c *countedConn) Close() error {
	if c.closed.CompareAndSwap(false, true) {
		c.count.Add(-1)
	}

	return c.Conn.Close()
}

type countingListener struct {
	net.Listener
	count *atomic.Int64
}

func (l countingListener) Accept() (net.Conn, error) {
	conn, err := l.Listener.Accept()
	if err != nil {
		return nil, err
	}
	l.count.Add(1)

	return &countedConn{Conn: conn, count: l.count}, nil
}
