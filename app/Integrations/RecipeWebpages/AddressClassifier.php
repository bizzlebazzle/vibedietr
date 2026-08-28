<?php

namespace App\Integrations\RecipeWebpages;

final class AddressClassifier
{
    public function isPublic(string $address): bool
    {
        $address = trim($address, '[]');
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $match) === 1) {
            $address = $match[1];
        }

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $packed = inet_pton($address);
            $carrierGradeNat = inet_pton('100.64.0.0');

            return $packed !== false
                && $carrierGradeNat !== false
                && (ord($packed[0]) !== 100 || (ord($packed[1]) & 0xC0) !== 0x40)
                && ord($packed[0]) < 224;
        }

        $packed = inet_pton($address);

        return $packed !== false && ord($packed[0]) !== 0xFF;
    }
}
