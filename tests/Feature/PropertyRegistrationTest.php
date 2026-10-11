<?php

use App\Models\Property;
use App\Models\PropertyImage;
use App\Models\PropertyType;
use App\Models\User;
use Database\Seeders\CrmCoreSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('changing the property type displays its respective fields', function () {
    $this->seed(CrmCoreSeeder::class);
    $user = User::factory()->administrador()->create();
    $types = PropertyType::query()->pluck('id', 'slug');

    $this->actingAs($user);

    Livewire::test('pages::properties.index')
        ->call('create')
        ->assertSee('Area total m2')
        ->set('form.property_type_id', $types['departamento'])
        ->assertSee('Edificio')
        ->assertSee('Piso')
        ->assertDontSee('Desarrollador')
        ->set('form.property_type_id', $types['proyecto-inmobiliario'])
        ->assertSee('Desarrollador')
        ->assertSee('Precio desde')
        ->assertDontSee('Dormitorios');
});

test('property registration stores image metadata for any image proportion', function () {
    $this->seed(CrmCoreSeeder::class);
    $user = User::factory()->administrador()->create();
    $propertyType = PropertyType::query()->where('slug', 'casa')->firstOrFail();
    $image = UploadedFile::fake()->createWithContent(
        'panorama.png',
        file_get_contents(base_path('public/assets/img/casa.png')),
        'image/png',
    );
    Storage::fake('public');

    $this->actingAs($user);

    Livewire::test('pages::properties.index')
        ->call('create')
        ->set('form.title', 'Casa panoramica')
        ->set('form.property_type_id', $propertyType->id)
        ->set('form.district', 'Miraflores')
        ->set('form.address', 'Avenida del Pacifico 100')
        ->set('form.price', 450000)
        ->set('form.currency', 'PEN')
        ->set('form.area_m2', 180)
        ->set('photos', [$image])
        ->call('save')
        ->assertHasNoErrors();

    $property = Property::query()->where('title', 'Casa panoramica')->firstOrFail();
    $storedImage = PropertyImage::query()->where('property_id', $property->id)->firstOrFail();

    $this->assertModelExists($property);
    Storage::disk('public')->assertExists($storedImage->storage_key);
    expect($storedImage->width)->toBe(1536)
        ->and($storedImage->height)->toBe(1024)
        ->and($storedImage->is_primary)->toBeTrue();
});
