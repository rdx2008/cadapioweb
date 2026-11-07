@php
$paths = [
    'home' => 'm3 10 9-7 9 7v10H15v-6H9v6H3Z',
    'orders' => 'M6 3h12v18H6ZM9 7h6M9 11h6M9 15h4',
    'menu' => 'M4 3v7m3-7v7M4 7h3m-1 3v11M15 3v18m0-18c7 4 7 10 0 10',
    'store' => 'M4 11v10h16V11M3 5h18l1 6H2ZM9 21v-7h6v7',
    'truck' => 'M2 5h12v12H2Zm12 5h5l3 4v3h-8M7 19a2 2 0 1 0-4 0 2 2 0 0 0 4 0Zm14 0a2 2 0 1 0-4 0 2 2 0 0 0 4 0Z',
    'chat' => 'M21 11a9 9 0 0 1-13 8l-5 2 1-6a9 9 0 1 1 17-4ZM8 8c0 4 3 7 7 7',
    'users' => 'M16 21v-3c0-3-12-3-12 0v3M14 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0Zm5 5c3 0 3 2 3 6M18 3c4 0 4 7 0 7',
    'money' => 'm9 3 1 4h4l1-4Zm1 4C1 16 5 22 12 22s11-6 2-15M14 12h-3c-3 0-3 4 0 4h2c3 0 3 4 0 4h-3m2-9v10',
    'chart' => 'M4 13h3v8H4Zm7-6h3v14h-3Zm7-5h3v19h-3Z',
    'chef' => 'M7 14a5 5 0 0 1-2-9 5 5 0 0 1 9-2 5 5 0 0 1 4 11v7H7Zm0 3h11M11 10v4m4-4v4',
    'link' => 'm10 13 4-4M8 16l-2 2a4 4 0 0 1-6-6l5-5a4 4 0 0 1 6 0m2 1 2-2a4 4 0 0 1 6 6l-5 5a4 4 0 0 1-6 0',
    'external' => 'M14 3h7v7m0-7L10 14M10 3H3v18h18v-7',
    'clock' => 'M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-6v6l4 2',
    'search' => 'M16 10a6 6 0 1 1-12 0 6 6 0 0 1 12 0Zm-2 4 7 7',
    'arrow' => 'm9 5 7 7-7 7',
    'check' => 'm5 12 4 4L19 6',
    'calendar' => 'M3 5h18v16H3ZM7 2v6m10-6v6M3 10h18',
];
@endphp
<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $paths[$name] ?? $paths['home'] }}"/></svg>
