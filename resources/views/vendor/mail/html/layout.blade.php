<x-mail.frame :title="config('app.name')">
<div class="so-md" style="padding-top:30px;">
{{ Illuminate\Mail\Markdown::parse($slot) }}
</div>
{{ $subcopy ?? '' }}
</x-mail.frame>
