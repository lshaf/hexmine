<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        {{-- Zoom stays allowed, as on the almanac: this is a bench of dense
             controls and readouts, not a map that owns the viewport. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <meta name="theme-color" content="#141b18">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=archivo:400,500,600,700|bitter:500,600,700"
            rel="stylesheet"
        >
        <title>Battle bench — HexMine</title>
        @vite(['resources/css/app.css', 'resources/js/battle.ts'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
