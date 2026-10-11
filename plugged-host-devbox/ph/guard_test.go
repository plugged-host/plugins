package main

import (
	"testing"
	"time"
)

func TestLimiterBlocksAfterRepeatedFailures(t *testing.T) {
	now := time.Unix(1_000_000, 0)
	l := newLimiter()
	l.now = func() time.Time { return now }

	for i := 0; i < failuresPerIP-1; i++ {
		l.fail("1.2.3.4")
	}
	if l.blocked("1.2.3.4") {
		t.Fatal("blocked before reaching the limit")
	}

	l.fail("1.2.3.4")
	if !l.blocked("1.2.3.4") {
		t.Fatal("not blocked at the limit")
	}
	if l.blocked("5.6.7.8") {
		t.Fatal("another address is blocked too")
	}

	now = now.Add(backoffPerFailed + time.Second)
	if l.blocked("1.2.3.4") {
		t.Fatal("still blocked after the backoff")
	}

	now = now.Add(failureWindow)
	l.fail("1.2.3.4")
	if l.blocked("1.2.3.4") {
		t.Fatal("old failures still count after the window")
	}
}

func TestLimiterSuccessClears(t *testing.T) {
	l := newLimiter()
	for i := 0; i < failuresPerIP; i++ {
		l.fail("1.2.3.4")
	}
	l.succeed("1.2.3.4")
	if l.blocked("1.2.3.4") {
		t.Fatal("still blocked after a successful sign-in")
	}
}

func TestLoopbackOnly(t *testing.T) {
	for host, want := range map[string]bool{
		"127.0.0.1":       true,
		"127.5.5.5":       true,
		"::1":             true,
		"localhost":       true,
		"0.0.0.0":         false,
		"10.0.0.1":        false,
		"169.254.169.254": false,
		"example.com":     false,
		"":                false,
	} {
		if got := loopback(host); got != want {
			t.Errorf("loopback(%q) = %v, want %v", host, got, want)
		}
	}
}
