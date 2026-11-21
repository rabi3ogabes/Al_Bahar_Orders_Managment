<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Livewire\Livewire;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Livewire remembers "a component was rendered during this request" in static state. In one long test process a full-page dashboard request would leave
        // that on, and the next plain request (the storefront shell, a PDF) would get Livewire's scripts injected into its HTML. Every real request starts clean;
        // so does every test.
        Livewire::flushState();
    }
}
