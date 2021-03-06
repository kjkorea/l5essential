<?php

namespace App\Http\Controllers;

use App\Article;
use App\Events\ArticleConsumed;
use App\Events\ModelChanged;
use App\Http\Requests\ArticlesRequest;
use App\Http\Requests\FilterArticlesRequest;
use App\Tag;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class ArticlesController extends Controller implements Cacheable
{
    public function __construct()
    {
        $this->middleware('author:article', ['only' => ['update', 'destroy', 'pickBest']]);

        if (! is_api_request()) {
            $this->middleware('auth', ['except' => ['index', 'show']]);

            $allTags = \Cache::remember('tags', 30, function() {
                return Tag::with('articles')->get();
            });

            view()->share('allTags', $allTags);
        }

        parent::__construct();
    }

    public function cacheKeys()
    {
        return 'articles';
    }

    /**
     * Display a listing of the resource.
     *
     * @param \App\Http\Requests\FilterArticlesRequest $request
     * @param string|null                              $slug
     * @return \Illuminate\Http\Response
     */
    public function index(FilterArticlesRequest $request, $slug = null)
    {
        $query = $slug
            ? Tag::whereSlug($slug)->firstOrFail()->articles()
            : new Article;

        $cacheKey = cache_key('articles.index');

        $query = $this->filter($query->orderBy('pin', 'desc'));
        $args = $request->input(config('project.params.limit'), 5);

        $articles = $this->cache($cacheKey, 5, $query, 'paginate', $args);

        return $this->respondCollection($articles, $cacheKey);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $article = new Article;

        return view('articles.create', compact('article'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param \App\Http\Requests\ArticlesRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(ArticlesRequest $request)
    {
        $payload = array_merge($request->except('_token'), [
            'notification' => $request->has('notification'),
        ]);

        $article = $request->user()->articles()->create($payload);
        $article->tags()->sync($request->input('tags'));

        if ($request->has('attachments')) {
            $attachments = \App\Attachment::whereIn('id', $request->input('attachments'))->get();
            $attachments->each(function ($attachment) use ($article) {
                $attachment->article()->associate($article);
                $attachment->save();
            });
        }

        event(new ModelChanged(['articles', 'tags']));

        return $this->respondCreated($article);
    }

    /**
     * Display the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $cacheKey = cache_key("articles.show.{$id}");
        $secondKey = cache_key("articles.show.{$id}.comments");

        $query = Article::with('comments', 'tags', 'attachments', 'solution')->findOrFail($id);
        $article = $this->cache($cacheKey, 5, $query, 'findOrFail', $id);


        $secondQuery = $article->comments()->with('replies')->withTrashed()->whereNull('parent_id')->latest();
        $commentsCollection = $this->cache($secondKey, 5, $secondQuery, 'get');

        if (! is_api_request()) {
            event(new ArticleConsumed($article));
        }

        return $this->respondItem($article, $commentsCollection, $cacheKey.$secondKey);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $article = Article::findOrFail($id);

        return view('articles.edit', compact('article'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param \App\Http\Requests\ArticlesRequest $request
     * @param  int                               $id
     * @return \Illuminate\Http\Response
     */
    public function update(ArticlesRequest $request, $id)
    {
        $payload = array_merge($request->except('_token'), [
            'notification' => $request->has('notification'),
        ]);

        $article = Article::findOrFail($id);
        $article->update($payload);

        if ($request->has('tags')) {
            $article->tags()->sync($request->input('tags'));
        }

        event(new ModelChanged(['articles', 'tags']));

        return $this->respondUpdated($article);
    }

    public function pickBest(Request $request, $id)
    {
        $this->validate($request, [
            'solution_id' => 'required|numeric|exists:comments,id',
        ]);

        Article::findOrFail($id)->update([
            'solution_id' => $request->input('solution_id'),
        ]);


        return json()->noContent();
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \Illuminate\Http\Request $request
     * @param  int                     $id
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function destroy(Request $request, $id)
    {
        $article = Article::with('attachments', 'comments')->findOrFail($id);

        foreach ($article->attachments as $attachment) {
            \File::delete(attachment_path($attachment->name));
        }

        $article->attachments()->delete();
        $article->comments->each(function ($comment) use ($request) {
            app(\App\Http\Controllers\CommentsController::class)->destroy($request, $comment->id);
        });
        $article->delete();

        event(new ModelChanged('articles'));

        if ($request->ajax()) {
            return response()->json('', 204);
        }

        return $this->respondDeleted($article);
    }

    /**
     * Respond Article Collection.
     *
     * @param \Illuminate\Pagination\LengthAwarePaginator $articles
     * @param string|null                                 $cacheKey
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    protected function respondCollection(LengthAwarePaginator $articles, $cacheKey = null)
    {
        return view('articles.index', compact('articles'));
    }

    /**
     * Respond Created.
     *
     * @param \App\Article $article
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function respondCreated(Article $article)
    {
        flash()->success(trans('common.created'));

        return redirect(route('articles.index'));
    }

    /**
     * Respond single Article item with a corresponding Comment collection.
     *
     * @param \App\Article                                  $article
     * @param \Illuminate\Database\Eloquent\Collection|null $commentsCollection
     * @param string|null                                    $cacheKey
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    protected function respondItem(Article $article, Collection $commentsCollection = null, $cacheKey = null)
    {
        return view('articles.show', [
            'article'         => $article,
            'comments'        => $commentsCollection,
            'commentableType' => Article::class,
            'commentableId'   => $article->id,
        ]);
    }

    /**
     * Respond Updated.
     *
     * @param \App\Article $article
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function respondUpdated(Article $article)
    {
        flash()->success(trans('common.updated'));

        return redirect(route('articles.show', $article->id));
    }

    /**
     * Respond Deleted.
     *
     * @param \App\Article $article
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    protected function respondDeleted(Article $article)
    {
        flash()->success(trans('common.deleted'));

        return redirect(route('articles.index'));
    }

    /**
     * Respond Not Modified.
     *
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    protected function respondNotModified()
    {
        return response('', 304);
    }
}
