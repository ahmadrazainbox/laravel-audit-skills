<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index()
    {
        // FLAW: returns User models directly as JSON. With an empty $hidden
        // on the model this serialises password hashes and api tokens.
        return User::with('posts')->paginate(50);
    }

    public function update(Request $request, int $id)
    {
        // FLAW: no authorize(), no policy, no gate. Any authenticated user
        // who can reach this route can edit any other user.
        $user = User::findOrFail($id);
        $user->update($request->all());

        return $user;
    }

    public function destroy(int $id)
    {
        // FLAW: same - destructive action with no authorization check.
        User::destroy($id);

        return response()->noContent();
    }
}
