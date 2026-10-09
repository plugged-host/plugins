{{--
    The evidence behind one abuse finding (AbuseFindingResource's Evidence action): when it was
    seen, and what matched where. Plain rows, no boxes; the modal is the container.
--}}
@php
    /** @var \PluggedHost\Abuse\Models\AbuseFinding $finding */
    $evidence = $finding->evidence ?? [];
    $matches = $evidence['matches'] ?? [];
    $rest = array_diff_key($evidence, ['matches' => true]);
@endphp

<div class="flex flex-col gap-4 text-sm">
    <p class="text-gray-500 dark:text-gray-400">
        First seen {{ $finding->first_seen_at?->diffForHumans() ?? 'unknown' }},
        last seen {{ $finding->last_seen_at?->diffForHumans() ?? 'unknown' }},
        {{ $finding->hits }} {{ \Illuminate\Support\Str::plural('time', $finding->hits) }}.
        @if ($finding->resolver)
            {{ ucfirst($finding->status) }} by {{ $finding->resolver->username }} {{ $finding->resolved_at?->diffForHumans() }}.
        @endif
    </p>

    @if ($matches !== [])
        <ul class="flex flex-col gap-3">
            @foreach ($matches as $match)
                <li class="flex flex-col gap-1">
                    <span>{{ $match['label'] ?? '' }} <span class="text-gray-500 dark:text-gray-400">({{ $match['severity'] ?? '' }})</span></span>
                    <span class="font-mono break-all text-gray-500 dark:text-gray-400">{{ $match['where'] ?? '' }}</span>
                    @if (!empty($match['match']))
                        <span class="font-mono break-all">{{ $match['match'] }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($rest !== [])
        <pre class="font-mono text-xs whitespace-pre-wrap break-all">{{ json_encode($rest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
    @endif
</div>
