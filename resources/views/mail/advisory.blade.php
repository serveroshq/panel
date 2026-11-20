@php
    $mono = "'JetBrains Mono',SFMono-Regular,Menlo,Consolas,monospace";
    $tones = ['good' => '#18794E', 'warn' => '#AD5700', 'bad' => '#CE2C31', 'mono' => '#62625D'];
    $dots = ['good' => '#30A46C', 'warn' => '#E5A000', 'bad' => '#E5484D'];
@endphp
<x-mail.frame :title="$subject" :preview="$label" :footer="$footer" :unsubscribe="$unsubscribe ?? null">
    <p style="margin:30px 0 0; font-size:12px; line-height:16px; font-weight:600; letter-spacing:0.08em; text-transform:uppercase; color:#CE2C31;">{{ $label }}</p>
    <h1 class="so-h" style="margin:10px 0 24px; font-size:32px; line-height:38px; font-weight:700; letter-spacing:-0.03em; color:#151515;">{{ $subject }}</h1>

    <x-mail.text lead>{{ $greeting }}</x-mail.text>

    @foreach ($blocks as $block)
        @switch($block[0])
            @case('p')
                <x-mail.text>{!! nl2br(e($block[1]), false) !!}</x-mail.text>
                @break

            @case('strong')
                <x-mail.text><span style="font-weight:600; color:#151515;">{{ $block[1] }}</span></x-mail.text>
                @break

            @case('h')
                <h2 style="margin:32px 0 12px; font-size:20px; line-height:28px; font-weight:700; letter-spacing:-0.01em; color:#151515;">{{ $block[1] }}</h2>
                @break

            @case('list')
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;">
                    @foreach ($block[1] as $item)
                        <tr>
                            <td width="18" style="width:18px; padding:2px 0; vertical-align:top; font-size:16px; line-height:26px; color:#8A8A84;">&bull;</td>
                            <td style="padding:2px 0; font-size:16px; line-height:26px; color:#3F3F3B;">{{ $item }}</td>
                        </tr>
                    @endforeach
                </table>
                @break

            @case('rows')
                <x-mail.block>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                        @foreach ($block[1] as $row)
                            @php($tone = $row[2] ?? null)
                            @php($line = $loop->first ? '' : 'border-top:1px solid #E6E6E1;')
                            <tr>
                                <td width="16" style="width:16px; padding:11px 0; vertical-align:middle; {{ $line }}">
                                    @isset($dots[$tone])
                                        <div style="width:7px; height:7px; border-radius:4px; font-size:1px; line-height:1px; background-color:{{ $dots[$tone] }};">&nbsp;</div>
                                    @endisset
                                </td>
                                <td style="padding:11px 0; vertical-align:middle; font-size:15px; line-height:22px; font-weight:500; color:#151515; {{ $line }}">{{ $row[0] }}</td>
                                <td align="right" style="padding:11px 0 11px 12px; vertical-align:middle; font-size:{{ $tone === 'mono' ? '13px' : '15px' }}; line-height:22px; {{ $tone === 'mono' ? 'font-family:'.$mono.';' : '' }} color:{{ $tones[$tone] ?? '#151515' }}; {{ in_array($tone, ['good', 'warn', 'bad'], true) ? 'font-weight:600;' : '' }} {{ $line }}">{{ $row[1] }}</td>
                            </tr>
                        @endforeach
                    </table>
                </x-mail.block>
                @break

            @case('button')
                <x-mail.button :url="$block[2]">{{ $block[1] }}</x-mail.button>
                @break
        @endswitch
    @endforeach

    <x-mail.text gap="0"><span style="color:#151515;">{!! nl2br(e($signature), false) !!}</span></x-mail.text>
</x-mail.frame>
