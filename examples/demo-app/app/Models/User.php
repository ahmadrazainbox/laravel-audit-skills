<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    protected $fillable = ['name', 'email', 'password', 'is_admin'];

    // FLAW: $hidden omits password and api_token, so both leak through
    // any JSON response that serialises a User.
    protected $hidden = [];

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
