<?php

namespace App\Domain\Measurements;

final class MeasurementUnitParser
{
    public static function storageValue(string $input): string
    {
        $unit = MeasurementUnitRegistry::normalize($input);

        return $unit instanceof StandardUnit
            ? MeasurementUnitRegistry::definition($unit)->symbol
            : $unit->originalText;
    }

    public static function parsedValue(StandardUnit|CustomUnit $unit): string
    {
        return $unit instanceof StandardUnit
            ? MeasurementUnitRegistry::definition($unit)->symbol
            : $unit->originalText;
    }

    public static function findInText(string $text): StandardUnit|CustomUnit|null
    {
        $value = mb_strtolower(trim($text));
        $patterns = [
            '/\bmilligrams?\b|\bmgs?\b/' => StandardUnit::Milligram,
            '/\bkilograms?\b|\bkgs?\b/' => StandardUnit::Kilogram,
            '/(?<![a-z])g(?![a-z])|\bgrams?\b/' => StandardUnit::Gram,
            '/\bmillilit(?:re|er)s?\b|\bmls?\b/' => StandardUnit::Millilitre,
            '/\bcentilit(?:re|er)s?\b|\bcls?\b/' => StandardUnit::Centilitre,
            '/(?<![a-z])l(?![a-z])|\blit(?:re|er)s?\b/' => StandardUnit::Litre,
            '/\bteaspoons?\b|\btsps?\b/' => StandardUnit::Teaspoon,
            '/\btablespoons?\b|\btbsps?\b/' => StandardUnit::Tablespoon,
            '/\bfluid ounces?\b|\bfl\.?\s*oz\b/' => StandardUnit::FluidOunce,
            '/\bcups?\b/' => StandardUnit::Cup,
            '/\bpints?\b|\bpts?\b/' => StandardUnit::Pint,
            '/\bquarts?\b|\bqts?\b/' => StandardUnit::Quart,
            '/\bgallons?\b|\bgals?\b/' => StandardUnit::Gallon,
            '/\bounces?\b|\bozs?\b/' => StandardUnit::Ounce,
            '/\bpounds?\b|\blbs?\b/' => StandardUnit::Pound,
            '/\bservings?\b|\bserve\b/' => StandardUnit::Serving,
            '/\bportions?\b/' => StandardUnit::Portion,
            '/\bslices?\b/' => StandardUnit::Slice,
            '/\bcloves?\b/' => StandardUnit::Clove,
            '/\bcans?\b/' => StandardUnit::Can,
            '/\bjars?\b/' => StandardUnit::Jar,
            '/\bbottles?\b/' => StandardUnit::Bottle,
            '/\bcartons?\b/' => StandardUnit::Carton,
            '/\bpackets?\b|\bpacks?\b/' => StandardUnit::Packet,
            '/\bpouches?\b/' => StandardUnit::Pouch,
            '/\bpots?\b/' => StandardUnit::Pot,
            '/\btubs?\b/' => StandardUnit::Tub,
            '/\bsticks?\b/' => StandardUnit::Stick,
            '/\bbars?\b/' => StandardUnit::Bar,
            '/\bpieces?\b|\bpcs?\b|\bcookies?\b|\bbiscuits?\b|\bcrackers?\b/' => StandardUnit::Piece,
            '/\beach\b|\bitems?\b|\bunits?\b/' => StandardUnit::Item,
        ];

        foreach ($patterns as $pattern => $unit) {
            if (preg_match($pattern, $value)) {
                return $unit;
            }
        }

        $customPatterns = [
            '/\bpinches?\b/' => 'pinch',
            '/\bdashes?\b/' => 'dash',
            '/\bhandfuls?\b/' => 'handful',
            '/\bscoops?\b/' => 'scoop',
            '/\bbunches?\b/' => 'bunch',
            '/\bsprigs?\b/' => 'sprig',
            '/\bto taste\b/' => 'to taste',
        ];

        foreach ($customPatterns as $pattern => $unit) {
            if (preg_match($pattern, $value)) {
                return new CustomUnit($unit);
            }
        }

        return null;
    }
}
