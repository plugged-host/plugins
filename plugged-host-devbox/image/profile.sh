# plugged.host devbox: login shells (the terminal) get user-level tools on PATH and a hello.
case ":$PATH:" in
    *":$HOME/.local/bin:"*) ;;
    *) export PATH="$HOME/.local/bin:$PATH" ;;
esac

if [ -n "$PS1" ] && [ -t 1 ] && [ -z "$PH_HELLO" ]; then
    export PH_HELLO=1
    echo "plugged.host devbox: your server's files are in /server. Type 'ph help' for what ph can do."
fi
