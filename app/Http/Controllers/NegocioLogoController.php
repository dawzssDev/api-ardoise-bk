<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NegocioLogoController extends Controller
{
    /**
     * Sirve el logo del negocio (público).
     * Evita depender de storage:link o de que public/ sea el document root.
     */
    public function show(string $filename): StreamedResponse
    {
        if (Storage::disk('negocios_logos')->exists($filename)) {
            return Storage::disk('negocios_logos')->response($filename);
        }

        abort(404);
    }
}
