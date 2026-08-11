<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Laravel 11+ ships a bare base controller. AuthorizesRequests is pulled in
 * here so controllers can call `$this->authorize(...)` against a policy —
 * used by FreightJobController for per-record ownership and lifecycle rules.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
