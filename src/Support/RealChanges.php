<?php

namespace Nidavellir\Mjolnir\Support;

use Illuminate\Database\Eloquent\Model;

class RealChanges
{
    protected Model $model;

    protected array $realChanges = [];

    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->computeRealChanges();
    }

    /**
     * Compute the real changes, filtering out insignificant numerical differences.
     */
    protected function computeRealChanges(): void
    {
        $changes = $this->model->getChanges();
        $original = $this->model->getOriginal();

        foreach ($changes as $attribute => $newValue) {
            $oldValue = $original[$attribute] ?? null;

            // Normalize numerical values
            if (is_numeric($oldValue) && is_numeric($newValue)) {
                $normalizedOldValue = $this->removeTrailingZeros((float) $oldValue);
                $normalizedNewValue = $this->removeTrailingZeros((float) $newValue);

                // info($normalizedOldValue . ' vs ' . $normalizedNewValue);

                if ($normalizedOldValue != $normalizedNewValue) {
                    // info('Index adding ' . $attribute);
                    $this->realChanges[$attribute] = [
                        'old' => $oldValue,
                        'new' => $newValue,
                    ];
                }
            }
            // Non-numerical values
            elseif ($oldValue !== $newValue) {
                $this->realChanges[$attribute] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }
        }
    }

    /**
     * Check if a specific attribute was "really" changed.
     */
    public function wasChanged(string $attribute): bool
    {
        return array_key_exists($attribute, $this->realChanges);
    }

    /**
     * Get all real changes.
     */
    public function all(): array
    {
        return $this->realChanges;
    }

    /**
     * Normalize a number by removing trailing zeros.
     */
    protected function removeTrailingZeros(float $number): float
    {
        return (float) rtrim(rtrim(number_format($number, 10, '.', ''), '0'), '.');
    }
}

// Usage Example:
// $changes = new RealChanges($order);
// if ($changes->wasChanged('status')) {
//     $realChanges = $changes->all();
//     $originalStatus = $order->getOriginal('status');
//     $currentStatus = $order->status;
// }
