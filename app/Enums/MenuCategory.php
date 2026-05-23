<?php

namespace App\Enums;

enum MenuCategory: string
{
    case Meal = 'meal';
    case Snack = 'snack';
    case Drink = 'drink';
    case Extra = 'extra';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
