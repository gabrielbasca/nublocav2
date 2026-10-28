<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#176b5b">
    <title>Contact the driver · NuBloca</title>
    <style>
        :root {
            color-scheme: light;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: #17231f;
            background: #f7f8f5;
        }

        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        main { width: min(100%, 520px); }
        .brand { display: flex; justify-content: center; align-items: center; gap: 10px; margin-bottom: 22px; color: #176b5b; font-weight: 750; letter-spacing: .04em; }
        .brand-mark { display: grid; place-items: center; width: 42px; height: 42px; border-radius: 14px; background: #d7eee5; font-size: 23px; }
        .card { padding: clamp(24px, 6vw, 40px); border: 1px solid #e5e9e4; border-radius: 26px; background: #fff; box-shadow: 0 18px 50px #17372b0d; }
        .eyebrow { margin: 0 0 8px; color: #176b5b; font-size: .78rem; font-weight: 750; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 0; font-size: clamp(1.7rem, 7vw, 2.3rem); line-height: 1.15; letter-spacing: -.04em; }
        .intro { margin: 12px 0 26px; color: #5c6862; line-height: 1.6; }
        fieldset { display: grid; gap: 10px; padding: 0; border: 0; }
        legend { margin-bottom: 12px; font-weight: 700; }
        .option { display: flex; align-items: flex-start; gap: 12px; padding: 15px; border: 1px solid #e5e9e4; border-radius: 15px; cursor: pointer; line-height: 1.45; }
        .option:has(input:checked) { border-color: #176b5b; background: #f2faf6; }
        .option input { width: 18px; height: 18px; margin: 1px 0 0; accent-color: #176b5b; flex: 0 0 auto; }
        button { width: 100%; min-height: 54px; margin-top: 20px; border: 0; border-radius: 16px; background: #176b5b; color: #fff; font: inherit; font-weight: 700; cursor: pointer; }
        button:hover { background: #105649; }
        .notice { margin: 0 0 18px; padding: 14px 16px; border-radius: 14px; background: #e9f6ed; color: #185c35; line-height: 1.5; }
        .error { margin: 0 0 18px; padding: 14px 16px; border-radius: 14px; background: #fff0ef; color: #8c2824; line-height: 1.5; }
        .privacy { margin: 20px 4px 0; color: #68746d; font-size: .85rem; line-height: 1.55; text-align: center; }
        .honeypot { position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden; }
        @media (prefers-reduced-motion: no-preference) { button { transition: background .15s ease; } }
    </style>
</head>
<body>
<main>
    <div class="brand"><span class="brand-mark" aria-hidden="true">⌑</span><span>NuBloca</span></div>
    <section class="card" aria-labelledby="page-title">
        @if (session('status'))
            <p class="notice" role="status">{{ session('status') }}</p>
        @endif

        @if ($errors->any())
            <div class="error" role="alert">{{ $errors->first() }}</div>
        @endif

        <p class="eyebrow">A quick, private note</p>
        <h1 id="page-title">Contact the driver</h1>
        <p class="intro">Choose a message to let the vehicle owner know. Your note is sent without sharing anyone’s personal contact details.</p>

        @if ($templates->isEmpty())
            <p class="notice">Messaging is temporarily unavailable. Please try again later.</p>
        @else
            <form method="post" action="{{ route('vehicles.public.contact', ['publicToken' => $vehicle->public_token]) }}">
                @csrf
                <input type="hidden" name="submission_token" value="{{ $submissionToken }}">
                <div class="honeypot" aria-hidden="true">
                    <label for="company">Leave this field empty</label>
                    <input id="company" name="company" type="text" tabindex="-1" autocomplete="off">
                </div>
                <fieldset>
                    <legend>Choose a message</legend>
                    @foreach ($templates as $template)
                        <label class="option">
                            <input
                                type="radio"
                                name="message_template_id"
                                value="{{ $template->id }}"
                                @checked((string) old('message_template_id') === (string) $template->id)
                                required
                            >
                            <span>{{ $template->body }}</span>
                        </label>
                    @endforeach
                </fieldset>
                <button type="submit">Send private message</button>
            </form>
        @endif
    </section>
    <p class="privacy">Messages use predefined text only. NuBloca does not show the vehicle owner’s name, phone number, or email address.</p>
</main>
</body>
</html>
