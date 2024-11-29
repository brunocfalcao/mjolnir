<?php

namespace App\Concerns;

use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

trait ValidatesAttributes
{
    /**
     * Validate the given attributes and throw an InvalidArgumentException
     * if validation fails.
     *
     * @return void
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $attributes, array $rules)
    {
        $validator = Validator::make($attributes, $rules);

        if ($validator->fails()) {
            // Throw exception with the first validation error message
            throw new InvalidArgumentException($validator->errors()->first());
        }
    }
}
