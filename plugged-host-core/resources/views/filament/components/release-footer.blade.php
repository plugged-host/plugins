{{--
    The release this panel runs (PluggedHost\Core\Support\Release), where Pelican's own footer was
    (CoreRuntimeServiceProvider hides that one). Muted, centred, one line: it is there to be read
    when someone reports a problem, not to draw the eye. A div, not a <footer>: the rule that hides
    Pelican's footer matches the element.
--}}
<div class="ph-release-footer">
    <style>
        .ph-release-footer {
            padding: 1rem;
            text-align: center;
            font-size: 0.75rem;
            line-height: 1rem;
            color: var(--phv2-text-faint, #71717a);
        }

        .ph-release-footer span {
            font-family: var(--mono-font-family, 'JetBrains Mono'), ui-monospace, monospace;
        }
    </style>
    {{ config('app.name', 'Pelican') }} <span title="{{ $release }}">{{ $release }}</span>
</div>
