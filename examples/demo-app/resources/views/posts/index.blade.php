<x-layout>
    <h1>Posts</h1>

    @foreach ($posts as $post)
        <article>
            <h2>{{ $post->title }}</h2>

            {{-- FLAW: lazy-loads the author relation once per post. --}}
            <p>by {{ $post->author->name }}</p>

            {{-- FLAW: loads every comment row just to count them. --}}
            <p>{{ $post->comments->count() }} comments</p>

            {{-- FLAW: a third relation walked per row. --}}
            <ul>
                @foreach ($post->tags as $tag)
                    <li>{{ $tag->label }}</li>
                @endforeach
            </ul>
        </article>
    @endforeach
</x-layout>
