<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
        body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; background: #f4f4f5; margin: 0; padding: 24px; color: #18181b; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; border: 1px solid #e4e4e7; }
        .header { background: #16a34a; color: #ffffff; padding: 16px 24px; font-weight: 600; font-size: 16px; }
        .body { padding: 24px; line-height: 1.6; }
        .body p { margin: 0 0 12px 0; }
        .footer { padding: 16px 24px; font-size: 12px; color: #71717a; border-top: 1px solid #e4e4e7; background: #fafafa; }
        a { color: #16a34a; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">Nawasara</div>
        <div class="body">
            {!! $htmlBody !!}
        </div>
        <div class="footer">
            Email otomatis dari Nawasara. Jangan reply ke alamat ini.
        </div>
    </div>
</body>
</html>
