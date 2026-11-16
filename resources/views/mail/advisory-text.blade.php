{{ $greeting }}

@foreach ($blocks as $block)
@switch($block[0])
@case('h')

{{ strtoupper($block[1]) }}

@break
@case('list')
@foreach ($block[1] as $item)
- {{ $item }}
@endforeach

@break
@case('rows')
@foreach ($block[1] as $row)
- {{ $row[0] }}: {{ $row[1] }}
@endforeach

@break
@case('button')
{{ $block[1] }}: {{ $block[2] }}

@break
@default
{{ $block[1] }}

@endswitch
@endforeach
{{ $signature }}

@foreach ($footer as $line)
{{ $line }}
@endforeach
@isset($unsubscribe)
{{ $unsubscribe['label'] }}: {{ $unsubscribe['url'] }}
@endisset
