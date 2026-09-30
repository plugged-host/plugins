{{--
    Replaces Laravel's errors::minimal, which its own 402/419/429 views extend (Pelican ships no view
    for those codes). Their sections become the shared layout's code, title and subtitle; sections
    set inline arrive HTML-escaped, so they are decoded before the layout escapes them again.
--}}
@include('errors::layout', [
    'code' => trim($__env->yieldContent('code')),
    'title' => html_entity_decode(trim($__env->yieldContent('title')), ENT_QUOTES | ENT_HTML5),
    'subtitle' => html_entity_decode(trim($__env->yieldContent('message')), ENT_QUOTES | ENT_HTML5),
])
