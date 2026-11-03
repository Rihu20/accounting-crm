<?php

namespace App\Services;

class ExpenseGroups
{
    public const LABELS = ['foreign' => 'Foreign expenses', 'patent_office' => 'Patent / IP office expenses', 'indian' => 'Indian expenses'];

    public static function categories(?string $group): array
    {
        $values = match ($group) {
            'foreign' => ['Law firm', 'Patent agent', 'Translation', 'Courier', 'Other'],'patent_office' => ['Official fees', 'Filing fees', 'Search / examination', 'Renewal / annuity', 'Other'],default => ['Law firm', 'Rent', 'Office expenses', 'Subscription', 'Admin expense', 'Travel', 'Software', 'Courier', 'Government fees', 'Office', 'Other']
        };

        return array_combine($values, $values);
    }
}
