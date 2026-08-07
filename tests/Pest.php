<?php

declare(strict_types=1);

use GraystackIT\MollieBilling\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

// tests/Browser deliberately gets no uses(): the Dusk server boots its app by
// instantiating the ORIGINATING TEST CLASS by name (see testbench-dusk's
// server.php), and Pest's evaluated closure classes ("P\Tests\…") are not
// autoloadable in that second process. Browser tests are therefore written as
// plain, autoloadable PHPUnit classes extending BrowserTestCase.
