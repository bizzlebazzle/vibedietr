<?php

namespace App\Domain\Measurements;

enum StandardUnit: string
{
    case Milligram = 'milligram';
    case Gram = 'gram';
    case Kilogram = 'kilogram';
    case Ounce = 'ounce';
    case Pound = 'pound';
    case Millilitre = 'millilitre';
    case Centilitre = 'centilitre';
    case Litre = 'litre';
    case Teaspoon = 'teaspoon_uk';
    case Tablespoon = 'tablespoon_uk';
    case FluidOunce = 'fluid_ounce_us';
    case Cup = 'cup_us';
    case Pint = 'pint_us';
    case Quart = 'quart_us';
    case Gallon = 'gallon_us';
    case Item = 'item';
    case Piece = 'piece';
    case Slice = 'slice';
    case Clove = 'clove';
    case Serving = 'serving';
    case Portion = 'portion';
    case Can = 'can';
    case Jar = 'jar';
    case Bottle = 'bottle';
    case Carton = 'carton';
    case Packet = 'packet';
    case Pouch = 'pouch';
    case Pot = 'pot';
    case Tub = 'tub';
    case Stick = 'stick';
    case Bar = 'bar';
}
