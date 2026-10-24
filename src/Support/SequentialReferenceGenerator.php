<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Support;

use BJTheCod3r\Docket\Contracts\ReferenceGenerator;
use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Report;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Formats like "DKT-{year}-{sequence:6}" backed by a per-year counter row,
 * so references are gapless and safe under concurrency.
 */
class SequentialReferenceGenerator implements ReferenceGenerator
{
    public function generate(Report $report): string
    {
        $format = (string) config('docket.reports.reference_format', 'DKT-{year}-{sequence:6}');
        $now = Carbon::now();

        $sequenceKey = 'reference:'.(Str::contains($format, '{year}') ? $now->format('Y') : 'all');
        $sequence = $this->next($sequenceKey, $report->getConnection());

        return (string) preg_replace_callback('/\{(\w+)(?::(\d+))?\}/', function (array $m) use ($now, $sequence, $report) {
            $pad = isset($m[2]) ? (int) $m[2] : 0;

            $value = match ($m[1]) {
                'year' => $now->format('Y'),
                'month' => $now->format('m'),
                'day' => $now->format('d'),
                'sequence' => (string) $sequence,
                'kind' => strtoupper(substr((string) $report->kind, 0, 3)),
                default => $m[0],
            };

            return $pad > 0 ? Str::padLeft($value, $pad, '0') : $value;
        }, $format);
    }

    /**
     * Locking a row that doesn't exist yet locks nothing, so the first report of a
     * period inserts the counter (ignoring a concurrent insert) and locks it again.
     */
    protected function next(string $key, ConnectionInterface $db): int
    {
        $table = Docket::table('sequences');

        return $db->transaction(function () use ($db, $table, $key) {
            $current = $db->table($table)->where('key', $key)->lockForUpdate()->value('value');

            if ($current === null) {
                $db->table($table)->insertOrIgnore(['key' => $key, 'value' => 0]);
                $current = $db->table($table)->where('key', $key)->lockForUpdate()->value('value');
            }

            $next = (int) $current + 1;
            $db->table($table)->where('key', $key)->update(['value' => $next]);

            return $next;
        });
    }
}
