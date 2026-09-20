<?php

namespace PluggedHost\Core\Services;

use App\Exceptions\DisplayException;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerDeletionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Deleting an account, all the way down.
 *
 * `$user->delete()` on its own cannot succeed for most accounts, and leaves rows behind when it
 * does. Neither problem is visible from the call site, so both are written down here:
 *
 *  - `App\Models\User::booted()` registers a `deleting` listener that throws
 *    `exceptions.users.has_servers` while the account still owns a server, and `servers.owner_id`
 *    is additionally `ON DELETE RESTRICT`. Measured on this install: 206 of 305 accounts own at
 *    least one server, so for two thirds of the userbase the delete button simply refuses. The
 *    servers have to go first, and only through `ServerDeletionService`, which is also what tells
 *    the daemon to remove the container and drops the server's databases from their hosts.
 *
 *  - Three places hold a user id that no foreign key can cascade through, so those rows outlive
 *    the account: `activity_logs.actor_id` and `notifications.notifiable_id` (both morphs, where
 *    a foreign key is not expressible) and `sessions.user_id`. A dangling actor id is not
 *    harmless: `ActivityLog::htmlable()` substitutes a fake `system` user when the actor cannot
 *    be resolved, so the audit log quietly re-attributes a departed user's actions to the panel
 *    itself. Those same rows carry the user's IP addresses, which is the one thing an account
 *    deletion is unambiguously supposed to remove.
 *
 * Everything else really does cascade -- 15 `ON DELETE CASCADE` keys cover api keys, passkeys,
 * ssh keys, subuser grants, credits, tokens, discord links, legal acceptances, queue entries and
 * the rest -- so this class only has to handle the two cases above.
 */
class AccountDeletionService
{
    public function __construct(private ServerDeletionService $serverDeletionService) {}

    /**
     * Delete the given user, optionally taking their servers with it.
     *
     * @param  bool  $withServers  destroy every server the account owns first. Without this the
     *                             call refuses for a server owner, which is the old behaviour and
     *                             still the right default: nothing should be able to wipe a
     *                             server as a side effect of an unrelated delete.
     * @param  bool  $force        let a server be forgotten even if its node does not confirm the
     *                             deletion. Admin-only; the container may be left running.
     * @return int the number of servers deleted along with the account
     *
     * @throws DisplayException
     * @throws Throwable
     */
    public function handle(User $user, bool $withServers = false, bool $force = false): int
    {
        throw_if(
            $user->isLastRootAdmin(),
            new DisplayException(trans('plugged-host-core::strings.notifications.account_delete_last_admin'))
        );

        /** @var Collection<int, Server> $servers */
        $servers = $user->servers()->get();

        throw_if(
            $servers->isNotEmpty() && !$withServers,
            new DisplayException(trans('plugged-host-core::strings.notifications.account_delete_has_servers'))
        );

        // Outside the transaction below on purpose: each deletion talks to a node and runs its own
        // transaction, and no rollback here could un-delete a container that wings has already
        // removed. If one fails we stop and say which, rather than pressing on.
        $deleted = $this->deleteServers($servers, $force);

        DB::transaction(function () use ($user): void {
            // Upstream's `deleting` guard refuses when the request user is the user being deleted,
            // which is exactly the self-service case, so the resolver is cleared for that one call.
            $originalResolver = request()->getUserResolver();
            request()->setUserResolver(fn () => null);

            try {
                $user->delete();
            } finally {
                request()->setUserResolver($originalResolver);
            }

            // Wipe remember token and mark model as purged so any post-delete hooks (e.g. SessionGuard::logout)
            // cannot cycle remember tokens or save this model back into the database.
            $user->setRememberToken(null);
            $user->account_is_purged = true;

            $this->purgeUnconstrainedReferences($user);
        });

        return $deleted;
    }

    /**
     * Remove the rows that reference a user without a foreign key to carry them away.
     *
     * Called after the user row is gone and inside the same transaction, so a delete that is
     * refused at the last moment does not cost anybody their audit trail.
     */
    protected function purgeUnconstrainedReferences(User $user): void
    {
        $morph = $user->getMorphClass();

        if (Schema::hasTable('activity_logs')) {
            // activity_log_subjects.activity_log_id is ON DELETE CASCADE, so the subject rows of
            // each log entry go with it -- this does not trade one orphan for another.
            DB::table('activity_logs')
                ->where('actor_type', $morph)
                ->where('actor_id', $user->id)
                ->delete();
        }

        if (Schema::hasTable('notifications')) {
            DB::table('notifications')
                ->where('notifiable_type', $morph)
                ->where('notifiable_id', $user->id)
                ->delete();
        }

        // Sessions live in redis here, so this is usually a no-op -- but the table exists, the
        // driver is a config value, and a stale row would keep a deleted account logged in.
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    /**
     * @param  Collection<int, Server>  $servers
     *
     * @throws DisplayException
     */
    protected function deleteServers(Collection $servers, bool $force): int
    {
        $deleted = 0;

        foreach ($servers as $server) {
            try {
                $this->serverDeletionService->withForce($force)->handle($server);
            } catch (Throwable $exception) {
                if (!$exception instanceof DisplayException) {
                    report($exception);
                }

                $message = trans('plugged-host-core::strings.notifications.account_delete_server_failed', [
                    'server' => $server->name,
                    'error' => $exception instanceof DisplayException ? $exception->getMessage() : trans('plugged-host-core::strings.notifications.account_delete_server_unreachable'),
                ]);

                if ($deleted > 0) {
                    $message .= ' ' . trans('plugged-host-core::strings.notifications.account_delete_partial', ['count' => $deleted]);
                }

                throw new DisplayException($message);
            }

            $deleted++;
        }

        return $deleted;
    }
}
