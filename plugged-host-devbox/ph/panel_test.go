package main

import (
	"crypto/ed25519"
	"crypto/rand"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	gossh "golang.org/x/crypto/ssh"
)

func newKey(t *testing.T) (gossh.PublicKey, string) {
	t.Helper()
	pub, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	key, err := gossh.NewPublicKey(pub)
	if err != nil {
		t.Fatal(err)
	}

	return key, string(gossh.MarshalAuthorizedKey(key))
}

func TestPeople(t *testing.T) {
	alice, aliceLine := newKey(t)
	other, _ := newKey(t)

	var a access
	a.Users = append(a.Users, struct {
		Username string   `json:"username"`
		Keys     []string `json:"keys"`
	}{Username: "alice", Keys: []string{aliceLine, "not a key"}})

	p := &people{}
	p.set(a)

	if !p.known("alice") || p.known("root") || p.known("") {
		t.Fatal("known() wrong")
	}
	if !p.hasKey("alice", alice) {
		t.Fatal("alice's key refused")
	}
	if p.hasKey("alice", other) || p.hasKey("root", alice) {
		t.Fatal("a key that isn't alice's was accepted")
	}

	if !p.anyone() {
		t.Fatal("anyone() false with alice on the list")
	}

	p.set(access{})
	if p.known("alice") {
		t.Fatal("alice still known after she was removed")
	}
	if p.anyone() {
		t.Fatal("anyone() true with nobody on the list")
	}
}

func TestRevoked(t *testing.T) {
	for code, want := range map[int]bool{
		http.StatusUnauthorized:        true,
		http.StatusForbidden:           true,
		http.StatusInternalServerError: false,
		http.StatusBadGateway:          false,
		http.StatusNotFound:            false,
	} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(code)
		}))
		err := newPanelClient(srv.URL, "token").post(t.Context(), "heartbeat", map[string]int{}, nil)
		srv.Close()

		if err == nil {
			t.Fatalf("%d: no error", code)
		}
		if got := revoked(err); got != want {
			t.Fatalf("%d: revoked() = %v, want %v (%v)", code, got, want, err)
		}
		if got := revoked(fmt.Errorf("wrapped: %w", err)); got != want {
			t.Fatalf("%d: wrapped: revoked() = %v, want %v", code, got, want)
		}
	}

	if revoked(errThrottled) || revoked(errors.New("connection refused")) || revoked(nil) {
		t.Fatal("revoked() true for an error that isn't a 401/403")
	}
}

func TestCodeCache(t *testing.T) {
	c := newCodeCache(time.Minute)
	if c.ok("alice", "code") {
		t.Fatal("empty cache accepted a code")
	}
	c.remember("alice", "code")
	if !c.ok("alice", "code") || c.ok("bob", "code") || c.ok("alice", "other") {
		t.Fatal("cache matched the wrong entry")
	}
	c.forget()
	if c.ok("alice", "code") {
		t.Fatal("forget kept an entry")
	}
}
