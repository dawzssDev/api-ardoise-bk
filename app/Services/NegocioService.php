<?php

namespace App\Services;

use App\Models\Negocio;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NegocioService
{
    private const LOGO_DISK = 'negocios_logos';

    public function forUser(User|Staff $user): Negocio
    {
        $negocio = $user->negocio;

        if (! $negocio) {
            throw new HttpException(422, 'El usuario no tiene un negocio asociado.');
        }

        return $negocio;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Negocio $negocio, array $data): Negocio
    {
        $negocio->fill($data);
        $negocio->save();

        return $negocio->refresh();
    }

    public function updateLogo(Negocio $negocio, UploadedFile $file): Negocio
    {
        $extension = $this->logoExtension($file);
        $filename = $this->logoFilename($negocio, $extension);

        $this->deletePreviousLogos($negocio);

        $file->storeAs('', $filename, self::LOGO_DISK);

        $negocio->logo = $filename;
        $negocio->save();

        return $negocio->refresh();
    }

    private function logoFilename(Negocio $negocio, string $extension): string
    {
        $base = (string) Str::of($negocio->name)
            ->ascii()
            ->replaceMatches('/[^A-Za-z0-9]+/', '_')
            ->trim('_');

        if ($base === '') {
            $base = 'negocio';
        }

        return $base.'_'.$negocio->id.'.'.$extension;
    }

    private function logoExtension(UploadedFile $file): string
    {
        $extension = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));

        if ($extension === 'jpeg') {
            return 'jpg';
        }

        if (in_array($extension, ['jpg', 'png'], true)) {
            return $extension;
        }

        return $file->getMimeType() === 'image/png' ? 'png' : 'jpg';
    }

    private function deletePreviousLogos(Negocio $negocio): void
    {
        $disk = Storage::disk(self::LOGO_DISK);
        $paths = [];

        if (is_string($negocio->logo) && $negocio->logo !== '') {
            $paths[] = $negocio->logo;
        }

        $suffixPng = '_'.$negocio->id.'.png';
        $suffixJpg = '_'.$negocio->id.'.jpg';

        foreach ($disk->files() as $path) {
            $name = basename((string) $path);
            if (str_ends_with($name, $suffixPng) || str_ends_with($name, $suffixJpg)) {
                $paths[] = $path;
            }
        }

        foreach (array_unique($paths) as $path) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }
    }
}
