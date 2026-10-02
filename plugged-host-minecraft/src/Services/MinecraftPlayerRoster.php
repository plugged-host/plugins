<?php

namespace PluggedHost\Minecraft\Services;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Services\Activity\ActivityLogService;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * The whitelist and operator lists of a Minecraft: Java server, and the two ways to change them.
 *
 * Lifted out of ServerPlayersPage so the onboarding wizard writes players in exactly the way the
 * players page does. The hard part was never the files: a *running* server holds both lists in
 * memory and rewrites them itself, so editing a file behind its back is thrown away on the next
 * start — while a stopped server has no console to send a command to. Every mutator here tries
 * the console first (when the server is up and the user may use it), falls back to writing the
 * file, and reports which of the two happened, because that is the difference between "done" and
 * "done after the next restart".
 *
 * Not bound in the container: one instance describes one server's lists and carries the state it
 * read, so a Livewire component can hand its own state back with hydrate() instead of paying for
 * another daemon round trip on every action.
 *
 * @see MinecraftProfileResolver for why a stopped-server edit needs a uuid lookup at all
 */
class MinecraftPlayerRoster
{
    public const WHITELIST = 'whitelist.json';

    public const OPS = 'ops.json';

    public const PROPERTIES = 'server.properties';

    /** @var array<int, array<string, mixed>> */
    protected array $whitelist = [];

    /** @var array<int, array<string, mixed>> */
    protected array $operators = [];

    protected bool $enforced = false;

    protected bool $onlineMode = true;

    protected int $level = 4;

    protected bool $unreachable = false;

    protected bool $notify = true;

    /** @var array<int, string> */
    protected array $successes = [];

    /** @var array<int, string> */
    protected array $errors = [];

    /**
     * True once a change was applied by writing a file rather than through the console, i.e. once
     * something in here will only take effect on the next start.
     */
    protected bool $restartPending = false;

    /**
     * Acting for the server rather than for whoever is logged in.
     *
     * A queued job has no request, so user() is null and every permission check would refuse —
     * including the console one, which would silently downgrade a running server to a file edit.
     * System mode says the authorisation happened earlier, when the user submitted the change that
     * queued the job, and names the actor explicitly so the audit log still points at a person.
     */
    protected bool $system = false;

    protected ?User $actor = null;

    final public function __construct(protected Server $server) {}

    public static function for(Server $server): static
    {
        return new static($server);
    }

    public function asSystem(?User $actor = null): static
    {
        $this->system = true;
        $this->actor = $actor;

        return $this;
    }

    /**
     * Collect messages instead of sending a notification per change.
     *
     * The players page acts on one player at a time and wants to be told each time; the
     * onboarding wizard applies whole lists at once and wants one summary at the end.
     */
    public function silently(): static
    {
        $this->notify = false;

        return $this;
    }

    /**
     * Adopt state that has already been read, without touching the daemon.
     *
     * @param  array<int, array<string, mixed>>  $whitelist
     * @param  array<int, array<string, mixed>>  $operators
     */
    public function hydrate(array $whitelist, array $operators, bool $enforced, bool $onlineMode, int $level): static
    {
        $this->whitelist = $whitelist;
        $this->operators = $operators;
        $this->enforced = $enforced;
        $this->onlineMode = $onlineMode;
        $this->level = $level;

        return $this;
    }

    /**
     * Read both lists and the three server.properties keys everything else depends on.
     */
    public function load(): static
    {
        $this->unreachable = false;

        // Properties first: entries() falls back to the server's own op-permission-level for an
        // ops.json entry that somehow has no level of its own.
        $this->readProperties();

        $this->whitelist = $this->entries(self::WHITELIST);
        $this->operators = $this->entries(self::OPS);

        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function whitelist(): array
    {
        return $this->whitelist;
    }

    /** @return array<int, array<string, mixed>> */
    public function operators(): array
    {
        return $this->operators;
    }

    public function isEnforced(): bool
    {
        return $this->enforced;
    }

    public function isOnlineMode(): bool
    {
        return $this->onlineMode;
    }

    public function level(): int
    {
        return $this->level;
    }

    public function isUnreachable(): bool
    {
        return $this->unreachable;
    }

    public function restartPending(): bool
    {
        return $this->restartPending;
    }

    /** @return array<int, string> */
    public function successes(): array
    {
        return $this->successes;
    }

    /** @return array<int, string> */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Whether the user may change either list at all.
     *
     * File permission rather than console permission, because the file is the path that always
     * exists: the console is only available while the server happens to be running.
     */
    public function canWrite(): bool
    {
        return $this->system || (bool) user()?->can(SubuserPermission::FileUpdate, $this->server);
    }

    /**
     * Add or remove a whitelist entry.
     *
     * The only thing that comes in is a name; everything else is re-derived from the state this
     * object holds, so a name that is already in the state being asked for is a no-op rather than
     * a duplicate entry.
     */
    public function setWhitelisted(string $name, bool $add): bool
    {
        abort_unless($this->canWrite(), 403);

        $existing = $this->find($this->whitelist, $name);

        // Already in the state being asked for — whoever called was working from a stale list.
        if ($add === ($existing !== null)) {
            return true;
        }

        if ($add) {
            $profile = $this->profile($name);

            if ($profile === null) {
                $this->failed(trans('plugged-host-minecraft::strings.players.unknown_user', ['name' => $name]));

                return false;
            }

            $name = $profile['name'];
            $list = array_merge($this->whitelist, [$profile + ['level' => $this->level, 'bypassesPlayerLimit' => false]]);
        } else {
            $name = (string) $existing['name'];
            $list = $this->without($this->whitelist, $name);
        }

        // A running server holds the whitelist in memory and writes the file itself, so the
        // console is the only way to change it there. Writing the file is the fallback: it is also
        // what happens when the status cache still says "running" a moment after it stopped, which
        // is exactly when a bare error message would be useless.
        $applied = $this->applyLive() && $this->command(($add ? 'whitelist add ' : 'whitelist remove ') . $name);

        if (!$applied && !$this->writeList(self::WHITELIST, $this->shape($list, false))) {
            return false;
        }

        $this->whitelist = $list;
        $this->done($add ? 'whitelisted' : 'unwhitelisted', $name, $applied);

        return true;
    }

    /**
     * Add or remove an operator entry.
     *
     * The level a new operator gets is the server's own op-permission-level, which is also what
     * `op <name>` in the console would give them — the two paths have to agree, or the panel would
     * show a level the server does not actually grant.
     */
    public function setOperator(string $name, bool $add): bool
    {
        abort_unless($this->canWrite(), 403);

        $existing = $this->find($this->operators, $name);

        if ($add === ($existing !== null)) {
            return true;
        }

        if ($add) {
            $profile = $this->profile($name);

            if ($profile === null) {
                $this->failed(trans('plugged-host-minecraft::strings.players.unknown_user', ['name' => $name]));

                return false;
            }

            $name = $profile['name'];
            $list = array_merge($this->operators, [$profile + ['level' => $this->level, 'bypassesPlayerLimit' => false]]);
        } else {
            $name = (string) $existing['name'];
            $list = $this->without($this->operators, $name);
        }

        $applied = $this->applyLive() && $this->command(($add ? 'op ' : 'deop ') . $name);

        if (!$applied && !$this->writeList(self::OPS, $this->shape($list, true))) {
            return false;
        }

        $this->operators = $list;
        $this->done($add ? 'opped' : 'deopped', $name, $applied);

        return true;
    }

    /**
     * Turn whitelist enforcement on or off.
     *
     * Unlike the two lists, this one is written to server.properties every time: `whitelist on`
     * only flips the running server's own copy of the setting and is forgotten on the next start,
     * so the console command is what makes it take effect *now* and the file is what makes it
     * stick. Enforcement is left where the file says it is when the write fails, so a caller can
     * never end up showing a setting the server does not have.
     */
    public function enforce(bool $state): bool
    {
        abort_unless($this->canWrite(), 403);

        $applied = $this->applyLive() && $this->command($state ? 'whitelist on' : 'whitelist off');

        if (!$this->writeProperties(['white-list' => $state], $applied)) {
            return false;
        }

        $this->done($state ? 'enforced_on' : 'enforced_off', '', $applied);

        return true;
    }

    /**
     * Write server.properties keys, keeping every other line exactly as it was.
     *
     * Re-reads the file rather than reusing the copy load() took: the server rewrites it on every
     * start, and every key except the ones being set has to come back out unchanged.
     *
     * `$live` says the same change was already pushed into the running server by some other means
     * (a console command), so the write is only about making it survive a restart. Left false, the
     * write is the whole change and therefore does not take effect until the next start.
     *
     * `$create` writes the keys into a new file when there is no server.properties yet, which is
     * the normal state of a server that has never been started — onboarding runs exactly there. The
     * server fills the rest of the file in on first boot and keeps what it finds, so a two-line
     * file is a legitimate starting point. Off by default: for the players page a missing file
     * means the daemon or the server is not in the state the page thought it was, and inventing one
     * would hide that.
     *
     * @param  array<string, bool|int|string>  $values
     */
    public function writeProperties(array $values, bool $live = false, bool $create = false): bool
    {
        $raw = $this->read(self::PROPERTIES);

        if ($raw === null && $create && !$this->unreachable) {
            $raw = '';
        }

        if ($raw === null) {
            $this->failed(trans('plugged-host-minecraft::strings.players.properties_missing'));

            return false;
        }

        if (!$this->put(self::PROPERTIES, app(GameConfigFile::class)->writeProperties($raw, $values))) {
            return false;
        }

        // Keep the tracked copy of the three keys this class reasons about in step with the file,
        // so a caller that writes online-mode and then adds a player resolves the right uuid.
        foreach ($values as $key => $value) {
            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN);

            match ($key) {
                'white-list' => $this->enforced = $flag,
                'online-mode' => $this->onlineMode = $flag,
                'op-permission-level' => $this->level = max(1, min(4, (int) $value)),
                default => null,
            };
        }

        if (!$live) {
            $this->restartPending = true;
        }

        return true;
    }

    /**
     * One of the two JSON lists, normalised.
     *
     * A file that is not there yet is an empty list, not an error: neither file exists until the
     * server has been started once, and ops.json stays absent until someone is opped.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function entries(string $path): array
    {
        $decoded = json_decode((string) $this->read($path), true);

        if (!is_array($decoded)) {
            return [];
        }

        $entries = [];

        foreach ($decoded as $entry) {
            $name = is_array($entry) && is_string($entry['name'] ?? null) ? trim($entry['name']) : '';

            if ($name === '') {
                continue;
            }

            $entries[] = [
                'uuid' => is_string($entry['uuid'] ?? null) ? trim($entry['uuid']) : '',
                'name' => $name,
                'level' => (int) ($entry['level'] ?? $this->level),
                'bypassesPlayerLimit' => (bool) ($entry['bypassesPlayerLimit'] ?? false),
            ];
        }

        return $entries;
    }

    /**
     * The file's contents, or null when it is not there.
     *
     * A daemon that cannot be reached is a different thing from a file that does not exist, and
     * only the first one is worth telling the user about — hence the flag.
     */
    protected function read(string $path): ?string
    {
        try {
            return convert_to_utf8($this->files()->getContent($path, (int) config('panel.files.max_edit_size')));
        } catch (ConnectionException) {
            $this->unreachable = true;

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Pick up whitelist enforcement, online mode and the operator level from the server's own
     * configuration, so what this class writes matches what the server would have written.
     */
    protected function readProperties(): void
    {
        $raw = $this->read(self::PROPERTIES);

        if ($raw === null) {
            return;
        }

        $values = app(GameConfigFile::class)->properties($raw);
        $bool = fn (string $key, bool $default) => isset($values[$key])
            ? filter_var(trim((string) $values[$key]), FILTER_VALIDATE_BOOLEAN)
            : $default;

        $this->enforced = $bool('white-list', false);
        $this->onlineMode = $bool('online-mode', true);
        $this->level = max(1, min(4, (int) trim((string) ($values['op-permission-level'] ?? 4))));
    }

    /**
     * The uuid/name pair to write, or null when there is no such player.
     *
     * An offline-mode server never asks Mojang who anyone is, and neither can this class: the uuid
     * such a server looks for is derived from the name itself, so a Mojang uuid would name a player
     * it never sees. Both paths are needed even when the command is going to the console instead of
     * the file, because wings answers "sent" for a command the server then quietly rejects —
     * checking the name here is the only chance to say "no such account" rather than claim a
     * success that did not happen.
     *
     * @return array{uuid: string, name: string}|null
     */
    protected function profile(string $name): ?array
    {
        $resolver = app(MinecraftProfileResolver::class);

        return $this->onlineMode ? $resolver->resolve($name) : $resolver->offline($name);
    }

    /**
     * Whether the console is the right way to apply a change right now.
     *
     * Both halves matter: a stopped server has no console to talk to, and a subuser with file
     * access but not console access is not allowed to use it even when there is one.
     */
    protected function applyLive(): bool
    {
        if (!$this->system && !user()?->can(SubuserPermission::ControlConsole, $this->server)) {
            return false;
        }

        try {
            return $this->server->retrieveStatus() === ContainerStatus::Running;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Send one console command, reporting whether the server took it.
     *
     * The daemon http macro does not throw on a 4xx, so the status code is the only thing that says
     * whether the command was delivered — a false here is what makes the caller fall back to
     * writing the file.
     */
    protected function command(string $command): bool
    {
        // One command per call: player names come from the browser and from files the server
        // writes, and a line break would send a second, arbitrary console command.
        if (preg_match('/[\r\n\x00]/', $command) === 1) {
            return false;
        }

        try {
            $response = $this->server->send($command);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        if ($response->getStatusCode() >= 400) {
            return false;
        }

        $this->activity('server:console.command')
            ->property('command', $command)
            ->log();

        return true;
    }

    /**
     * Write one of the two JSON lists.
     *
     * @param  array<int, array<string, mixed>>  $list
     */
    protected function writeList(string $path, array $list): bool
    {
        // Pretty-printed with a trailing newline, which is how the server writes both files — so a
        // diff after a panel edit shows the entry that changed and nothing else.
        $encoded = json_encode(array_values($list), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($encoded)) {
            $this->failed(trans('plugged-host-minecraft::strings.players.write_failed', ['file' => $path]));

            return false;
        }

        return $this->put($path, $encoded . "\n");
    }

    protected function put(string $path, string $contents): bool
    {
        try {
            $this->files()->putContent($path, $contents);
        } catch (Throwable $exception) {
            report($exception);

            $this->failed(trans('plugged-host-minecraft::strings.players.write_failed', ['file' => $path]));

            return false;
        }

        $this->activity('server:file.write')
            ->property('file', $path)
            ->log();

        return true;
    }

    /**
     * An audit entry for something this class did.
     *
     * In a request the facade fills in the actor and the subject from the panel's own context; in a
     * job there is no context, so both have to be said out loud or the entry reads as something the
     * system decided to do on its own.
     */
    protected function activity(string $event): ActivityLogService
    {
        $activity = Activity::event($event);

        if (!$this->system) {
            return $activity;
        }

        $activity->subject($this->server);

        if ($this->actor !== null) {
            $activity->actor($this->actor);
        }

        return $activity;
    }

    protected function files(): DaemonFileRepository
    {
        return app(DaemonFileRepository::class)->setServer($this->server);
    }

    /**
     * Entries as the file itself holds them.
     *
     * whitelist.json holds a uuid and a name; ops.json adds the level and the player-limit bypass.
     * State here carries all four for both lists so one row can describe a player in either file,
     * so the extra keys are dropped on the way out rather than on the way in.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @return array<int, array<string, mixed>>
     */
    protected function shape(array $list, bool $ops): array
    {
        return array_map(fn (array $entry): array => $ops
            ? [
                'uuid' => $entry['uuid'],
                'name' => $entry['name'],
                'level' => (int) $entry['level'],
                'bypassesPlayerLimit' => (bool) $entry['bypassesPlayerLimit'],
            ]
            : [
                'uuid' => $entry['uuid'],
                'name' => $entry['name'],
            ], $list);
    }

    /**
     * The entry for a name, matched the way the server matches it: case-insensitively.
     *
     * @param  array<int, array<string, mixed>>  $list
     * @return array<string, mixed>|null
     */
    protected function find(array $list, string $name): ?array
    {
        foreach ($list as $entry) {
            if (strcasecmp((string) $entry['name'], $name) === 0) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $list
     * @return array<int, array<string, mixed>>
     */
    protected function without(array $list, string $name): array
    {
        return array_values(array_filter(
            $list,
            fn (array $entry): bool => strcasecmp((string) $entry['name'], $name) !== 0,
        ));
    }

    /**
     * Report a change that went through, saying whether it is in effect yet.
     *
     * A file edit only reaches the server on its next start, and that is the whole difference
     * between the two paths — so it is the one thing the message has to say.
     */
    protected function done(string $key, string $name, bool $applied): void
    {
        if (!$applied) {
            $this->restartPending = true;
        }

        $message = trans("plugged-host-minecraft::strings.players.$key", ['name' => $name]);

        $this->successes[] = $message;

        if (!$this->notify) {
            return;
        }

        Notification::make()
            ->title($message)
            ->body($applied ? null : trans('plugged-host-minecraft::strings.players.restart_note'))
            ->success()
            ->send();
    }
    protected function failed(string $message): void
    {
        $this->errors[] = $message;

        if (!$this->notify) {
            return;
        }

        Notification::make()
            ->title(trans('plugged-host-minecraft::strings.players.failed'))
            ->body($message)
            ->danger()
            ->send();
    }
}
