package main

import (
	"bufio"
	"context"
	"errors"
	"flag"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// The game server's console comes straight from Wings over its websocket, with a token the panel
// signs for the devbox's person (or, for a shared devbox, the rights the owner chose). Start and
// restart are never in it: those go through the panel (`ph start`), like the Start button.

type socketInfo struct {
	Token       string   `json:"token"`
	Socket      string   `json:"socket"`
	Origin      string   `json:"origin"`
	Permissions []string `json:"permissions"`
}

type wingsEvent struct {
	Event string `json:"event"`
	Args  []any  `json:"args,omitempty"`
}

type console struct {
	conn  *websocket.Conn
	info  socketInfo
	write sync.Mutex
}

func openConsole(ctx context.Context) (*console, error) {
	var info socketInfo
	if err := call("game/websocket", map[string]any{}, &info); err != nil {
		return nil, err
	}

	dialer := websocket.Dialer{HandshakeTimeout: 15 * time.Second, Proxy: http.ProxyFromEnvironment}
	conn, _, err := dialer.DialContext(ctx, info.Socket, http.Header{"Origin": {info.Origin}})
	if err != nil {
		return nil, fmt.Errorf("could not reach the server's console: %w", err)
	}

	c := &console{conn: conn, info: info}
	if err := c.send("auth", info.Token); err != nil {
		conn.Close()

		return nil, err
	}

	return c, nil
}

func (c *console) send(event string, args ...any) error {
	c.write.Lock()
	defer c.write.Unlock()

	return c.conn.WriteJSON(wingsEvent{Event: event, Args: args})
}

func (c *console) can(permission string) bool {
	for _, p := range c.info.Permissions {
		if p == permission {
			return true
		}
	}

	return false
}

// refresh gets a new token from the panel before Wings' 10 minutes run out.
func (c *console) refresh() {
	var info socketInfo
	if err := call("game/websocket", map[string]any{}, &info); err != nil {
		fmt.Fprintln(os.Stderr, "ph: could not renew the console token:", err)

		return
	}
	c.info.Token = info.Token
	_ = c.send("auth", info.Token)
}

// events reads Wings' messages until the connection ends, handing console lines to onLine and
// answering auth and token renewal itself. onReady runs once after a successful auth.
func (c *console) events(onReady func(), onLine func(string)) error {
	ready := false
	for {
		var e wingsEvent
		if err := c.conn.ReadJSON(&e); err != nil {
			return err
		}
		switch e.Event {
		case "auth success":
			if !ready {
				ready = true
				onReady()
			}
		case "console output", "install output":
			if len(e.Args) > 0 {
				if s, ok := e.Args[0].(string); ok {
					onLine(s)
				}
			}
		case "token expiring", "token expired":
			go c.refresh()
		case "jwt error", "daemon error":
			msg := e.Event
			if len(e.Args) > 0 {
				msg = fmt.Sprintf("%s: %v", e.Event, e.Args[0])
			}

			return errors.New(msg)
		}
	}
}

func runLogs(args []string) error {
	fs := flag.NewFlagSet("logs", flag.ContinueOnError)
	lines := fs.Int("n", 100, "how many recent lines")
	follow := fs.Bool("f", false, "keep printing new lines")
	if err := fs.Parse(args); err != nil {
		return err
	}

	c, err := openConsole(context.Background())
	if err != nil {
		return err
	}
	defer c.conn.Close()

	var (
		mu       sync.Mutex
		history  []string
		lastLine time.Time
		replayed bool
	)
	ready := make(chan struct{})
	done := make(chan error, 1)
	go func() {
		done <- c.events(func() {
			_ = c.send("send logs", nil)
			close(ready)
		}, func(line string) {
			mu.Lock()
			defer mu.Unlock()
			if replayed {
				fmt.Println(line)

				return
			}
			history = append(history, line)
			lastLine = time.Now()
		})
	}()

	select {
	case <-ready:
	case err := <-done:
		return err
	case <-time.After(15 * time.Second):
		return errors.New("the server's console did not answer")
	}

	// Wings replays recent history right after "send logs"; it is over when the lines stop.
	asked := time.Now()
	ticker := time.NewTicker(200 * time.Millisecond)
	defer ticker.Stop()
	for waiting := true; waiting; {
		select {
		case err := <-done:
			return err
		case <-ticker.C:
		}
		mu.Lock()
		got := len(history)
		quiet := time.Since(lastLine)
		mu.Unlock()
		waiting = !((got > 0 && quiet > 800*time.Millisecond) || (got == 0 && time.Since(asked) > 3*time.Second))
	}

	mu.Lock()
	from := max(0, len(history)-*lines)
	for _, line := range history[from:] {
		fmt.Println(line)
	}
	replayed = true
	mu.Unlock()

	if !*follow {
		return nil
	}

	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)
	select {
	case err := <-done:
		return err
	case <-interrupt:
		return nil
	}
}

func runCmd(args []string) error {
	command := joinArgs(args)
	if command == "" {
		return errors.New(`usage: ph cmd "<console command>"`)
	}

	c, err := openConsole(context.Background())
	if err != nil {
		return err
	}
	defer c.conn.Close()

	if !c.can("control.console") {
		return errors.New("you may read this server's console but not send commands to it")
	}

	done := make(chan error, 1)
	go func() {
		done <- c.events(func() {
			_ = c.send("send command", command)
		}, func(line string) {
			fmt.Println(line)
		})
	}()

	// Show what the server answers for a few seconds.
	select {
	case err := <-done:
		return err
	case <-time.After(3 * time.Second):
		return nil
	}
}

func runConsole(_ []string) error {
	c, err := openConsole(context.Background())
	if err != nil {
		return err
	}
	defer c.conn.Close()

	done := make(chan error, 1)
	go func() {
		done <- c.events(func() {
			_ = c.send("send logs", nil)
		}, func(line string) {
			fmt.Println(line)
		})
	}()

	if c.can("control.console") {
		fmt.Fprintln(os.Stderr, "ph: console attached. Type a command and press Enter; Ctrl-C leaves (the server keeps running).")
		go func() {
			scanner := bufio.NewScanner(os.Stdin)
			for scanner.Scan() {
				if line := strings.TrimSpace(scanner.Text()); line != "" {
					_ = c.send("send command", line)
				}
			}
		}()
	} else {
		fmt.Fprintln(os.Stderr, "ph: console attached read-only. Ctrl-C leaves.")
	}

	interrupt := make(chan os.Signal, 1)
	signal.Notify(interrupt, os.Interrupt)
	select {
	case err := <-done:
		return err
	case <-interrupt:
		return nil
	}
}
