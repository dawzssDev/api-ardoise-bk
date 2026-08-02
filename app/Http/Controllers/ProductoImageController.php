<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductoImageController extends Controller
{
    /**
     * Sirve imagen de producto (público).
     * Evita depender de storage:link o de que public/ sea el document root.
     */
    public function show(int $negocioId, string $filename): StreamedResponse
    {
        $relative = $negocioId.'/'.$filename;

        if (Storage::disk('productos')->exists($relative)) {
            return Storage::disk('productos')->response($relative);
        }

        // Legacy: storage/app/public/productos/{negocio}/{file}
        $legacy = 'productos/'.$relative;
        if (Storage::disk('public')->exists($legacy)) {
            return Storage::disk('public')->response($legacy);
        }

        abort(404);
    }
}
