<?php

use Foodics\DbHound\Tests\IntegrationTestCase;
use Foodics\DbHound\Tests\TestCase;

uses(TestCase::class)->in('Unit');
uses(IntegrationTestCase::class)->group('integration')->in('Integration');
