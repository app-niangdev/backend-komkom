<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Réinitialisation du mot de passe</title>
    <style>
        body {
            margin: 0; padding: 0;
            background-color: #f4f4f7;
            font-family: Arial, sans-serif;
            color: #333;
        }
        .wrapper {
            max-width: 600px;
            margin: 40px auto;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .header {
            background-color: #4F46E5; /* ← change la couleur selon ta charte */
            padding: 32px;
            text-align: center;
        }
        .header h1 {
            color: #ffffff;
            margin: 0;
            font-size: 24px;
            letter-spacing: 1px;
        }
        .body {
            padding: 40px 48px;
        }
        .body p {
            font-size: 15px;
            line-height: 1.7;
            margin: 0 0 16px;
        }
        .btn-wrapper {
            text-align: center;
            margin: 32px 0;
        }
        .btn {
            display: inline-block;
            padding: 14px 32px;
            background-color: #4F46E5;
            color: #ffffff !important;
            text-decoration: none;
            border-radius: 6px;
            font-size: 15px;
            font-weight: bold;
        }
        .notice {
            font-size: 13px;
            color: #888;
            border-top: 1px solid #eee;
            padding-top: 16px;
            margin-top: 24px;
        }
        .footer {
            background-color: #f4f4f7;
            text-align: center;
            padding: 20px;
            font-size: 12px;
            color: #aaa;
        }
    </style>
</head>
<body>
    <div class="wrapper">

        {{-- Header --}}
        <div class="header">
            <h1>KomKom</h1>
        </div>

        {{-- Corps --}}
        <div class="body">
            <p>Bonjour <strong>{{ $user->first_name }}</strong>,</p>

            <p>
                Vous recevez cet email car nous avons reçu une demande de
                réinitialisation de mot de passe pour votre compte.
            </p>

            <div class="btn-wrapper">
                <a href="{{ $resetUrl }}" class="btn">
                    Réinitialiser mon mot de passe
                </a>
            </div>

            <p>Ce lien expirera dans <strong>60 minutes</strong>.</p>

            <p>
                Si vous n'êtes pas à l'origine de cette demande,
                aucune action n'est requise.
            </p>

            <p>Cordialement,<br><strong>L'équipe KomKom</strong></p>

            {{-- Fallback URL --}}
            <div class="notice">
                Si le bouton ne fonctionne pas, copiez et collez ce lien dans votre navigateur :<br>
                <a href="{{ $resetUrl }}">{{ $resetUrl }}</a>
            </div>
        </div>

        {{-- Footer --}}
        <div class="footer">
            &copy; {{ date('Y') }} KomKom. Tous droits réservés.
        </div>

    </div>
</body>
</html>
