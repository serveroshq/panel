@props(['title' => config('app.name'), 'preview' => null, 'brand' => null, 'footer' => [], 'unsubscribe' => null])
@php
    $font = "'Mona Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    $grey = '#8A8A84';
    $socials = $brand ? [] : (config('serveros.mail_socials') ?: []);
    $links = $brand ? [] : (config('serveros.mail_links') ?: [[\App\Support\Brand::website(), config('app.url')]]);
    $company = \App\Support\Brand::signOff();
    $address = \App\Support\Brand::address();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title }}</title>
    <style>
        :root { color-scheme: light; supported-color-schemes: light; }
        @media (max-width: 620px) {
            .so-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .so-h { font-size: 28px !important; line-height: 34px !important; }
        }
    </style>
</head>
<body style="margin:0; padding:0; background-color:#FFFFFF;">
    @if (filled($preview))
        <div style="display:none; max-height:0; overflow:hidden; mso-hide:all;">{{ $preview }}&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#FFFFFF" style="background-color:#FFFFFF;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; font-family:{!! $font !!};">
                    <tr>
                        <td class="so-pad" style="padding:36px 32px 0;">
                            @if ($brand)
                                <span style="font-size:18px; line-height:22px; font-weight:600; letter-spacing:-0.02em; color:#151515;">{{ $brand }}</span>
                            @else
                                <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="padding-right:8px; vertical-align:middle;"><img src="{{ asset('images/email/logo-ink.png') }}" width="18" height="20" alt="" style="display:block; border:0;"></td>
                                        <td style="vertical-align:middle; font-size:18px; line-height:22px; font-weight:600; letter-spacing:-0.02em; color:#151515;">{{ config('app.name') }}</td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="so-pad" style="padding:0 32px;">
                            {{ $slot }}
                        </td>
                    </tr>
                    @unless ($brand)
                        <tr>
                            <td class="so-pad" style="padding:48px 32px 0;">
                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                    <tr>
                                        <td style="vertical-align:middle;">
                                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                                <tr>
                                                    <td style="padding-right:7px; vertical-align:middle;"><img src="{{ asset('images/email/logo-ink.png') }}" width="15" height="17" alt="" style="display:block; border:0;"></td>
                                                    <td style="vertical-align:middle; font-size:15px; line-height:20px; font-weight:600; letter-spacing:-0.02em; color:#151515;">{{ config('app.name') }}</td>
                                                </tr>
                                            </table>
                                        </td>
                                        @if ($socials !== [])
                                            <td align="right" style="vertical-align:middle;">
                                                @foreach ($socials as [$icon, $label, $url])
                                                    <a href="{{ $url }}" style="display:inline-block; margin-left:12px; text-decoration:none;"><img src="{{ asset('images/email/social-'.$icon.'.png') }}" width="22" height="22" alt="{{ $label }}" style="display:inline-block; border:0;"></a>
                                                @endforeach
                                            </td>
                                        @endif
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    @endunless
                    <tr>
                        <td class="so-pad" style="padding:{{ $brand ? '40px' : '24px' }} 32px 48px; font-size:12px; line-height:18px; color:{{ $grey }};">
                            @if ($links !== [])
                                <p style="margin:0 0 14px;">
                                    @foreach ($links as [$label, $url])
                                        @unless ($loop->first)&nbsp;&nbsp;&nbsp;|&nbsp;&nbsp;&nbsp;@endunless<a href="{{ $url }}" style="color:{{ $grey }}; text-decoration:underline;">{{ $label }}</a>
                                    @endforeach
                                </p>
                            @endif
                            @foreach ((array) $footer as $line)
                                <p style="margin:0 0 6px;">{{ $line }}</p>
                            @endforeach
                            @if ($unsubscribe)
                                <p style="margin:0 0 6px;"><a href="{{ $unsubscribe['url'] }}" style="color:{{ $grey }}; text-decoration:underline;">{{ $unsubscribe['label'] }}</a></p>
                            @endif
                            @unless ($brand)
                                <p style="margin:0;">&copy; {{ now()->year }} {{ $company }}{{ $address !== null ? ' · '.$address : '' }}</p>
                            @endunless
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
