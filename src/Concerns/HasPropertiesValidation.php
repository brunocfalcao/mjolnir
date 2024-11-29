<?php

namespace Nidavellir\Mjolnir\Concerns;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Nidavellir\Mjolnir\Support\ValueObjects\ApiProperties;

trait HasPropertiesValidation
{
    /**
     * Reusable generic validate method.
     *
     * @throws ValidationException
     */
    protected function validate(ApiProperties $properties, array $rules)
    {
        // Convert ApiProperties to array and validate against the rules.
        $data = $properties->toArray();

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
