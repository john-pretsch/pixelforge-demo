<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\PixelforgeFixtures;
use Tests\TestCase;

class ReferenceImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_upload_returns_a_public_url_under_the_users_folder(): void
    {
        $user = User::factory()->create();

        $url = $this->actingAs($user)
            ->post('/reference-images', ['image' => UploadedFile::fake()->image('My Photo.PNG', 640, 480)], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('url');

        $this->assertMatchesRegularExpression('#/storage/reference-images/'.$user->id.'/[0-9a-f-]{36}\.png$#', $url);
        $path = 'reference-images/'.$user->id.'/'.basename($url);
        Storage::disk('public')->assertExists($path);
    }

    public function test_rejects_non_images_svg_and_oversized_files(): void
    {
        $user = User::factory()->create();

        $files = [
            UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->createWithContent('evil.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('fake.png', 'not really a png'),
            UploadedFile::fake()->image('huge.jpg')->size(2049),
        ];

        foreach ($files as $file) {
            $this->actingAs($user)->postJson('/reference-images', ['image' => $file])->assertUnprocessable()->assertJsonValidationErrors('image');
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_guests_cannot_upload(): void
    {
        $this->postJson('/reference-images', ['image' => UploadedFile::fake()->image('a.png')])->assertUnauthorized();
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_uploaded_url_is_accepted_as_a_reference_image(): void
    {
        $user = User::factory()->create();
        $url = $this->actingAs($user)->postJson('/reference-images', ['image' => UploadedFile::fake()->image('a.jpg')])->json('url');

        Http::fake(['https://pixelforge.test/v1/pricing' => Http::response(PixelforgeFixtures::pricing())]);

        $this->actingAs($user)->postJson('/generations/estimate', [
            'prompt' => 'a cat', 'size' => '1024x1024', 'quality' => 'low', 'reference_images' => [$url],
        ])->assertOk()->assertJsonPath('tokens.max.input_image_tokens', 765);
    }
}
