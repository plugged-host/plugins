{{--
    The automatic log check above the console (LogCheckBanner). The page renders first and the
    check runs after (wire:init), then every minute while the console is open. Problems get a
    card with mclo.gs's explanation and suggested fix and "Ask neo to fix it"; no problems is one
    quiet line with the log's link.

    "Ask neo" dispatches the same window event the chat listens for (chat.blade.php), which
    starts a fresh chat on this log.
--}}
<div wire:init="check" wire:poll.60s="check" class="neo-logcheck">
    <style>
        .neo-logcheck-block { margin-bottom: 1rem; }
        .neo-logcheck-card { display: flex; flex-direction: column; gap: 0.875rem; padding: 1rem 1.25rem; border-radius: 1rem; background-color: var(--phv2-raise); }
        .neo-logcheck-title { font-size: 0.875rem; font-weight: 600; line-height: 1.25rem; color: var(--warning-400); }
        .neo-logcheck-list { display: flex; flex-direction: column; gap: 0.5rem; margin-top: 0.5rem; }
        .neo-logcheck-problem { font-size: 0.875rem; line-height: 1.375rem; color: var(--phv2-text-nav); }
        .neo-logcheck-fix { display: block; font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .neo-logcheck-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 0.75rem; }
        .neo-logcheck-quiet { font-size: 0.8125rem; line-height: 1.25rem; color: var(--phv2-text-muted); }
        .neo-logcheck-link { font-weight: 500; color: var(--phv2-accent-bright); }
        .neo-logcheck-link:hover { color: var(--phv2-accent-glow); text-decoration: underline; }
        @media (min-width: 640px) {
            .neo-logcheck-card { flex-direction: row; align-items: flex-start; justify-content: space-between; gap: 1.5rem; }
            .neo-logcheck-actions { flex: none; flex-direction: column; align-items: flex-end; gap: 0.5rem; }
        }
    </style>

    @if ($hasIssues)
        @php
            $problems = $result['problems'];
            $shown = array_slice($problems, 0, 3);
        @endphp

        <div class="neo-logcheck-card neo-logcheck-block" role="status">
            <div class="min-w-0">
                <p class="neo-logcheck-title">
                    {{ $problems !== []
                        ? trans_choice('plugged-host-ai::strings.log_check.problems', count($problems), ['count' => count($problems)])
                        : trans_choice('plugged-host-ai::strings.log_check.errors', (int) $result['errors'], ['count' => (int) $result['errors']]) }}
                </p>

                @if ($shown !== [])
                    <ul class="neo-logcheck-list">
                        @foreach ($shown as $problem)
                            <li class="neo-logcheck-problem">
                                {{ $problem['message'] }}
                                @if ($problem['solution'])
                                    <span class="neo-logcheck-fix">{{ trans('plugged-host-ai::strings.log_check.fix', ['fix' => $problem['solution']]) }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if (count($problems) > count($shown))
                        <p class="neo-logcheck-quiet">{{ trans('plugged-host-ai::strings.mclogs.more', ['count' => count($problems) - count($shown)]) }}</p>
                    @endif
                @else
                    <p class="neo-logcheck-quiet">{{ trans('plugged-host-ai::strings.log_check.errors_body') }}</p>
                @endif
            </div>

            <div class="neo-logcheck-actions">
                @if ($canAsk)
                    <x-filament::button
                        size="sm"
                        color="gray"
                        x-on:click="$dispatch('plugged-host-ai-ask', { log: '{{ $result['id'] }}' })"
                    >
                        {{ trans('plugged-host-ai::strings.mclogs.ask') }}
                    </x-filament::button>
                @endif

                <a href="{{ $result['url'] }}" target="_blank" rel="noopener" class="neo-logcheck-quiet neo-logcheck-link">
                    {{ trans('plugged-host-ai::strings.mclogs.view') }}
                </a>
            </div>
        </div>
    @elseif ($result)
        <p class="neo-logcheck-quiet neo-logcheck-block">
            {{ trans('plugged-host-ai::strings.log_check.clean') }}
            <a href="{{ $result['url'] }}" target="_blank" rel="noopener" class="neo-logcheck-link">{{ trans('plugged-host-ai::strings.log_check.open') }}</a>
        </p>
    @endif
</div>
