<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PostController extends Controller
{
    public function index()
    {
        // FLAW: Post::all() with no eager loading. The view then walks
        // $post->author, $post->comments and $post->tags for every row, so this
        // is 1 + 3N queries and it grows with the table.
        $posts = Post::all();

        return view('posts.index', ['posts' => $posts]);
    }

    public function show(int $id)
    {
        $post = Post::findOrFail($id);

        // FLAW: a query inside a loop. One SELECT per comment.
        $authors = [];
        foreach ($post->comments as $comment) {
            $authors[] = DB::table('users')->where('id', $comment->user_id)->first();
        }

        return view('posts.show', compact('post', 'authors'));
    }

    public function search(Request $request)
    {
        $term = $request->input('q');

        // FLAW: user input interpolated straight into raw SQL.
        return Post::whereRaw("title LIKE '%{$term}%'")
            ->orderByRaw($request->input('sort', 'created_at DESC'))
            ->get();
    }

    public function store(Request $request)
    {
        // FLAW: $request->all() into create() on a model with $guarded = [].
        // A crafted request can set user_id, is_featured, anything.
        $post = Post::create($request->all());

        return redirect()->route('posts.show', $post);
    }

    public function popular()
    {
        // FLAW: counts the relation in PHP after loading every child row.
        // withCount('comments') does this in SQL without hydrating models.
        $posts = Post::with('comments')->get();

        return $posts->sortByDesc(fn (Post $post) => $post->comments->count())->take(10);
    }
}
