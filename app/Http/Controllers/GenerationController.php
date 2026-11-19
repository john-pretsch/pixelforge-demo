<?php

namespace App\Http\Controllers;

use App\Actions\Credits\InsufficientCredits;
use App\Actions\Generations\CreateGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Brick\Math\BigDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class GenerationController extends Controller
{
    /** History page size. */
    public const PER_PAGE = 10;

    public function index(Request $request, PixelforgeClient $client): Response|RedirectResponse
    {
        $page = $request->user()->generations()->latest('id')->paginate(self::PER_PAGE)->withQueryString();

        // Past the end (e.g. a stale ?page= link): go to the last page instead of showing nothing.
        if ($page->isEmpty() && $page->currentPage() > 1) {
            return to_route('generations.index', ['page' => $page->lastPage()]);
        }

        try {
            $pricing = $client->pricing();
            $options = ['sizes' => $pricing->sizes(), 'qualities' => $pricing->qualities()];
        } catch (PixelforgeException) {
            $options = null; // Form shows "pricing unavailable" and disables submit.
        }

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($page->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
            'options' => $options,
            'markupPercent' => (string) BigDecimal::of(config('pixelforge.markup'))->minus(1)->multipliedBy(100)->stripTrailingZeros(),
        ]);
    }

    public function store(GenerationRequest $request, CreateGeneration $create): RedirectResponse
    {
        // A Pixelforge outage while pricing is rendered by the PixelforgeException handler (bootstrap/app.php).
        try {
            $create->handle($request->user(), $request->validated());
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }

        return to_route('generations.index')->with('success', 'Generation submitted.');
    }

    /**
     * Retry a generation that failed because Pixelforge stayed busy. Submits a
     * new generation with the same settings (fresh estimate, credit check and
     * hold); the failed original is left untouched.
     */
    public function retry(Request $request, Generation $generation, CreateGeneration $create): RedirectResponse
    {
        abort_unless($generation->user_id === $request->user()->id, 404);

        if (! $generation->canRetry()) {
            return back()->with('error', 'Only generations that failed because Pixelforge was busy can be retried.');
        }

        try {
            $create->handle($request->user(), [
                'title' => $generation->title,
                'prompt' => $generation->prompt,
                'size' => $generation->size,
                'quality' => $generation->quality,
                'reference_images' => $generation->reference_images ?? [],
            ]);
        } catch (InsufficientCredits $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('generations.index')->with('success', 'Retrying: submitted as a new generation.');
    }

    public function estimate(GenerationRequest $request, CreateGeneration $create): JsonResponse
    {
        $data = $request->validated();

        $estimate = $create->estimate(
            (string) ($data['prompt'] ?? ''),
            $data['size'],
            $data['quality'],
            count($data['reference_images'] ?? []),
        );

        return response()->json($estimate->toArray());
    }
}
