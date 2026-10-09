<?php

namespace PluggedHost\Economy\Providers;

use App\Enums\TabPosition;
use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use PluggedHost\Core\Support\Plugins;
use PluggedHost\Economy\Models\UserCredits;
use PluggedHost\Economy\Models\UserTokens;
use PluggedHost\Economy\Services\TokenService;
use Throwable;

/**
 * An Economy tab on the admin user page: read the two balances, and set either one.
 *
 * Support needs this. Every other way a balance moves is automatic — a Discord invite
 * matures, a sponsored link settles, a purchase clears — so the one thing nobody could
 * do was correct a balance that had gone wrong, refund a purchase that failed halfway,
 * or hand someone the credits an outage cost them.
 *
 * The shape is unusual on purpose, and the reason is upstream's save path. `EditUser`
 * strips exactly `roles` and `avatar` and hands everything else to `UserUpdateService`,
 * which does `$user->forceFill($data)->saveOrFail()` — so **any** extra key in this
 * form's dehydrated state becomes an attempted `users` column write and takes the whole
 * save down with it. Nothing here is a writable field. The balances are `Text`
 * components, which carry no state at all and re-evaluate their closure on every render
 * (so they are current the moment an adjustment lands), and the two adjustments are
 * modal `Action`s whose inputs live in their own schema and never touch the page's form
 * data.
 *
 * Every write goes through the ledger services — `UserCredits::addCredits()` /
 * `deductCredits()` and `TokenService::add()` / `spend()` — never `update()` on the
 * balance row. Those methods are the only thing that writes the transaction row and its
 * `balance_after`, and a balance changed without one silently invalidates that column on
 * every row after it. This tab therefore asks for a *target balance*, converts it to a
 * delta, and posts the delta; setting a balance to what it already is writes nothing.
 *
 * `src/Providers/` is auto-registered by App\Services\Helpers\PluginService — dropping
 * this file in is the whole registration. `UserResource` is guarded by `class_exists`
 * anyway, because a plugin must not be what breaks the panel if upstream moves a class.
 */
class EconomyAdminPluginProvider extends ServiceProvider
{
    public const ADJUSTMENT_TYPE = 'admin_adjustment';

    /**
     * Both balance columns are `unsignedInteger`, so the database's own ceiling is
     * 4294967295 — and `lifetime_earned` is incremented by the same amount, so a write
     * that fits the balance can still overflow that. A billion is far past any real
     * balance and leaves both columns four times the headroom, which is what a typo in
     * a support ticket needs to hit a validation message rather than a 500.
     */
    public const MAX_BALANCE = 1000000000;

    public function boot(): void
    {
        // Client-API routes register regardless of the admin UserResource being present.
        $this->registerClientApi();

        if (!class_exists(UserResource::class)) {
            return;
        }

        try {
            UserResource::registerCustomTabs(TabPosition::After, $this->tab());
        } catch (Throwable $e) {
            Log::warning('plugged-host-economy: admin economy tab not registered', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Client-API (WP7): user-scoped economy endpoints under /api/client/economy. Reproduces
     * RouteServiceProvider's client stack so Scramble documents them by path prefix.
     */
    protected function registerClientApi(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'client-api', 'throttle:api.client'])
            ->prefix('/api/client/economy')
            ->scopeBindings()
            ->group(function (): void {
                \Illuminate\Support\Facades\Route::get('/balances', function (\Illuminate\Http\Request $request) {
                    $user = $request->user();
                    $tokens = Plugins::available(\PluggedHost\Economy\Services\TokenService::class)
                        ? (int) app(\PluggedHost\Economy\Services\TokenService::class)->balance($user) : 0;
                    $credits = (int) (UserCredits::forUser($user)?->credits ?? 0);

                    return response()->json(['data' => ['tokens' => $tokens, 'credits' => $credits]]);
                })->name('api:client:economy.balances');

                \Illuminate\Support\Facades\Route::get('/transactions', function (\Illuminate\Http\Request $request) {
                    $tokens = Plugins::available(\PluggedHost\Economy\Services\TokenService::class)
                        ? app(\PluggedHost\Economy\Services\TokenService::class)->recentTransactions($request->user(), 25) : [];

                    return response()->json(['data' => $tokens]);
                })->name('api:client:economy.transactions');

                \Illuminate\Support\Facades\Route::post('/store/purchase', function (\Illuminate\Http\Request $request) {
                    // Same switch that closes the Resource Store page.
                    if (!config('plugged-host-economy.credits.enabled', true)) {
                        return response()->json(['success' => false, 'message' => 'The resource store is closed.'], 422);
                    }

                    $user = $request->user();
                    $resource = (string) $request->input('resource');
                    $amount = max(1, (int) $request->input('amount', 1));
                    $store = app(\PluggedHost\Economy\Services\ResourceStoreService::class);

                    $result = match ($resource) {
                        'cpu' => $store->purchaseCpu($user, $amount),
                        'memory' => $store->purchaseMemory($user, $amount),
                        'disk' => $store->purchaseDisk($user, $amount),
                        default => ['success' => false, 'message' => 'Unknown resource.'],
                    };

                    return response()->json($result, $result['success'] ? 200 : 422);
                })->name('api:client:economy.store.purchase');
            });
    }

    protected function tab(): Tab
    {
        // Every label is a closure, not a resolved string. This method runs in a service
        // provider's boot(), which is before LanguageMiddleware has had a chance to call
        // setLocale() from users.language — a trans() evaluated here would pin the whole
        // tab to the config default and a German admin would read it in English.
        // Tab::make() takes the *label*, not a name — so the identity is set with key(),
        // which is also what gives Livewire a stable handle on this subtree.
        return Tab::make()
            ->key('ph_economy')
            ->label(fn (): string => trans('plugged-host-economy::strings.admin.tab'))
            ->icon(TablerIcon::Coin)
            // The same form builds CreateUser, where there is no record to hold a
            // balance, and a tab with two disabled buttons in it is worse than no tab.
            ->visible(fn (?Model $record): bool => $record instanceof User)
            ->columns(['default' => 1, 'md' => 2])
            ->schema([
                $this->creditsSection(),
                $this->tokensSection(),
            ]);
    }

    protected function creditsSection(): Section
    {
        return Section::make(fn (): string => trans('plugged-host-economy::strings.admin.credits_heading'))
            ->description(fn (): string => trans('plugged-host-economy::strings.admin.credits_hint'))
            ->schema([
                Text::make(fn (?Model $record): string => $this->balanceLabel(
                    $this->creditsEnabled() ? $this->readCredits($record) : null,
                ))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                Actions::make([
                    Action::make('ph_set_credits')
                        ->label(fn (): string => trans('plugged-host-economy::strings.admin.set_credits'))
                        ->color('gray')
                        ->modalHeading(fn (): string => trans('plugged-host-economy::strings.admin.modal_credits'))
                        ->modalDescription(fn (): string => trans('plugged-host-economy::strings.admin.modal_description'))
                        ->visible(fn (?Model $record): bool => $this->mayAdjust($record) && $this->creditsEnabled())
                        ->schema(fn (?Model $record): array => $this->adjustmentSchema($this->readCredits($record) ?? 0))
                        ->action(fn (array $data, ?Model $record) => $this->setCredits($record, $data)),
                ]),
            ]);
    }

    protected function tokensSection(): Section
    {
        return Section::make(fn (): string => trans('plugged-host-economy::strings.admin.tokens_heading'))
            ->description(fn (): string => trans('plugged-host-economy::strings.admin.tokens_hint'))
            ->schema([
                Text::make(fn (?Model $record): string => $this->balanceLabel(
                    $this->tokensEnabled() ? $this->readTokens($record) : null,
                ))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),
                Actions::make([
                    Action::make('ph_set_tokens')
                        ->label(fn (): string => trans('plugged-host-economy::strings.admin.set_tokens'))
                        ->color('gray')
                        ->modalHeading(fn (): string => trans('plugged-host-economy::strings.admin.modal_tokens'))
                        ->modalDescription(fn (): string => trans('plugged-host-economy::strings.admin.modal_description'))
                        ->visible(fn (?Model $record): bool => $this->mayAdjust($record) && $this->tokensEnabled())
                        ->schema(fn (?Model $record): array => $this->adjustmentSchema($this->readTokens($record) ?? 0))
                        ->action(fn (array $data, ?Model $record) => $this->setTokens($record, $data)),
                ]),
            ]);
    }

    /**
     * The modal's own fields, so nothing they collect can reach the user form.
     *
     * A target balance rather than a delta, because "set this account to 500" is what
     * the person at the keyboard is being asked for; the delta is arithmetic and this
     * class can do it. The bounds mirror the unsigned columns underneath — see
     * MAX_BALANCE — and are asserted again server-side in targetFrom().
     *
     * @return array<int, TextInput|Textarea>
     */
    protected function adjustmentSchema(int $current): array
    {
        return [
            TextInput::make('balance')
                ->label(fn (): string => trans('plugged-host-economy::strings.admin.balance_field'))
                ->helperText(fn (): string => trans('plugged-host-economy::strings.admin.balance_field_hint', [
                    'amount' => number_format($current),
                ]))
                ->numeric()
                ->integer()
                ->minValue(0)
                ->maxValue(self::MAX_BALANCE)
                ->required()
                ->default($current),
            Textarea::make('note')
                ->label(fn (): string => trans('plugged-host-economy::strings.admin.note_field'))
                ->helperText(fn (): string => trans('plugged-host-economy::strings.admin.note_field_hint'))
                ->rows(2)
                ->maxLength(180),
        ];
    }

    /**
     * Who is allowed to move a balance: whoever may edit the user.
     *
     * `UserPolicy::update()` is `canTarget()` plus the ordinary permission check, so a
     * root admin cannot be adjusted by a lesser one — the same rule the rest of this
     * page already enforces. Deliberately not a permission of its own: a new permission
     * would be unassigned on every existing role and the tab would be dead on arrival.
     */
    protected function mayAdjust(?Model $record): bool
    {
        return $record instanceof User && (bool) user()?->can('update', $record);
    }

    protected function creditsEnabled(): bool
    {
        return (bool) config('plugged-host-economy.credits.enabled', true);
    }

    protected function tokensEnabled(): bool
    {
        return app(TokenService::class)->isEnabled();
    }

    /**
     * Null means "no ledger row to read" — the table is missing, or this is a form with
     * no record behind it. Distinct from 0, which is a real balance, because the two
     * want different words on the page and only one of them may be adjusted.
     */
    protected function readCredits(?Model $record): ?int
    {
        if (!$record instanceof User) {
            return null;
        }

        try {
            $credits = UserCredits::forUser($record);

            return $credits instanceof UserCredits ? (int) $credits->credits : null;
        } catch (Throwable $e) {
            $this->report('credit balance read failed', $e);

            return null;
        }
    }

    protected function readTokens(?Model $record): ?int
    {
        if (!$record instanceof User) {
            return null;
        }

        try {
            $tokens = UserTokens::forUser($record);

            return $tokens instanceof UserTokens ? (int) $tokens->tokens : null;
        } catch (Throwable $e) {
            $this->report('token balance read failed', $e);

            return null;
        }
    }

    protected function balanceLabel(?int $balance): string
    {
        return $balance === null
            ? trans('plugged-host-economy::strings.admin.balance_unavailable')
            : trans('plugged-host-economy::strings.admin.balance_current', [
                'amount' => number_format($balance),
            ]);
    }

    /**
     * Set the credit balance to what was asked for, by posting the difference.
     *
     * The read has to happen here and not in the modal's default, because the default
     * was rendered when the modal opened and the balance may have moved since — a
     * Discord invite maturing in between would otherwise be silently reverted by an
     * adjustment the admin thought was a no-op.
     */
    protected function setCredits(?Model $record, array $data): void
    {
        if (!$this->mayAdjust($record) || !$this->creditsEnabled()) {
            return;
        }

        $target = $this->targetFrom($data);
        $current = $this->readCredits($record);

        if ($target === null || $current === null) {
            $this->notifyFailed();

            return;
        }

        $delta = $target - $current;

        if ($delta === 0) {
            $this->notifyUnchanged($target);

            return;
        }

        try {
            $credits = UserCredits::forUser($record);

            if (!$credits instanceof UserCredits) {
                $this->notifyFailed();

                return;
            }

            $description = $this->describe($data);
            $metadata = $this->metadata($current, $target, $data);

            if ($delta > 0) {
                $credits->addCredits($delta, self::ADJUSTMENT_TYPE, $description, $metadata);
            } else {
                $credits->deductCredits(-$delta, self::ADJUSTMENT_TYPE, $description, $metadata);
            }
        } catch (Throwable $e) {
            $this->report('credit adjustment failed', $e);
            $this->notifyFailed();

            return;
        }

        $this->notifySet($target);
    }

    /**
     * The token half. Same arithmetic, different failure convention: TokenService
     * reports by return value rather than by throwing — null from add(), false from
     * spend() — so both are checked explicitly instead of relying on the catch.
     */
    protected function setTokens(?Model $record, array $data): void
    {
        if (!$this->mayAdjust($record) || !$this->tokensEnabled()) {
            return;
        }

        $target = $this->targetFrom($data);
        $current = $this->readTokens($record);

        if ($target === null || $current === null) {
            $this->notifyFailed();

            return;
        }

        $delta = $target - $current;

        if ($delta === 0) {
            $this->notifyUnchanged($target);

            return;
        }

        $tokens = app(TokenService::class);
        $description = $this->describe($data);
        $metadata = $this->metadata($current, $target, $data);

        try {
            $ok = $delta > 0
                ? $tokens->add($record, $delta, self::ADJUSTMENT_TYPE, $description, $metadata) !== null
                : $tokens->spend($record, -$delta, self::ADJUSTMENT_TYPE, $description, $metadata);
        } catch (Throwable $e) {
            $this->report('token adjustment failed', $e);
            $ok = false;
        }

        if (!$ok) {
            $this->notifyFailed();

            return;
        }

        $this->notifySet($target);
    }

    /**
     * The submitted target, re-checked here rather than trusted from validation.
     *
     * `TextInput` state arrives as a string and the modal's rules are client-reachable,
     * so the bounds are asserted again on the way in: this is the one method in the
     * plugin that turns a browser value into money.
     */
    protected function targetFrom(array $data): ?int
    {
        $raw = $data['balance'] ?? null;

        if (!is_numeric($raw)) {
            return null;
        }

        $target = (int) $raw;

        if ($target < 0 || $target > self::MAX_BALANCE) {
            return null;
        }

        return $target;
    }

    /**
     * What the ledger row will say. The acting admin is named in the description and
     * not only in the metadata, because the transaction lists on the store page and in
     * the admin tables render `description` and nothing else.
     */
    protected function describe(array $data): string
    {
        $admin = user()?->username ?? user()?->email ?? 'admin';
        $note = trim((string) ($data['note'] ?? ''));

        $description = $note === ''
            ? trans('plugged-host-economy::strings.admin.tx_description', ['admin' => $admin])
            : trans('plugged-host-economy::strings.admin.tx_description_note', [
                'admin' => $admin,
                'note' => $note,
            ]);

        // `description` is a plain string column (255).
        return Str::limit($description, 250);
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(int $from, int $to, array $data): array
    {
        $note = trim((string) ($data['note'] ?? ''));

        return array_filter([
            'admin_id' => user()?->id,
            'admin' => user()?->username,
            'from' => $from,
            'to' => $to,
            'note' => $note === '' ? null : $note,
        ], fn ($value): bool => $value !== null);
    }

    protected function notifySet(int $target): void
    {
        Notification::make()
            ->title(trans('plugged-host-economy::strings.admin.notify_set', [
                'amount' => number_format($target),
            ]))
            ->success()
            ->send();
    }

    protected function notifyUnchanged(int $target): void
    {
        Notification::make()
            ->title(trans('plugged-host-economy::strings.admin.notify_unchanged', [
                'amount' => number_format($target),
            ]))
            ->info()
            ->send();
    }

    protected function notifyFailed(): void
    {
        Notification::make()
            ->title(trans('plugged-host-economy::strings.admin.notify_failed'))
            ->danger()
            ->send();
    }

    protected function report(string $message, Throwable $e): void
    {
        Log::warning("plugged-host-economy: {$message}", [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
