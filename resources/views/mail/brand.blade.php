@php
    $mono = "'JetBrains Mono',SFMono-Regular,Menlo,Consolas,monospace";
    $dots = ['good' => '#30A46C', 'warn' => '#E5A000', 'bad' => '#E5484D', 'neutral' => '#9A9A94'];
    $tones = ['good' => '#18794E', 'warn' => '#AD5700', 'bad' => '#CE2C31', 'mono' => '#62625D'];
    $mark = ['down' => ['#E5484D', 'Alert'], 'up' => ['#30A46C', 'Resolved']][$image ?? ''] ?? null;
@endphp
<x-mail.frame :title="$heading" :preview="$paragraphs[0] ?? null" :brand="$brand" :footer="$footer" :unsubscribe="$unsubscribe ?? null">
    @if ($mark)
        <p style="margin:30px 0 0; font-size:13px; line-height:18px; font-weight:600; color:#62625D;"><span style="display:inline-block; width:7px; height:7px; border-radius:4px; background-color:{{ $mark[0] }}; margin-right:7px; vertical-align:1px;"></span>{{ $mark[1] }}</p>
        <h1 class="so-h" style="margin:10px 0 24px; font-size:32px; line-height:38px; font-weight:700; letter-spacing:-0.03em; color:#151515;">{{ $heading }}</h1>
    @else
        <x-mail.heading>{{ $heading }}</x-mail.heading>
    @endif

    @foreach ($paragraphs as $paragraph)
        <x-mail.text :lead="$loop->first">{!! nl2br(e($paragraph), false) !!}</x-mail.text>
    @endforeach

    @foreach ($sections as $section)
        <x-mail.block>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                @if (! empty($section['title']))
                    <tr>
                        <td colspan="3" style="padding:12px 0 4px; font-size:11px; line-height:16px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#8A8A84;">{{ $section['title'] }}</td>
                    </tr>
                @endif
                @foreach ($section['rows'] as $row)
                    <tr>
                        <td width="16" style="width:16px; padding:11px 0; vertical-align:middle; {{ $loop->first && empty($section['title']) ? '' : 'border-top:1px solid #E6E6E1;' }}">
                            @if (! empty($row['dot']))
                                <div style="width:7px; height:7px; border-radius:4px; font-size:1px; line-height:1px; background-color:{{ $dots[$row['dot']] ?? $dots['neutral'] }};">&nbsp;</div>
                            @endif
                        </td>
                        <td style="padding:11px 0; vertical-align:middle; font-size:15px; line-height:22px; {{ ($row['muted'] ?? false) ? 'color:#62625D;' : 'color:#151515; font-weight:500;' }} {{ $loop->first && empty($section['title']) ? '' : 'border-top:1px solid #E6E6E1;' }}">{{ $row['name'] }}</td>
                        <td align="right" style="padding:11px 0 11px 12px; vertical-align:middle; font-size:{{ ($row['tone'] ?? null) === 'mono' ? '13px' : '15px' }}; line-height:22px; font-family:{!! ($row['tone'] ?? null) === 'mono' ? $mono : 'inherit' !!}; color:{{ $tones[$row['tone'] ?? ''] ?? '#151515' }}; {{ in_array($row['tone'] ?? null, ['good', 'warn', 'bad'], true) ? 'font-weight:600;' : '' }} {{ $loop->first && empty($section['title']) ? '' : 'border-top:1px solid #E6E6E1;' }}">{{ filled($row['value'] ?? null) ? $row['value'] : '' }}</td>
                    </tr>
                @endforeach
                @if (! empty($section['more']))
                    <tr>
                        <td colspan="3" style="padding:10px 0 12px 16px; border-top:1px solid #E6E6E1; font-size:14px; line-height:20px; color:#62625D;">{{ $section['more'] }}</td>
                    </tr>
                @endif
            </table>
        </x-mail.block>
    @endforeach

    @foreach ($after as $paragraph)
        <x-mail.text>{!! nl2br(e($paragraph), false) !!}</x-mail.text>
    @endforeach

    @if ($button)
        <x-mail.button :url="$button['url']">{{ $button['label'] }}</x-mail.button>
    @endif

    @if ($link ?? null)
        <x-mail.text small><a href="{{ $link['url'] }}" style="color:#1621D1; text-decoration:underline;">{{ $link['label'] }}</a></x-mail.text>
    @endif

    @if ($note)
        <x-mail.text small muted>{{ $note }}</x-mail.text>
    @endif

    @if ($signature)
        <x-mail.text gap="0"><span style="font-weight:600; color:#151515;">{{ $signature }}</span></x-mail.text>
    @endif
</x-mail.frame>
