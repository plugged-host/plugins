package main

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/base64"
	"errors"
	"fmt"
	"log"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const codeServerBin = "/usr/local/bin/code-server"

// webSide is VS Code in the browser: code-server on loopback, started on the first request, behind
// a TLS server that only the panel's edge can talk to (its client certificate is signed by the
// panel's devbox CA). The edge has already checked who the browser belongs to; nothing reaches
// code-server any other way, which is why it runs with --auth none.
type webSide struct {
	d      *devbox
	server *http.Server
	active atomic.Int64

	mu    sync.Mutex
	cmd   *exec.Cmd
	port  int
	proxy *httputil.ReverseProxy
	exit  chan struct{}
}

// newWebSide reads the devbox's certificate, key and the edge CA from the environment (base64 PEM,
// issued by the panel on every start). Missing means the panel has no web version: nil, no error.
func newWebSide(d *devbox) (*webSide, error) {
	certPEM, keyPEM, caPEM := envPEM("DEVBOX_TLS_CERT"), envPEM("DEVBOX_TLS_KEY"), envPEM("DEVBOX_EDGE_CA")
	if certPEM == nil || keyPEM == nil || caPEM == nil {
		return nil, nil
	}

	cert, err := tls.X509KeyPair(certPEM, keyPEM)
	if err != nil {
		return nil, fmt.Errorf("devbox certificate: %w", err)
	}
	pool := x509.NewCertPool()
	if !pool.AppendCertsFromPEM(caPEM) {
		return nil, errors.New("edge CA: no certificate in DEVBOX_EDGE_CA")
	}

	w := &webSide{d: d}
	w.server = &http.Server{
		Handler:           w,
		ReadHeaderTimeout: 15 * time.Second,
		IdleTimeout:       2 * time.Minute,
		ErrorLog:          log.New(discard{}, "", 0), // bots' failed handshakes aren't news
		TLSConfig: &tls.Config{
			MinVersion:   tls.VersionTLS12,
			Certificates: []tls.Certificate{cert},
			ClientAuth:   tls.RequireAndVerifyClientCert,
			ClientCAs:    pool,
		},
	}

	return w, nil
}

func envPEM(name string) []byte {
	value := strings.TrimSpace(os.Getenv(name))
	if value == "" {
		return nil
	}
	if strings.HasPrefix(value, "-----BEGIN") {
		return []byte(value)
	}
	data, err := base64.StdEncoding.DecodeString(value)
	if err != nil {
		return nil
	}

	return data
}

func (w *webSide) serve(l net.Listener) error {
	err := w.server.ServeTLS(countingListener{Listener: l, count: &w.active}, "", "")
	if errors.Is(err, http.ErrServerClosed) {
		return nil
	}

	return err
}

func (w *webSide) ServeHTTP(rw http.ResponseWriter, r *http.Request) {
	// The edge asked the panel who this browser is; the devbox still has to be open to anyone at
	// all (not before the panel's first answer, not once it is locked down).
	if !w.d.people.anyone() {
		http.Error(rw, "This devbox is not open right now. Open it again from the panel.", http.StatusServiceUnavailable)

		return
	}

	proxy, err := w.ensure()
	if err != nil {
		log.Printf("web: %v", err)
		http.Error(rw, "VS Code could not start in this devbox. Stop it in the panel and try again.", http.StatusBadGateway)

		return
	}
	proxy.ServeHTTP(rw, r)
}

// ensure starts code-server if it isn't running and returns the proxy to it.
func (w *webSide) ensure() (*httputil.ReverseProxy, error) {
	w.mu.Lock()
	defer w.mu.Unlock()

	if w.cmd != nil {
		select {
		case <-w.exit:
			w.cmd = nil
		default:
			return w.proxy, nil
		}
	}

	if _, err := os.Stat(codeServerBin); err != nil {
		return nil, errors.New("code-server is not in this image")
	}

	port, err := freePort()
	if err != nil {
		return nil, err
	}

	data := filepath.Join(w.d.home, ".local", "share", "code-server")
	if err := os.MkdirAll(data, 0o700); err != nil {
		return nil, err
	}
	logFile, err := os.OpenFile(filepath.Join(data, "ph-code-server.log"), os.O_CREATE|os.O_WRONLY|os.O_APPEND, 0o600)
	if err != nil {
		return nil, err
	}

	cmd := exec.Command(codeServerBin,
		"--auth", "none",
		"--bind-addr", fmt.Sprintf("127.0.0.1:%d", port),
		"--disable-telemetry",
		"--disable-update-check",
		"--user-data-dir", data,
		"--extensions-dir", filepath.Join(data, "extensions"),
		w.d.workdir(),
	)
	cmd.Dir = w.d.workdir()
	cmd.Env = w.d.sessionEnv("web", nil)
	cmd.Stdout = logFile
	cmd.Stderr = logFile
	if err := cmd.Start(); err != nil {
		logFile.Close()

		return nil, fmt.Errorf("starting code-server: %w", err)
	}

	exit := make(chan struct{})
	go func() {
		_ = cmd.Wait()
		logFile.Close()
		close(exit)
		log.Printf("web: code-server stopped")
	}()

	if err := waitForPort(port, 30*time.Second, exit); err != nil {
		_ = cmd.Process.Kill()

		return nil, err
	}

	target := &url.URL{Scheme: "http", Host: fmt.Sprintf("127.0.0.1:%d", port)}
	proxy := httputil.NewSingleHostReverseProxy(target)
	proxy.ErrorLog = log.New(discard{}, "", 0)
	proxy.FlushInterval = -1 // stream as it comes (websockets, logs)

	w.cmd, w.port, w.proxy, w.exit = cmd, port, proxy, exit
	log.Printf("web: code-server started on 127.0.0.1:%d", port)

	return proxy, nil
}

// closeBackend ends code-server, and with it every browser connection proxied to it. The next
// allowed request starts it again.
func (w *webSide) closeBackend() {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.cmd != nil && w.cmd.Process != nil {
		_ = w.cmd.Process.Kill()
	}
}

func (w *webSide) stop() {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.cmd != nil && w.cmd.Process != nil {
		_ = w.cmd.Process.Signal(os.Interrupt)
	}
	_ = w.server.Close()
}

func freePort() (int, error) {
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		return 0, err
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port, nil
}

func waitForPort(port int, limit time.Duration, exit <-chan struct{}) error {
	deadline := time.Now().Add(limit)
	for time.Now().Before(deadline) {
		select {
		case <-exit:
			return errors.New("code-server exited while starting (see ~/.local/share/code-server/ph-code-server.log)")
		default:
		}
		conn, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", port), 500*time.Millisecond)
		if err == nil {
			conn.Close()

			return nil
		}
		time.Sleep(200 * time.Millisecond)
	}

	return errors.New("code-server did not start in time")
}

type discard struct{}

func (discard) Write(p []byte) (int, error) { return len(p), nil }
