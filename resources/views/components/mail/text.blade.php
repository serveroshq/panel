@props(['lead' => false, 'muted' => false, 'small' => false, 'gap' => 16])
@php
    [$size, $height] = $lead ? [19, 29] : ($small ? [14, 22] : [16, 26]);
    $color = $muted ? '#62625D' : ($lead ? '#151515' : '#3F3F3B');
@endphp
<p style="margin:0 0 {{ $gap }}px; font-size:{{ $size }}px; line-height:{{ $height }}px; color:{{ $color }};">{{ $slot }}</p>
