<?php

namespace App\Http\Controllers;

use App\Actions\Credits\InsufficientCredits;
use App\Actions\Generations\CreateGeneration;
use App\Http\Requests\GenerationRequest;
use App\Http\Resources\GenerationResource;
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
    public function index(Request $request, PixelforgeClient $client): Response
    {
        try {
            $pricing = $client->pricing();
            $options = ['sizes' => $pricing->sizes(), 'qualities' => $pricing->qualities()];
        } catch (PixelforgeException) {
            $options = null; // Form shows "pricing unavailable" and disables submit.
        }

        $generations = $request->user()->generations()->latest('id')->limit(50)->get();

        return Inertia::render('Generations/Index', [
            'generations' => GenerationResource::collection($generations)->resolve($request),
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
