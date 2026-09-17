<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AvatarController extends Controller
{
    public function store(Request $request)
    {
        $file = $request->file('avatar');

        // FLAW: trusts the client-supplied filename, does not validate the
        // MIME type or extension, and writes to a publicly served disk.
        $name = $file->getClientOriginalName();
        Storage::disk('public')->put("avatars/{$name}", file_get_contents($file));

        return back()->with('status', 'Avatar updated.');
    }
}
