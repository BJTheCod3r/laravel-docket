<?php

declare(strict_types=1);

namespace BJTheCod3r\Docket\Tests\Fixtures;

use BJTheCod3r\Docket\Concerns\ReceivesReports;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

class Team extends Model
{
    use Notifiable;
    use ReceivesReports;

    protected $guarded = [];
}
