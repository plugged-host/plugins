package main

import (
	"io"
	"net"
	"testing"
	"time"
)

func dialAndSend(t *testing.T, addr string, first []byte) net.Conn {
	t.Helper()
	c, err := net.Dial("tcp", addr)
	if err != nil {
		t.Fatal(err)
	}
	if first != nil {
		if _, err := c.Write(first); err != nil {
			t.Fatal(err)
		}
	}

	return c
}

func acceptWithin(t *testing.T, l net.Listener, d time.Duration) net.Conn {
	t.Helper()
	got := make(chan net.Conn, 1)
	go func() {
		c, err := l.Accept()
		if err == nil {
			got <- c
		}
	}()
	select {
	case c := <-got:
		return c
	case <-time.After(d):
		return nil
	}
}

func TestSplitListenerRoutes(t *testing.T) {
	base, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	s := newSplitListener(base, true)
	defer base.Close()

	// An SSH client speaks first with its banner: SSH side, bytes replayed intact.
	c1 := dialAndSend(t, base.Addr().String(), []byte("SSH-2.0-test\r\n"))
	defer c1.Close()
	got := acceptWithin(t, s.ssh, 3*time.Second)
	if got == nil {
		t.Fatal("SSH banner not routed to SSH")
	}
	buf := make([]byte, 14)
	if _, err := io.ReadFull(got, buf); err != nil || string(buf) != "SSH-2.0-test\r\n" {
		t.Fatalf("replayed %q, %v", buf, err)
	}

	// A TLS ClientHello starts with 0x16: web side.
	c2 := dialAndSend(t, base.Addr().String(), []byte{0x16, 0x03, 0x01})
	defer c2.Close()
	if acceptWithin(t, s.web, 3*time.Second) == nil {
		t.Fatal("TLS not routed to web")
	}

	// A client that waits for the server to speak: SSH after the peek times out.
	c3 := dialAndSend(t, base.Addr().String(), nil)
	defer c3.Close()
	if acceptWithin(t, s.ssh, 4*time.Second) == nil {
		t.Fatal("silent client not routed to SSH")
	}
}

func TestSplitListenerWebOff(t *testing.T) {
	base, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	s := newSplitListener(base, false)
	defer base.Close()

	c := dialAndSend(t, base.Addr().String(), []byte{0x16, 0x03, 0x01})
	defer c.Close()
	if acceptWithin(t, s.web, time.Second) != nil {
		t.Fatal("TLS accepted with the web side off")
	}
	_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
	if _, err := c.Read(make([]byte, 1)); err == nil {
		t.Fatal("connection not closed")
	}
}
