<x-mail::message>
# {{ $report->reference }}{{ $report->title ? ' — '.$report->title : '' }}

{{ $summary }}

@if($bypassedMute ?? null)
> Sent despite an active mute{{ $bypassedMute['reason'] ? ' ('.$bypassedMute['reason'].')' : '' }} because of its priority.
@endif

@if($report->description)
<x-mail::panel>
{{ \Illuminate\Support\Str::limit($report->description, 1000) }}
</x-mail::panel>
@endif

**Status:** {{ \BJTheCod3r\Docket\Facades\Docket::statuses()->find($report->status)?->label ?? $report->status }}
@if($report->priority !== null)
**Priority:** P{{ $report->priority }}
@endif

@if($url)
<x-mail::button :url="$url">
View report
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
