<?php
/**
 * Strict UUIDv7 TypeID validation for backend identifiers.
 *
 * @package AuraHistoria\PartnerConnect
 */

namespace AuraHistoria\PartnerConnect;

if (!defined("ABSPATH")) {
    exit();
}

/**
 * Validates canonical ls_ and oc_ TypeIDs backed by UUIDv7 bytes.
 */
class Type_ID_Validator
{
    const ALPHABET = "0123456789abcdefghjkmnpqrstvwxyz";

    /**
     * @param mixed  $type_id TypeID to validate.
     * @param string $prefix  Exact expected prefix, either ls_ or oc_.
     * @return bool
     */
    public static function is_valid($type_id, $prefix)
    {
        if (
            !is_string($type_id) ||
            ($prefix !== "ls_" && $prefix !== "oc_") ||
            strlen($type_id) !== 29 ||
            substr($type_id, 0, 3) !== $prefix
        ) {
            return false;
        }

        $suffix = substr($type_id, 3);
        if (1 !== preg_match('/\A[0-7][0-9a-hjkmnp-tv-z]{25}\z/', $suffix)) {
            return false;
        }

        // 26 Crockford digits encode 130 bits; the first two are zero padding.
        $bytes = "";
        $buffer = 0;
        $bits = 0;
        for ($i = 0; $i < 26; $i++) {
            $digit = strpos(self::ALPHABET, $suffix[$i]);
            $width = $i === 0 ? 3 : 5;
            $buffer = ($buffer << $width) | $digit;
            $bits += $width;

            if ($bits >= 8) {
                $bits -= 8;
                $bytes .= chr(($buffer >> $bits) & 0xff);
                $buffer &= (1 << $bits) - 1;
            }
        }

        return strlen($bytes) === 16 &&
            (ord($bytes[6]) & 0xf0) === 0x70 &&
            (ord($bytes[8]) & 0xc0) === 0x80;
    }
}
