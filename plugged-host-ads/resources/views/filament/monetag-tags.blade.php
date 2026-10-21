{{-- Monetag's loader, as it issues it, rebuilt from the validated zone and script URL
     (MonetagService). data-navigate-once: a wire:navigate swap does not run it again when the
     next page carries the same tag, so a format never loads twice in one tab. --}}
@foreach ($tags as $format => $tag)
    <script data-plugged-host="monetag-{{ $format }}" data-navigate-once data-cfasync="false">(function(s){s.dataset.zone={{ \Illuminate\Support\Js::from($tag['zone']) }},s.src={{ \Illuminate\Support\Js::from($tag['src']) }}})([document.documentElement, document.body].filter(Boolean).pop().appendChild(document.createElement('script')))</script>
@endforeach
