<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->title }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917; margin: 0;
               display: flex; min-height: 100vh; align-items: center; justify-content: center; padding: 2rem 1rem; }
        .card { background: #fff; border-radius: 12px; padding: 2.5rem; max-width: 34rem; width: 100%;
                box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        h1 { font-size: 1.3rem; margin: 0 0 .4rem; }
        .muted { color: #78716c; font-size: .9rem; margin: .2rem 0; }
        dl { display: grid; grid-template-columns: auto 1fr; gap: .4rem 1rem; margin: 1.5rem 0; font-size: .9rem; }
        dt { color: #57534e; font-weight: 600; }
        dd { margin: 0; }
        a.btn { display: inline-block; padding: .7rem 1.2rem; border-radius: 8px; text-decoration: none;
                font-size: .95rem; font-weight: 600; background: #1c1917; color: #fff; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ $document->title }}</h1>
        @if ($document->description)
            <p class="muted">{{ $document->description }}</p>
        @endif

        <dl>
            <dt>File</dt><dd>{{ $version?->original_filename ?? '—' }}</dd>
            <dt>Type</dt><dd>{{ $version?->mime_type ?? '—' }}</dd>
            <dt>Size</dt>
            <dd>{{ $version ? number_format($version->file_size / 1024, 1).' KB' : '—' }}</dd>
        </dl>

        <a class="btn" href="{{ route('accounting.claim.file', ['token' => request()->route('token')]) }}">
            Download file
        </a>

        <p class="muted" style="margin-top:1.5rem">
            This link expires {{ optional($transmission->expires_at)->toDayDateTimeString() }}.
        </p>
    </div>
</body>
</html>
