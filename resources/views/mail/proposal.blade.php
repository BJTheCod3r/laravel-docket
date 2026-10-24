<x-mail::message>
# {{ $report->reference }}{{ $report->title ? ' — '.$report->title : '' }}

{{ $summary }}

@if($report->description)
<x-mail::panel>
{{ \Illuminate\Support\Str::limit($report->description, 1000) }}
</x-mail::panel>
@endif

@if(count($diff))
<x-mail::table>
| Field | From | To |
|:------|:-----|:---|
@foreach($diff as $field => $change)
| {{ $field }} | {{ is_scalar($change['from']) || $change['from'] === null ? var_export($change['from'], true) : json_encode($change['from']) }} | {{ is_scalar($change['to']) || $change['to'] === null ? var_export($change['to'], true) : json_encode($change['to']) }} |
@endforeach
</x-mail::table>
@endif

@if($url)
<x-mail::button :url="$url">
Review proposal
</x-mail::button>
@endif

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
