<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Models;

use BJTheCod3r\Docket\Facades\Docket;
use BJTheCod3r\Docket\Models\Concerns\DocketModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $approver_type
 * @property string $approver_id
 * @property string $decision  approved | rejected
 * @property string|null $comment
 */
class ProposalApproval extends Model
{
    use DocketModel;

    public const UPDATED_AT = null;

    protected string $docketTable = 'proposal_approvals';

    protected $guarded = [];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** @return BelongsTo<Proposal, $this> */
    public function proposal(): BelongsTo
    {
        return $this->belongsTo(Docket::model('proposal'), 'proposal_id');
    }
}
