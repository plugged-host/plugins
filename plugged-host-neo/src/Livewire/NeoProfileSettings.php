<?php

namespace PluggedHost\Neo\Livewire;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use PluggedHost\Core\Support\NoprofitLink;
use PluggedHost\Neo\Models\NeoConversation;
use PluggedHost\Neo\Services\NeoGateway;
use PluggedHost\Neo\Support\NeoPreferences;

/**
 * The "neo" tab on the profile page. Each toggle saves the moment it is flipped: the page's
 * own Save button rebuilds the user from upstream's fillable list and would drop them.
 *
 * @property Schema $form
 */
class NeoProfileSettings extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $settings = NeoPreferences::for($this->user());

        $this->form->fill([
            'enabled' => (bool) $settings->enabled,
            'auto_approve' => (bool) $settings->auto_approve,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('enabled')
                    ->label(trans('plugged-host-neo::strings.profile.enabled'))
                    ->helperText(trans('plugged-host-neo::strings.profile.enabled_hint'))
                    ->live()
                    ->afterStateUpdated(fn (bool $state) => $this->persist(['enabled' => $state])),
                Toggle::make('auto_approve')
                    ->label(trans('plugged-host-neo::strings.profile.auto_approve'))
                    ->helperText(trans('plugged-host-neo::strings.profile.auto_approve_hint'))
                    ->live()
                    ->afterStateUpdated(fn (bool $state) => $this->persist(['auto_approve' => $state])),
            ])
            ->statePath('data');
    }

    public function deleteHistoryAction(): Action
    {
        return Action::make('deleteHistory')
            ->label(trans('plugged-host-neo::strings.profile.delete_history'))
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (): void {
                NeoConversation::query()->where('user_id', $this->user()->id)->delete();

                Notification::make()->title(trans('plugged-host-neo::strings.profile.deleted'))->success()->send();
            });
    }

    public function render(): View
    {
        $user = $this->user();
        $usage = app(NeoGateway::class)->usage($user);

        return view('plugged-host-neo::livewire.profile-settings', [
            'usage' => $usage,
            'linked' => NoprofitLink::accountId($user) !== null || ($usage['linked'] ?? false),
            'timezone' => $user->timezone ?: config('app.timezone'),
            'retentionDays' => (int) config('plugged-host-neo.retention_days', 30),
        ]);
    }

    /**
     * @param  array{enabled?: bool, auto_approve?: bool}  $values
     */
    private function persist(array $values): void
    {
        NeoPreferences::set($this->user(), $values);

        Notification::make()->title(trans('plugged-host-neo::strings.profile.saved'))->success()->send();
    }

    private function user(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
