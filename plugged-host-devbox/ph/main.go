// ph is the plugged.host devbox program: the SSH server a devbox runs (`ph serve`) and the
// command people and tools use inside it to look after the game server next door.
package main

import (
	"fmt"
	"os"
)

// version is set at build time (-ldflags "-X main.version=...").
var version = "dev"

const usage = `ph: the plugged.host devbox

Your game server's files are in /server. The game itself runs in its own container;
ph talks to it for you:

  ph status            is it running, CPU, memory, disk
  ph logs [-n 100]     its recent console output
  ph logs -f           ...and keep following it (Ctrl-C to stop)
  ph cmd "say hi"      run a console command and show the answer
  ph console           live console; type commands, Ctrl-C leaves
  ph start | stop | restart | kill

What you may do follows your permissions on the server (or, in a shared devbox,
what the owner allowed).

  ph version           ph's version
`

func main() {
	if len(os.Args) < 2 {
		fmt.Print(usage)
		os.Exit(2)
	}

	var err error
	switch cmd, args := os.Args[1], os.Args[2:]; cmd {
	case "serve":
		err = serve(args)
	case "status":
		err = runStatus(args)
	case "start", "stop", "restart", "kill":
		err = runPower(cmd)
	case "logs", "log":
		err = runLogs(args)
	case "cmd", "command", "send":
		err = runCmd(args)
	case "console":
		err = runConsole(args)
	case "version", "--version", "-v":
		fmt.Println(version)
	case "help", "--help", "-h":
		fmt.Print(usage)
	default:
		fmt.Fprintf(os.Stderr, "ph: unknown command %q\n\n%s", cmd, usage)
		os.Exit(2)
	}

	if err != nil {
		fmt.Fprintln(os.Stderr, "ph:", err)
		os.Exit(1)
	}
}
