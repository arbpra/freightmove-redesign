<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Australian suburb reference data, imported from the legacy platform. */
class Suburb extends Model
{
    protected $fillable = ['legacy_id', 'name', 'state'];

    /** "Kyle Bay, NSW" — the form freight jobs store as a location string. */
    public function label(): string
    {
        return "{$this->name}, {$this->state}";
    }
}
