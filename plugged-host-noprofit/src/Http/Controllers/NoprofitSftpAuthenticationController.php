<?php

namespace PluggedHost\Noprofit\Http\Controllers;

use App\Facades\Activity;
use App\Http\Controllers\Api\Remote\SftpAuthenticationController;
use App\Http\Requests\Api\Remote\SftpAuthenticationFormRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use PluggedHost\Noprofit\Services\NoprofitClient;
use PluggedHost\Noprofit\Support\NoprofitLink;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * SFTP sign-in for converted accounts.
 *
 * A converted account has no panel password (NoprofitLink::convert), so upstream's
 * password_verify() against users.password can never succeed for it. For those accounts, and
 * only for password logins, this asks noprofit.space to check the password instead. Key
 * logins and every account that has not been converted take upstream's path unchanged.
 *
 * Bound over upstream's controller in NoprofitAccountPluginProvider, so the route, its daemon
 * authentication and the throttling (ThrottlesLogins, per username and IP) stay upstream's.
 */
class NoprofitSftpAuthenticationController extends SftpAuthenticationController
{
    public function __invoke(SftpAuthenticationFormRequest $request): JsonResponse
    {
        if ($request->input('type') === 'public_key') {
            return parent::__invoke($request);
        }

        $connection = $this->parseUsername((string) $request->input('username'));
        $user = empty($connection['username'])
            ? null
            : User::query()->where('username', str($connection['username'])->lower()->trim())->first();

        if (!NoprofitLink::isConverted($user)) {
            return parent::__invoke($request);
        }

        throw_if(empty($connection['server']), new BadRequestHttpException('No valid server identifier was included in the request.'));

        if ($this->hasTooManyLoginAttempts($request)) {
            $seconds = $this->limiter()->availableIn($this->throttleKey($request));

            throw new TooManyRequestsHttpException($seconds, "Too many login attempts for this account, please try again in $seconds seconds.");
        }

        $server = $this->getServer($request, $connection['server']);

        $verdict = $this->verifyWithNoprofit($user, (string) $request->input('password'));
        if ($verdict !== true) {
            Activity::event('auth:sftp.fail')->property('method', 'password')->subject($user)->log();

            // An unreachable noprofit.space is not the user's mistake: reject without counting it.
            $this->reject($request, $verdict === false);
        }

        $this->validateSftpAccess($user, $server);

        return new JsonResponse([
            'user' => $user->uuid,
            'server' => $server->uuid,
            'permissions' => $this->permissions->handle($server, $user),
        ]);
    }

    /**
     * @return bool|null true/false for a verdict, null when noprofit.space could not answer
     */
    private function verifyWithNoprofit(User $user, string $password): ?bool
    {
        if ($password === '' || strlen($password) > 1024) {
            return false;
        }

        $client = app(NoprofitClient::class);
        $secret = (string) config('plugged-host-noprofit.migration_secret');

        if (!$client->configured($secret)) {
            return null;
        }

        try {
            $response = $client->send('POST', '/api/v1/internal/plugged-host/verify-password', [
                'account_id' => NoprofitLink::accountId($user),
                'pelican_user_id' => (int) $user->getKey(),
                'password' => $password,
            ], $secret, 8);
        } catch (Throwable $e) {
            report($e);

            return null;
        }

        if (!$response->successful()) {
            return $response->status() === 422 ? false : null;
        }

        return $response->json('valid') === true;
    }
}
