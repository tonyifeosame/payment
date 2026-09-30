{{-- Shared styling for every web page: the compiled FEYRA stylesheet (Tailwind CSS
     v3.4.17 plus the brand palette, components and the self-hosted Inter / Plus Jakarta
     Sans fonts). Built from resources/css/app.css — see the README, "Building the CSS".
     Included by the marketing and admin layouts and by the standalone payment and
     receipt pages, so there is one source of truth. The ?v= is a hash of the file's
     contents, so a rebuilt stylesheet is never served from a stale browser cache. --}}
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ substr(md5_file(public_path('css/app.css')), 0, 12) }}">
