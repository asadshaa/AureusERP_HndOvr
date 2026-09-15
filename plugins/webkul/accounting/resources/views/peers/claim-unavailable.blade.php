{{--
    One neutral page for every failure: wrong token, expired, cancelled.
    Distinguishing them would let someone probe for valid tokens.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link no longer available</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f5f5f4; color: #1c1917;
               display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .card { background: #fff; border-radius: 12px; padding: 2.5rem; max-width: 30rem;
                box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); text-align: center; }
        h1 { font-size: 1.25rem; margin: 0 0 .75rem; }
        p { color: #57534e; line-height: 1.6; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>This link is no longer available</h1>
        <p>It may have expired or been withdrawn. Please contact the sender for a new one.</p>
    </div>
</body>
</html>
