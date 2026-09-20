{{-- Social Bar and Popunder draw outside the ad slot, so unlike the banner and
     native units they cannot be sandboxed and still work: both need the top
     document. data-navigate-once is what stops a wire:navigate swap from
     re-running them and stacking a second widget or a second popunder on top of
     the first — Livewire skips a marked script whose exact copy was already in
     the outgoing body. --}}
@foreach ($scripts as $format => $url)
    <script data-plugged-host="adsterra-{{ $format }}"
            data-navigate-once
            data-cfasync="false"
            src="{{ $url }}"></script>
@endforeach
