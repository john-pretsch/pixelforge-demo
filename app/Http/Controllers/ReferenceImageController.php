<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload a local image to use as a reference image. Pixelforge takes
 * reference images as URLs, so the file is stored on the public disk and its
 * public URL is returned for the form to use like any other reference URL.
 */
class ReferenceImageController extends Controller
{
    /** Matches nginx client_max_body_size / PHP upload_max_filesize (2M). The browser downsizes larger images first. */
    public const MAX_KILOBYTES = 2048;

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            // Raster formats only (no SVG: it could carry script). `image` checks the real content, not the name.
            'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.self::MAX_KILOBYTES, 'dimensions:max_width=8192,max_height=8192'],
        ], [
            'image.max' => 'Images must be 2 MB or smaller.',
            'image.mimes' => 'Upload a JPEG, PNG, WebP or GIF image.',
            'image.image' => 'Upload a JPEG, PNG, WebP or GIF image.',
        ]);

        $file = $request->file('image');

        // Unguessable name; the extension comes from the detected type, never the client's filename.
        $path = $file->storeAs(
            'reference-images/'.$request->user()->id,
            Str::uuid().'.'.$file->extension(),
            'public',
        );

        // Always absolute: Pixelforge needs a full http(s) URL (url() leaves absolute ones as they are).
        return response()->json(['url' => url(Storage::disk('public')->url((string) $path))], 201);
    }
}
