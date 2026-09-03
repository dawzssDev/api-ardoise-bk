<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña — {{ $appName }}</title>
</head>
{{-- Paleta Ardoise: #FDFDFA · #E4E3E1 · #D7B794 · #1E2539 --}}
<body style="margin:0;padding:0;background-color:#E4E3E1;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1E2539;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#E4E3E1;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#FDFDFA;border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(30,37,57,0.14);">
                    {{-- Header oscuro estilo landing --}}
                    <tr>
                        <td style="background-color:#1E2539;padding:28px 32px;text-align:center;">
                            @php
                                $logoSrc = null;
                                if (isset($message) && is_object($message) && method_exists($message, 'embed') && ! empty($logoPath) && is_file($logoPath)) {
                                    $logoSrc = $message->embed($logoPath);
                                }
                            @endphp
                            @if ($logoSrc)
                                <img src="{{ $logoSrc }}"
                                     alt="{{ $appName }}"
                                     width="72"
                                     height="72"
                                     style="display:block;margin:0 auto 12px;border:0;outline:none;width:72px;height:72px;">
                            @endif
                            <div style="font-size:22px;font-weight:700;color:#D7B794;letter-spacing:2px;text-transform:uppercase;">
                                {{ $appName }}
                            </div>
                            <div style="margin-top:8px;font-size:13px;color:#E4E3E1;letter-spacing:0.2px;">
                                Recuperación de acceso
                            </div>
                            <div style="width:48px;height:2px;background:#D7B794;margin:14px auto 0;"></div>
                        </td>
                    </tr>

                    {{-- Cuerpo --}}
                    <tr>
                        <td style="padding:32px;background-color:#FDFDFA;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;color:#1E2539;">
                                Hola <strong>{{ $name }}</strong>,
                            </p>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#1E2539;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta
                                <strong style="color:#1E2539;">{{ $email }}</strong> en {{ $appName }}.
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#1E2539;">
                                Haz clic en el botón para elegir una nueva contraseña. Este enlace es válido por
                                <strong>{{ $expireMinutes }} minutos</strong> y dejará de funcionar en cuanto
                                actualices tu contraseña.
                            </p>

                            {{-- CTA principal --}}
                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 auto 28px;">
                                <tr>
                                    <td align="center" style="border-radius:8px;background-color:#1E2539;">
                                        <a href="{{ $url }}"
                                           target="_blank"
                                           rel="noopener noreferrer"
                                           style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#FDFDFA;text-decoration:none;border-radius:8px;">
                                            Restablecer contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            {{-- Fallback sin URL visible (sin token en texto plano) --}}
                            <p style="margin:0 0 24px;font-size:13px;line-height:1.6;color:#1E2539;text-align:center;">
                                Si el botón no funciona,
                                <a href="{{ $url }}"
                                   target="_blank"
                                   rel="noopener noreferrer"
                                   style="color:#1E2539;font-weight:700;text-decoration:underline;">
                                    Restablecer contraseña
                                </a>
                                desde este enlace.
                            </p>

                            <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Si tú no solicitaste este cambio, puedes ignorar este correo. Tu contraseña
                                actual permanecerá igual.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding:20px 32px;background-color:#E4E3E1;border-top:1px solid #D7B794;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#1E2539;text-align:center;">
                                Este mensaje fue enviado por {{ $appName }} · Soporte DAWZSS<br>
                                No respondas a este correo si no solicitaste recuperar tu acceso.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
