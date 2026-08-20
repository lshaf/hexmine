<!doctype html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport"
              content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
        <meta name="theme-color" content="#141b18">

        <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
        <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">

        {{-- Bitter carries the display and the gauge numerals: a slab serif
             reads as a stamped equipment plate, which is what the HUD is.
             Archivo handles UI text and the wide-tracked survey-map labels. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link
            href="https://fonts.bunny.net/css?family=archivo:400,500,600,700|bitter:500,600,700"
            rel="stylesheet"
        >
        <title>HexMine</title>
        @vite(['resources/css/app.css', 'resources/js/main.ts'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
