<?php

namespace Nidavellir\Mjolnir\Support\ApiDataMappers;

use Illuminate\Support\Facades\Validator;

trait DataMapperValidator
{
    public function validateOrderQuery(array $data)
    {
        $rules = [
            'order_id' => 'required|integer',
            'symbol' => 'required|array|size:2',
            'symbol.0' => 'required|string',
            'symbol.1' => 'required|string',
            'status' => 'required|string|in:NEW,FILLED,PARTIALLY_FILLED,CANCELLED',
            'price' => 'required|numeric|min:0',
            'quantity' => 'required|numeric',
            'type' => 'required|string|in:LIMIT,MARKET',
            'side' => 'required|string|in:SELL,BUY',
        ];

        $this->validate($data, $rules);
    }

    public function validate($data, $rules)
    {
        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $failedRules = $validator->failed();
            $message = 'DataMapperValidator for validateOrderQuery failed: ';

            foreach ($failedRules as $field => $failures) {
                foreach ($failures as $rule => $details) {
                    $value = $data[$field] ?? 'undefined';
                    $ruleDetails = is_array($details) ? json_encode($details) : $details;
                    $message .= "\nField: {$field}, Value: {$value}, Failed Rule: {$rule}, Rule Details: {$ruleDetails}";
                }
            }

            throw new \InvalidArgumentException($message);
        }
    }
}
