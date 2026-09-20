<?php

namespace PluggedHost\Servers\Filament\Components\Forms;

use Closure;
use Filament\Forms\Components\Concerns\HasExtraInputAttributes;
use Filament\Forms\Components\Field;

/**
 * A radio group rendered as selectable cards.
 *
 * Used for the game type, location and plan steps of server creation. Options are
 * plain arrays so callers can attach whatever a card needs to show (a flag span, a
 * resource summary, a disabled reason) without a field subclass per step.
 */
class CardSelect extends Field
{
    use HasExtraInputAttributes;

    protected string $view = 'plugged-host-servers::filament.components.card-select';

    /** @var array<int, array<string, mixed>>|Closure */
    protected array|Closure $cardOptions = [];

    protected int|Closure $cardColumns = 3;

    /**
     * @param  array<int, array<string, mixed>>|Closure  $options
     */
    public function cardOptions(array|Closure $options): static
    {
        $this->cardOptions = $options;

        return $this;
    }

    public function cardColumns(int|Closure $columns): static
    {
        $this->cardColumns = $columns;

        return $this;
    }

    /**
     * Normalised card definitions. Every key the view reads is guaranteed present
     * here so the blade template stays free of null juggling.
     *
     * @return array<int, array{value: string, label: string, description: ?string, meta: ?string, image: ?string, svg: ?string, icon: ?string, badge: ?string, disabled: bool}>
     */
    public function getCardOptions(): array
    {
        $options = $this->evaluate($this->cardOptions);

        return collect(is_array($options) ? $options : [])
            ->filter(fn ($option) => is_array($option) && isset($option['value']))
            ->map(fn (array $option) => [
                'value' => (string) $option['value'],
                'label' => (string) ($option['label'] ?? $option['value']),
                'description' => isset($option['description']) ? (string) $option['description'] : null,
                'meta' => isset($option['meta']) ? (string) $option['meta'] : null,
                // An <img> src (used for country flags) rather than a CSS class, so
                // nothing here can collide with Filament's own `.fi-*` namespace.
                'image' => isset($option['image']) ? (string) $option['image'] : null,
                // Inline SVG markup (WP3 country flags), rendered raw. Preferred over `image`
                // because the flags are vendored into the plugin and inlined, not served from
                // public/. Trusted content — vendored flag-icons SVGs, never user input.
                'svg' => isset($option['svg']) && is_string($option['svg']) ? $option['svg'] : null,
                'icon' => isset($option['icon']) ? (string) $option['icon'] : null,
                // A small pill on the top-right of the card (WP3 latency badge).
                'badge' => isset($option['badge']) ? (string) $option['badge'] : null,
                // A Minecraft-style stepped signal meter on the top-right (replaces the
                // plain "N ms" badge for latency). Shape: ['ms' => int, 'bars' => 1..4,
                // 'level' => 'great'|'good'|'ok'|'poor']. Null when latency is unknown.
                'signal' => (isset($option['signal']) && is_array($option['signal'])) ? $option['signal'] : null,
                // WP13 premium: a locked premium card is inert with its own unlock button rather
                // than a plain disabled radio; an unlocked premium card is selectable and keeps
                // the "Premium" pill.
                'premium' => array_key_exists('premium', $option) ? (bool) $option['premium'] : null,
                'locked' => (bool) ($option['locked'] ?? false),
                'cost' => isset($option['cost']) ? (int) $option['cost'] : 0,
                'disabled' => (bool) ($option['disabled'] ?? false),
            ])
            ->values()
            ->all();
    }

    public function getCardColumns(): int
    {
        return max(1, (int) $this->evaluate($this->cardColumns));
    }
}
