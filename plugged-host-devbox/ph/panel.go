package main

import (
	"bytes"
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"sync"
	"time"

	gossh "golang.org/x/crypto/ssh"
)

// panelClient talks to the panel's devbox endpoints with the devbox's bearer token. The token
// only ever belongs to this one devbox and the people allowed into it, so the panel re-checks
// access on every call and nothing here is trusted beyond "this request came from that devbox".
type panelClient struct {
	base  string
	token string
	http  *http.Client
}

func newPanelClient(base, token string) *panelClient {
	return &panelClient{
		base:  strings.TrimRight(base, "/"),
		token: token,
		http:  &http.Client{Timeout: 10 * time.Second},
	}
}

func (p *panelClient) post(ctx context.Context, path string, in, out any) error {
	body, err := json.Marshal(in)
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodPost, p.base+"/plugged-host/devbox/"+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+p.token)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "plugged-host-devbox/"+version)

	res, err := p.http.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()

	if res.StatusCode == http.StatusTooManyRequests {
		return errThrottled
	}
	if res.StatusCode < 200 || res.StatusCode > 299 {
		return &statusError{code: res.StatusCode, status: res.Status, path: path}
	}
	if out == nil {
		return nil
	}

	return json.NewDecoder(http.MaxBytesReader(nil, res.Body, 1<<20)).Decode(out)
}

var errThrottled = errors.New("panel throttled the request")

// statusError is a panel answer outside 2xx.
type statusError struct {
	code         int
	status, path string
}

func (e *statusError) Error() string {
	return fmt.Sprintf("panel answered %s on %s", e.status, e.path)
}

// revoked reports whether the panel refused this devbox's token itself (401) or everyone in it
// (403): the devbox was stopped, restarted with a new token, or its people lost access. Nothing
// the panel said before counts any more.
func revoked(err error) bool {
	var se *statusError

	return errors.As(err, &se) && (se.code == http.StatusUnauthorized || se.code == http.StatusForbidden)
}

// access is what the panel says about who may sign in: the people bound to this devbox (one
// for a personal devbox, the owner and permitted subusers for a shared one) and the public keys
// on their accounts. Connect codes are checked by the panel itself.
type access struct {
	Users []struct {
		Username string   `json:"username"`
		Keys     []string `json:"keys"`
	} `json:"users"`
}

// people is the current access list, refreshed at boot and with every heartbeat.
type people struct {
	mu    sync.RWMutex
	users map[string][]gossh.PublicKey
}

func (p *people) set(a access) {
	users := make(map[string][]gossh.PublicKey, len(a.Users))
	for _, u := range a.Users {
		name := strings.TrimSpace(u.Username)
		if name == "" {
			continue
		}
		var keys []gossh.PublicKey
		for _, line := range u.Keys {
			key, _, _, _, err := gossh.ParseAuthorizedKey([]byte(line))
			if err == nil {
				keys = append(keys, key)
			}
		}
		users[name] = keys
	}

	p.mu.Lock()
	p.users = users
	p.mu.Unlock()
}

// anyone reports whether anybody at all may sign in: false before the panel's first answer and
// once the devbox is locked down.
func (p *people) anyone() bool {
	p.mu.RLock()
	defer p.mu.RUnlock()

	return len(p.users) > 0
}

func (p *people) known(username string) bool {
	p.mu.RLock()
	defer p.mu.RUnlock()
	_, ok := p.users[username]

	return ok
}

func (p *people) hasKey(username string, key gossh.PublicKey) bool {
	p.mu.RLock()
	defer p.mu.RUnlock()

	want := key.Marshal()
	for _, k := range p.users[username] {
		if subtle.ConstantTimeCompare(k.Marshal(), want) == 1 {
			return true
		}
	}

	return false
}

// codeCache remembers connect codes the panel accepted, so the handful of connections VS Code
// opens per window don't each cost a panel round trip. Keyed by username + a hash of the code.
type codeCache struct {
	mu      sync.Mutex
	entries map[string]time.Time
	ttl     time.Duration
}

func newCodeCache(ttl time.Duration) *codeCache {
	return &codeCache{entries: map[string]time.Time{}, ttl: ttl}
}

func (c *codeCache) key(username, code string) string {
	sum := sha256.Sum256([]byte(username + "\x00" + code))

	return string(sum[:])
}

func (c *codeCache) ok(username, code string) bool {
	c.mu.Lock()
	defer c.mu.Unlock()

	until, found := c.entries[c.key(username, code)]

	return found && time.Now().Before(until)
}

func (c *codeCache) remember(username, code string) {
	c.mu.Lock()
	defer c.mu.Unlock()

	now := time.Now()
	for k, until := range c.entries {
		if now.After(until) {
			delete(c.entries, k)
		}
	}
	c.entries[c.key(username, code)] = now.Add(c.ttl)
}

func (c *codeCache) forget() {
	c.mu.Lock()
	c.entries = map[string]time.Time{}
	c.mu.Unlock()
}
