package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

// agentFile is how `ph` in a session reaches the panel: the running devbox writes it at boot
// (~/.ph/agent.json, 0600). The token in it is only good for this start of this devbox, and
// everyone who can read the file can already read the devbox's environment.
type agentFile struct {
	PanelURL string `json:"panel_url"`
	Token    string `json:"token"`
}

func agentPath(home string) string {
	return filepath.Join(home, ".ph", "agent.json")
}

func writeAgentFile(home, panelURL, token string) error {
	data, err := json.Marshal(agentFile{PanelURL: panelURL, Token: token})
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Join(home, ".ph"), 0o700); err != nil {
		return err
	}
	tmp := agentPath(home) + ".tmp"
	if err := os.WriteFile(tmp, data, 0o600); err != nil {
		return err
	}

	return os.Rename(tmp, agentPath(home))
}

func loadPanel() (*panelClient, error) {
	home := os.Getenv("HOME")
	if home == "" {
		home = "/home/container"
	}
	data, err := os.ReadFile(agentPath(home))
	if err != nil {
		return nil, errors.New("not in a running plugged.host devbox (no ~/.ph/agent.json)")
	}
	var a agentFile
	if err := json.Unmarshal(data, &a); err != nil || a.PanelURL == "" || a.Token == "" {
		return nil, errors.New("~/.ph/agent.json is unreadable; restart the devbox from the panel")
	}

	return newPanelClient(a.PanelURL, a.Token), nil
}

// panelError turns the panel's {"error": "..."} or a status line into something readable.
func call(path string, in, out any) error {
	p, err := loadPanel()
	if err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	return p.post(ctx, path, in, out)
}

func runStatus(args []string) error {
	fs := flag.NewFlagSet("status", flag.ContinueOnError)
	asJSON := fs.Bool("json", false, "print the raw JSON")
	if err := fs.Parse(args); err != nil {
		return err
	}

	var s struct {
		Name           string   `json:"name"`
		ID             string   `json:"id"`
		State          string   `json:"state"`
		MemoryBytes    *float64 `json:"memory_bytes"`
		MemoryLimitMiB *float64 `json:"memory_limit_mib"`
		CPUPercent     *float64 `json:"cpu_percent"`
		DiskBytes      *float64 `json:"disk_bytes"`
		DiskLimitMiB   *float64 `json:"disk_limit_mib"`
	}
	if err := call("game/status", map[string]any{}, &s); err != nil {
		return err
	}

	if *asJSON {
		out, _ := json.MarshalIndent(s, "", "  ")
		fmt.Println(string(out))

		return nil
	}

	fmt.Printf("%s (%s): %s\n", s.Name, s.ID, s.State)
	if s.CPUPercent != nil {
		fmt.Printf("  cpu     %.1f%%\n", *s.CPUPercent)
	}
	if s.MemoryBytes != nil {
		fmt.Printf("  memory  %s of %s\n", human(*s.MemoryBytes), limit(s.MemoryLimitMiB))
	}
	if s.DiskBytes != nil {
		fmt.Printf("  disk    %s of %s\n", human(*s.DiskBytes), limit(s.DiskLimitMiB))
	}

	return nil
}

func runPower(action string) error {
	var r struct {
		OK         bool   `json:"ok"`
		Message    string `json:"message"`
		ContinueAt string `json:"continue_at"`
	}
	if err := call("game/power", map[string]string{"action": action}, &r); err != nil {
		return err
	}

	fmt.Println(r.Message)
	if r.ContinueAt != "" {
		fmt.Println("  " + r.ContinueAt)
	}
	if !r.OK {
		os.Exit(3)
	}

	return nil
}

func human(b float64) string {
	units := []string{"B", "KiB", "MiB", "GiB", "TiB"}
	i := 0
	for b >= 1024 && i < len(units)-1 {
		b /= 1024
		i++
	}

	return fmt.Sprintf("%.1f %s", b, units[i])
}

func limit(mib *float64) string {
	if mib == nil || *mib == 0 {
		return "unlimited"
	}

	return human(*mib * 1024 * 1024)
}

func joinArgs(args []string) string {
	return strings.TrimSpace(strings.Join(args, " "))
}
