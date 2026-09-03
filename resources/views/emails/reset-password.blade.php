<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Restablecer contraseña — {{ $appName }}</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color:#f4f6f8;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="background:linear-gradient(135deg,#0f766e,#115e59);padding:28px 32px;text-align:left;">
                            <div style="font-size:22px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">{{ $appName }}</div>
                            <div style="margin-top:6px;font-size:13px;color:#ccfbf1;">Recuperación de acceso</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;">
                            <p style="margin:0 0 16px;font-size:16px;line-height:1.5;">
                                Hola <strong>{{ $name }}</strong>,
                            </p>
                            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">
                                Recibimos una solicitud para restablecer la contraseña de tu cuenta
                                <strong>{{ $email }}</strong> en {{ $appName }}.
                            </p>
                            <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#374151;">
                                Haz clic en el botón para elegir una nueva contraseña. Este enlace es válido por
                                <strong>{{ $expireMinutes }} minutos</strong> y dejará de funcionar en cuanto
                                actualices tu contraseña.
                            </p>

                            <table role="presentation" cellspacing="0" cellpadding="0" style="margin:0 0 28px;">
                                <tr>
                                    <td style="border-radius:8px;background:#0f766e;">
                                        <a href="{{ $url }}"
                                           style="display:inline-block;padding:14px 28px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;">
                                            Restablecer contraseña
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 8px;font-size:13px;line-height:1.5;color:#6b7280;">
                                Si el botón no funciona, copia y pega este enlace en tu navegador:
                            </p>
                            <p style="margin:0 0 24px;font-size:12px;line-height:1.5;word-break:break-all;color:#0f766e;">
                                {{ $url }}
                            </p>

                            <p style="margin:0;font-size:13px;line-height:1.6;color:#6b7280;">
                                Si tú no solicitaste este cambio, puedes ignorar este correo. Tu contraseña
                                actual permanecerá igual.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:20px 32px;background:#f9fafb;border-top:1px solid #e5e7eb;">
                            <p style="margin:0;font-size:12px;line-height:1.5;color:#9ca3af;text-align:center;">
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
