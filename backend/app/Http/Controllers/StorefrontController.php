<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the storefront single-page application shell. The SPA itself is built from /storefront into public/store
 * (see docker/php/Dockerfile); without a build the web root falls back to the admin sign-in so the site never 404s.
 */
class StorefrontController extends Controller
{
    public function show(Request $request): Response
    {
        $shell = public_path('store/index.html');
        if (! is_file($shell)) {
            return redirect('/admin');
        }

        // The shell is tiny and references hashed assets; never cache it so a release is picked up at once.
        return response()->file($shell, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
