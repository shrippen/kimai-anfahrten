<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;

/**
 * Where the personal Dawarich API key lives (not in the user preferences, see {@see \KimaiPlugin\MileageBundle\Entity\UserSecret}).
 */
interface DawarichKeyStore
{
    public function getDawarichApiKey(User $user): ?string;

    /**
     * Null or an empty/blank string removes the key.
     */
    public function setDawarichApiKey(User $user, ?string $key): void;
}
