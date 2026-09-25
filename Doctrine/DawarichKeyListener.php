<?php

namespace KimaiPlugin\MileageBundle\Doctrine;

use App\Entity\User;
use App\Entity\UserPreference;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use KimaiPlugin\MileageBundle\Service\DawarichKeyStore;
use KimaiPlugin\MileageBundle\Service\MileageConfiguration;

/**
 * The Dawarich API key is edited as a user preference (Profil → Einstellungen, or Kimai's
 * `PATCH /api/users/{id}/preferences`), but never stored as one: Kimai hands all preferences to the
 * user API and to invoice templates. This listener takes the submitted value out of the flush and writes
 * it to {@see DawarichKeyStore} instead.
 *
 * Value of the preference → effect: {@see MileageConfiguration::SECRET_UNCHANGED} keeps the key,
 * null or blank deletes it, anything else replaces it.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
class DawarichKeyListener
{
    /** @var array<int, array{User, ?string}> */
    private array $pending = [];

    public function __construct(private readonly DawarichKeyStore $keys)
    {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();

        foreach ([...$uow->getScheduledEntityInsertions(), ...$uow->getScheduledEntityUpdates()] as $entity) {
            if (!$entity instanceof UserPreference || $entity->getName() !== MileageConfiguration::PREF_DAWARICH_API_KEY) {
                continue;
            }
            $user = $entity->getUser();
            $value = $entity->getValue();
            if ($user !== null && $user->getId() !== null && $value !== MileageConfiguration::SECRET_UNCHANGED) {
                $this->pending[$user->getId()] = [$user, $value];
            }

            // never written: a new preference is dropped from the insertions, a row from before the
            // key had its own table is deleted
            $user?->getPreferences()->removeElement($entity);
            $uow->scheduleForDelete($entity);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $pending = $this->pending;
        $this->pending = [];
        foreach ($pending as [$user, $value]) {
            $this->keys->setDawarichApiKey($user, $value);
        }
    }
}
