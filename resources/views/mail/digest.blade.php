<x-mail::message>
# {{ count($entries) }} update{{ count($entries) === 1 ? '' : 's' }} while you were away

<x-mail::table>
| Report | Event | Status |
|:-------|:------|:-------|
@foreach($entries as $entry)
| {{ $entry['reference'] ?? $entry['report_id'] }}{{ !empty($entry['title']) ? ' — '.$entry['title'] : '' }} | {{ $entry['event_key'] }} | {{ $entry['status'] ?? '' }} |
@endforeach
</x-mail::table>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
