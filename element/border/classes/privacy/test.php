<?php

enum DiscountType 
{
    case Standard;
    case Seasonal;
    case Weight;

    public function discountpercentage(float $weight = 0): float {
        return match($this) {
            self::Standard => 6.0,
            self::Seasonal => 12.0,
            self::Weight => $weight <= 10 ? 6.0 : 18.0,
        };
    }
}

function getDiscountedPrice(float $cartWeight, float $totalPrice, 
                            DiscountType $discountType): float
{
    $discount = $discountType->discountpercentage($cartWeight);
    return $totalPrice - ($totalPrice * ($discount / 100));
}

echo getDiscountedPrice(12, 100, DiscountType::Weight);