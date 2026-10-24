<?php

declare(strict_types=1);

use BJTheCod3r\Docket\Tests\AltTestCase;
use BJTheCod3r\Docket\Tests\TestCase;

uses(TestCase::class)->in('Unit');
uses(TestCase::class)->in('Feature/*.php');
uses(AltTestCase::class)->in('Feature/Alt');
