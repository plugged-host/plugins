<?php

namespace PluggedHost\Core\Providers;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Throwable;

/**
 * Intercepts daemon exceptions and transient client errors to prevent unhandled 500
 * error modals in Livewire, and filters non-fatal validation/daemon errors from log spam.
 *
 * 1. Livewire Action Resilience:
 *    When a server daemon HTTP call fails inside a Livewire action (e.g. decompressing
 *    an archive when server disk is full, or deleting locked/missing files in ListFiles),
 *    the daemon returns a 4xx or 5xx JSON error. Because the upstream actions do not catch
 *    RequestException or ConnectionException, Livewire would otherwise abort the request
 *    with a 500 Server Error and display the generic "An error was encountered while
 *    trying to load this page / processing this request" modal.
 *    By listening to Livewire's 'exception' event and calling $stopPropagation(), we prevent
 *    the crash and instead dispatch a user-friendly Filament danger notification toast
 *    containing the actual daemon message.
 *
 * 2. Exception Report Filtering:
 *    - Turnstile token replay/double-click: Cloudflare returns 'timeout-or-duplicate' and
 *      Rule.php calls report($exception) on a regular validation failure.
 *    - Daemon 4xx errors: User-level errors like disk quota exceeded (400) or missing file
 *      (404) are client-side operational results, not panel server crashes.
 *    - Transient resolving timeouts on daemon status probes.
 */
class DaemonResiliencePluginProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerLivewireExceptionInterceptor();
        $this->registerExceptionFilters();
    }

    protected function registerLivewireExceptionInterceptor(): void
    {
        Livewire::listen('exception', function (object $target, Throwable $e, Closure $stopPropagation): void {
            if ($e instanceof RequestException) {
                $stopPropagation();

                $error = $e->response?->json('error')
                    ?? $e->response?->json('message')
                    ?? $e->getMessage();

                Notification::make()
                    ->title(trans('server/file.exceptions.daemon_error') ?: 'Operation Failed')
                    ->body($error)
                    ->danger()
                    ->send();
            } elseif ($e instanceof ConnectionException) {
                $stopPropagation();

                Notification::make()
                    ->title('Daemon Connection Error')
                    ->body('Unable to connect to the node daemon. Please try again later.')
                    ->danger()
                    ->send();
            }
        });
    }

    protected function registerExceptionFilters(): void
    {
        /** @var ExceptionHandler $handler */
        $handler = $this->app->make(ExceptionHandler::class);

        // Filter Cloudflare Turnstile duplicate token validation exceptions from error logs.
        $handler->reportable(function (Throwable $e): bool|null {
            if (str_contains($e->getMessage(), 'The response parameter (token) has already been validated before.')) {
                return false;
            }

            return null;
        });

        // Filter daemon 4xx errors (client errors such as out of disk space 400 or file not found 404).
        $handler->reportable(function (RequestException $e): bool|null {
            if ($e->response?->status() < 500) {
                return false;
            }

            return null;
        });

        // Filter routine resolving timeouts from daemon status polls.
        $handler->reportable(function (ConnectionException $e): bool|null {
            if (str_contains($e->getMessage(), 'Resolving timed out')) {
                return false;
            }

            return null;
        });

        // Filter OAuth state expiration or duplicate callback errors.
        $handler->reportable(function (\Laravel\Socialite\Two\InvalidStateException $e): bool|null {
            return false;
        });

        // Filter OAuth user cancellations (e.g. clicking Cancel on Discord OAuth screen).
        $handler->reportable(function (Throwable $e): bool|null {
            if (str_contains($e->getMessage(), 'The resource owner or authorization server denied the request')) {
                return false;
            }

            return null;
        });
    }
}
