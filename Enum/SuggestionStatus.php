<?php

namespace KimaiPlugin\MileageBundle\Enum;

enum SuggestionStatus: string
{
    case OPEN = 'open';
    case ACCEPTED = 'accepted';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return 'suggestion.status.' . $this->value;
    }
}
