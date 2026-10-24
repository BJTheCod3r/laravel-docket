<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Concerns\HasAttachments;
use BJTheCod3r\Docket\Concerns\HasProposals;
use BJTheCod3r\Docket\Contracts\Proposable;
use Illuminate\Database\Eloquent\Model;

class Artist extends Model implements Proposable
{
    use HasAttachments;
    use HasProposals;

    protected $guarded = [];

    /** @var array<int, string> */
    protected array $proposable = ['name'];

    /** @var array<string, array<string, mixed>> */
    protected array $proposableFiles = [
        'artwork' => ['multiple' => true, 'max' => 3, 'mimes' => ['image/*'], 'max_kb' => 10240],
    ];
}
