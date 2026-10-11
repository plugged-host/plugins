// ph is the plugged.host devbox program: the SSH server a devbox runs (`ph serve`) and the
// command people and tools use inside it.
package main

import (
	"fmt"
	"os"
)

// version is set at build time (-ldflags "-X main.version=...").
var version = "dev"

const usage = `ph: the plugged.host devbox

Usage:
  ph serve        run the devbox's SSH server (the container's main process)
  ph version      print the version

Your server's files are in /server.
`

func main() {
	if len(os.Args) < 2 {
		fmt.Print(usage)
		os.Exit(2)
	}

	switch os.Args[1] {
	case "serve":
		if err := serve(os.Args[2:]); err != nil {
			fmt.Fprintln(os.Stderr, "ph serve:", err)
			os.Exit(1)
		}
	case "version", "--version", "-v":
		fmt.Println(version)
	case "help", "--help", "-h":
		fmt.Print(usage)
	default:
		fmt.Fprintf(os.Stderr, "ph: unknown command %q\n\n%s", os.Args[1], usage)
		os.Exit(2)
	}
}
