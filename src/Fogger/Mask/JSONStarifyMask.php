<?php

namespace App\Fogger\Mask;

final class JSONStarifyMask extends AbstractMask
{
    public function apply(?string $value, array $options = []): ?string
    {
        if ((null === $value) or ("" === $value )) {
            return $value;
        }

        $fields = $options['fields'] ?? [];
        
        $jsonArray = json_decode($value, true);
        $result = $jsonArray;

        foreach ($fields as $field) {
            if (array_key_exists($field, $jsonArray)) {
                $result[$field] = str_repeat('*', $options['length'] ?? 10);
            }
        }
        return json_encode($result);
    }

    protected function getMaskName(): string
    {
        return 'jsonstarify';
    }
}
