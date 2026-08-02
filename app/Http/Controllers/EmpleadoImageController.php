<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmpleadoImageController extends Controller
{
    /**
     * Sirve imagen de empleado (público).
     */
    public function show(int $negocioId, string $filename): StreamedResponse
    {
        $relative = $negocioId.'/'.$filename;

        if (Storage::disk('empleados')->exists($relative)) {
            return Storage::disk('empleados')->response($relative);
        }

        abort(404);
    }
}
