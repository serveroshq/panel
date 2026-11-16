@php
    $font = "'Mona Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Helvetica,Arial,sans-serif";
    $mono = "'Geist Mono',SFMono-Regular,Menlo,Consolas,monospace";
    $tones = ['good' => '#6EE7B7', 'warn' => '#FCD34D', 'bad' => '#FCA5A5', 'mono' => '#B4B4BE'];
    $dots = ['good' => '#34D399', 'warn' => '#FBBF24', 'bad' => '#F87171'];
    $fill = fn (string $hex) => "background-color:{$hex}; background-image:linear-gradient({$hex},{$hex});";
    $text = fn (string $html) => '<div class="g-screen"><div class="g-diff">'.$html.'</div></div>';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $subject }}</title>
    <style>
        :root { color-scheme: dark; supported-color-schemes: dark; }
        u + .body .g-screen { background: #000; mix-blend-mode: screen; }
        u + .body .g-diff { background: #000; mix-blend-mode: difference; }
        @media (max-width: 620px) {
            .so-pad { padding-left: 24px !important; padding-right: 24px !important; }
            .so-row .so-name, .so-row .so-value { display: block !important; text-align: left !important; }
            .so-row .so-value { padding-top: 0 !important; padding-left: 4px !important; }
        }
    </style>
</head>
<body class="body" style="margin:0; padding:0; {{ $fill('#121218') }}">
    <u></u>
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#121218" style="{{ $fill('#121218') }}">
        <tr>
            <td align="center" style="padding:36px 12px 40px; {{ $fill('#121218') }}">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:600px; font-family:{!! $font !!};">

                    <tr>
                        <td style="{{ $fill('#26262E') }} border-radius:11px; padding:1px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#16161D') }} border-radius:10px;">
                                <tr>
                                    <td style="padding:22px 24px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="padding-right:8px; vertical-align:middle;"><img src="{{ $logoUrl }}" width="20" height="22" alt="" style="display:block; border:0;"></td>
                                                <td style="vertical-align:middle; font-size:17px; line-height:22px; font-weight:600; letter-spacing:-0.01em; color:#E4E4E9;">{!! $text('ServerOS') !!}</td>
                                            </tr>
                                        </table>
                                    </td>
                                    <td align="right" style="padding:22px 24px; font-size:11px; line-height:16px; font-weight:500; letter-spacing:0.08em; text-transform:uppercase; color:#FCA5A5;">{!! $text(e($label)) !!}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td class="so-pad" style="padding:32px 40px 0; font-size:15px; line-height:24px; color:#E4E4E9;">{!! $text(e($greeting)) !!}</td>
                    </tr>

                    @foreach ($blocks as $block)
                        @switch($block[0])
                            @case('p')
                                <tr>
                                    <td class="so-pad" style="padding:14px 40px 0; font-size:15px; line-height:24px; color:#B4B4BE;">{!! $text(nl2br(e($block[1]), false)) !!}</td>
                                </tr>
                                @break

                            @case('strong')
                                <tr>
                                    <td class="so-pad" style="padding:16px 40px 0; font-size:15px; line-height:24px; font-weight:600; color:#E4E4E9;">{!! $text(e($block[1])) !!}</td>
                                </tr>
                                @break

                            @case('h')
                                <tr>
                                    <td class="so-pad" style="padding:30px 40px 0; font-size:20px; line-height:28px; font-weight:600; letter-spacing:-0.01em; color:#E4E4E9;">{!! $text(e($block[1])) !!}</td>
                                </tr>
                                @break

                            @case('list')
                                <tr>
                                    <td class="so-pad" style="padding:10px 40px 0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                            @foreach ($block[1] as $item)
                                                <tr>
                                                    <td width="18" style="width:18px; padding:4px 0; vertical-align:top; font-size:15px; line-height:24px; color:#6E6E7A;">{!! $text('&bull;') !!}</td>
                                                    <td style="padding:4px 0; font-size:15px; line-height:24px; color:#B4B4BE;">{!! $text(e($item)) !!}</td>
                                                </tr>
                                            @endforeach
                                        </table>
                                    </td>
                                </tr>
                                @break

                            @case('rows')
                                <tr>
                                    <td class="so-pad" style="padding:14px 40px 0;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#26262E') }} border-radius:11px;">
                                            <tr>
                                                <td style="padding:1px;">
                                                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#16161D') }} border-radius:10px;">
                                                        @foreach ($block[1] as $row)
                                                            @php($tone = $row[2] ?? null)
                                                            @unless ($loop->first)
                                                                <tr><td colspan="3" style="padding:0 16px;"><div style="height:1px; line-height:1px; font-size:1px; {{ $fill('#22222A') }}">&nbsp;</div></td></tr>
                                                            @endunless
                                                            <tr class="so-row">
                                                                <td width="16" style="width:16px; padding:18px 0 11px 16px; vertical-align:top;">
                                                                    @isset($dots[$tone])
                                                                        <div style="width:6px; height:6px; border-radius:3px; font-size:1px; line-height:1px; {{ $fill($dots[$tone]) }}">&nbsp;</div>
                                                                    @endisset
                                                                </td>
                                                                <td class="so-name" style="padding:11px 0 11px 4px; vertical-align:middle; font-size:14px; line-height:20px; font-weight:500; color:#E4E4E9;">{!! $text(e($row[0])) !!}</td>
                                                                <td align="right" class="so-value" style="padding:11px 16px 11px 12px; vertical-align:middle; font-size:{{ $tone === 'mono' ? '13px' : '14px' }}; line-height:20px; font-family:{!! $tone === 'mono' ? $mono : $font !!}; color:{{ $tones[$tone] ?? '#B4B4BE' }}; {{ in_array($tone, ['good', 'warn', 'bad'], true) ? 'font-weight:500;' : '' }}">{!! $text(e($row[1])) !!}</td>
                                                            </tr>
                                                        @endforeach
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @break

                            @case('button')
                                <tr>
                                    <td class="so-pad" style="padding:22px 40px 0;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#2F68DF') }} border-radius:9px;">
                                            <tr>
                                                <td style="padding:1px;">
                                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="{{ $fill('#1C4BB0') }} border-radius:8px;">
                                                        <tr>
                                                            <td><a href="{{ $block[2] }}" style="display:inline-block; padding:11px 22px; font-size:14px; line-height:18px; font-weight:500; color:#FFFFFF; text-decoration:none;">{!! $text(e($block[1])) !!}</a></td>
                                                        </tr>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                @break
                        @endswitch
                    @endforeach

                    <tr>
                        <td class="so-pad" style="padding:30px 40px 0; font-size:15px; line-height:24px; color:#E4E4E9;">{!! $text(nl2br(e($signature), false)) !!}</td>
                    </tr>

                    <tr>
                        <td class="so-pad" style="padding:36px 40px 0;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td align="center" style="border-top:2px dotted #2A2A33; padding-top:28px; font-size:12px; line-height:18px; color:#5E5E6A;">
                                        {!! $text('ServerOS Cloud Ltd &middot; <a href="'.e(config('app.url')).'" style="color:#5E5E6A; text-decoration:none;">serveros.com</a>') !!}
                                        @foreach ($footer as $line)
                                            <div style="height:12px; line-height:12px; font-size:1px;">&nbsp;</div>
                                            {!! $text(e($line)) !!}
                                        @endforeach
                                        @isset($unsubscribe)
                                            <div style="height:12px; line-height:12px; font-size:1px;">&nbsp;</div>
                                            {!! $text('<a href="'.e($unsubscribe['url']).'" style="color:#7A7A86; text-decoration:underline;">'.e($unsubscribe['label']).'</a>') !!}
                                        @endisset
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
