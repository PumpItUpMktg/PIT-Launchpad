<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\LibraryPhoto;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryPhoto>
 */
class LibraryPhotoFactory extends Factory
{
    protected $model = LibraryPhoto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $hash = hash('sha256', $this->faker->unique()->uuid());

        return [
            'account_id' => Account::factory(),
            'r2_key' => 'accounts/acct/library/'.$hash.'.jpg',
            'hash' => $hash,
            'original_filename' => $this->faker->word().'.jpg',
            'content_type' => 'image/jpeg',
            'tags' => null,
            'label' => null,
        ];
    }
}
