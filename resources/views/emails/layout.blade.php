<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    {{-- Arka01 es oscuro por diseño (sección 9.9) — esto evita que Gmail/Apple
         Mail le apliquen su propio "modo oscuro" automático encima, que
         invierte colores y rompe el contraste ya pensado a propósito. --}}
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>@yield('title', config('app.name'))</title>
    <!--[if mso]>
    <noscript>
        <xml>
            <o:OfficeDocumentSettings>
                <o:PixelsPerInch>96</o:PixelsPerInch>
            </o:OfficeDocumentSettings>
        </xml>
    </noscript>
    <![endif]-->
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; }

        body, .arka-bg {
            background-color: #0a0f0c;
        }

        .arka-font {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }

        a.arka-link { color: #6ee7b7; }

        @media only screen and (max-width: 480px) {
            .arka-container { width: 100% !important; }
            .arka-px { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body class="arka-font arka-bg" style="margin:0; padding:0; background-color:#0a0f0c;">
    {{-- Texto de preencabezado: lo que se ve en la bandeja de entrada antes de
         abrir el correo, oculto en el cuerpo — sin esto, clientes de correo
         suelen mostrar el HTML crudo del arranque de la plantilla en su lugar. --}}
    @hasSection('preheader')
        <div style="display:none; max-height:0; overflow:hidden; mso-hide:all; font-size:1px; line-height:1px; color:#0a0f0c;">
            @yield('preheader')
        </div>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#0a0f0c" style="background-color:#0a0f0c;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" class="arka-container" width="480" cellpadding="0" cellspacing="0" border="0" style="width:480px; max-width:480px;">
                    {{-- Logotipo tipográfico de dos colores (mismo criterio que
                         Components/ApplicationLogo.vue): sin ícono genérico. --}}
                    <tr>
                        <td align="center" style="padding: 8px 0 28px 0;">
                            <span style="font-size:26px; font-weight:800; letter-spacing:-0.02em;">
                                <span style="color:#e7f4ee;">Arka</span><span style="color:#34d399;">01</span>
                            </span>
                        </td>
                    </tr>

                    {{-- Tarjeta principal --}}
                    <tr>
                        <td bgcolor="#121b17" class="arka-px" style="background-color:#121b17; border-radius:16px; padding: 36px 32px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                @yield('content')
                            </table>
                        </td>
                    </tr>

                    {{-- Pie --}}
                    <tr>
                        <td align="center" style="padding: 28px 16px 8px 16px;">
                            <p style="margin:0; font-size:12px; line-height:18px; color:#93ada2;">
                                <a href="{{ route('legal.terms') }}" class="arka-link" style="color:#6ee7b7; text-decoration:none;">Términos</a>
                                &nbsp;·&nbsp;
                                <a href="{{ route('legal.privacy') }}" class="arka-link" style="color:#6ee7b7; text-decoration:none;">Privacidad</a>
                            </p>
                            <p style="margin:12px 0 0 0; font-size:12px; line-height:18px; color:#5c7268;">
                                Recibiste este correo porque esta dirección se usó para crear una cuenta en {{ config('app.name') }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
