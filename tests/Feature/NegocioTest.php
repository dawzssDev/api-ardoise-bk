<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NegocioTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_negocio_defaults_comision_venta_tarjeta_to_three(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/negocio')
            ->assertOk()
            ->assertJsonPath('data.negocio.comision_venta_tarjeta', 3)
            ->assertJsonPath('data.negocio.comisionVentaTarjeta', 3)
            ->assertJsonPath('data.negocio.comicionVentaTarjeta', 3)
            ->assertJsonPath('data.negocio.logo', [])
            ->assertJsonPath('data.negocio.logo_url', []);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 3,
        ]);
    }

    public function test_master_can_update_comicion_venta_tarjeta(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/negocio', [
            'comicionVentaTarjeta' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.negocio.comision_venta_tarjeta', 10)
            ->assertJsonPath('data.negocio.comicionVentaTarjeta', 10);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 10,
        ]);
    }

    public function test_comision_venta_tarjeta_rejects_out_of_range(): void
    {
        $user = User::factory()->create();
        $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->putJson('/api/negocio', [
            'comisionVentaTarjeta' => 101,
        ])->assertStatus(422);

        $this->putJson('/api/negocio', [
            'comision_venta_tarjeta' => -1,
        ])->assertStatus(422);

        $this->assertDatabaseHas('negocios', [
            'user_id' => $user->id,
            'comision_venta_tarjeta' => 3,
        ]);
    }

    public function test_master_can_upload_negocio_logo_as_png(): void
    {
        Storage::fake('negocios_logos');

        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $response = $this->post('/api/negocio/logo', [
            'logo' => UploadedFile::fake()->image('marca.png', 120, 80),
        ], [
            'Accept' => 'application/json',
        ]);

        $filename = 'Negocio_Test_'.$negocio->id.'.png';

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.negocio.logo', $filename);

        $this->assertNotNull($response->json('data.negocio.logo_url'));
        Storage::disk('negocios_logos')->assertExists($filename);
        $this->assertDatabaseHas('negocios', [
            'id' => $negocio->id,
            'logo' => $filename,
        ]);
    }

    public function test_uploading_jpg_logo_replaces_previous_png(): void
    {
        Storage::fake('negocios_logos');

        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Café Sol',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        $pngName = 'Cafe_Sol_'.$negocio->id.'.png';
        $negocio->update(['logo' => $pngName]);
        Storage::disk('negocios_logos')->put($pngName, 'old-png');

        Sanctum::actingAs($user);

        $this->post('/api/negocio/logo', [
            'imagen' => UploadedFile::fake()->image('nuevo.jpg', 80, 80),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('data.negocio.logo', 'Cafe_Sol_'.$negocio->id.'.jpg');

        Storage::disk('negocios_logos')->assertMissing('Cafe_Sol_'.$negocio->id.'.png');
        Storage::disk('negocios_logos')->assertExists('Cafe_Sol_'.$negocio->id.'.jpg');
        $this->assertCount(1, Storage::disk('negocios_logos')->files());
    }

    public function test_uploading_same_format_logo_replaces_previous_file(): void
    {
        Storage::fake('negocios_logos');

        $user = User::factory()->create();
        $negocio = $user->negocio()->create([
            'name' => 'Negocio Test',
            'phone' => '6670000000',
            'needs_invoice' => false,
        ]);

        Sanctum::actingAs($user);

        $this->post('/api/negocio/logo', [
            'logo' => UploadedFile::fake()->image('primero.png', 40, 40),
        ], [
            'Accept' => 'application/json',
        ])->assertOk();

        $filename = 'Negocio_Test_'.$negocio->id.'.png';
        $previousContents = Storage::disk('negocios_logos')->get($filename);

        $this->post('/api/negocio/logo', [
            'logo' => UploadedFile::fake()->image('segundo.png', 90, 90),
        ], [
            'Accept' => 'application/json',
        ])
            ->assertOk()
            ->assertJsonPath('data.negocio.logo', $filename);

        Storage::disk('negocios_logos')->assertExists($filename);
        $this->assertCount(1, Storage::disk('negocios_logos')->files());
        $this->assertNotSame($previousContents, Storage::disk('negocios_logos')->get($filename));
    }
}
