<?php

namespace KimaiPlugin\MileageBundle\Doctrine;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use KimaiPlugin\MileageBundle\Entity\Attachment;
use KimaiPlugin\MileageBundle\Entity\Trip;
use KimaiPlugin\MileageBundle\Entity\TripAudit;
use KimaiPlugin\MileageBundle\Service\MonthLockService;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Central guard for every way a trip is written (UI, API, import, suggestions):
 * - closed months can only be changed with "edit_locked_mileage"
 * - each change is written to the audit log (electronic logbook requirement)
 *
 * Receipts: adding one to a closed month is allowed (handing in later), changing or deleting needs
 * "edit_locked_mileage". Receipts of trips are listed in the trip's audit log.
 */
#[AsDoctrineListener(event: Events::onFlush, priority: 50)]
#[AsDoctrineListener(event: Events::postFlush)]
class TripAuditListener
{
    private const IGNORED = ['createdAt'];

    /** @var list<Trip> */
    private array $inserted = [];
    private bool $flushing = false;

    public function __construct(
        private readonly MonthLockService $lockService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly TokenStorageInterface $tokenStorage,
    ) {
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        if ($this->flushing) {
            return;
        }

        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $auditMeta = $em->getClassMetadata(TripAudit::class);

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof Trip) {
                $this->assertWritable($entity);
                $this->inserted[] = $entity;
            } elseif ($entity instanceof Attachment && $entity->getTrip()?->getId() !== null) {
                $this->auditReceipt($args, $entity, TripAudit::RECEIPT_ADD);
            }
        }

        foreach ([...$uow->getScheduledEntityUpdates(), ...$uow->getScheduledEntityDeletions()] as $entity) {
            if (!$entity instanceof Attachment) {
                continue;
            }
            if ($this->lockService->isAttachmentLocked($entity)) {
                $this->assertMayEditLocked();
            }
            if ($uow->isScheduledForDelete($entity) && $entity->getTrip()?->getId() !== null && !$uow->isScheduledForDelete($entity->getTrip())) {
                $this->auditReceipt($args, $entity, TripAudit::RECEIPT_DELETE);
            }
        }

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof Trip) {
                continue;
            }
            $changes = [];
            foreach ($uow->getEntityChangeSet($entity) as $field => $change) {
                if (\in_array($field, self::IGNORED, true) || !\is_array($change)) {
                    continue;
                }
                $old = self::normalize($change[0] ?? null);
                $new = self::normalize($change[1] ?? null);
                if ($old !== $new) {
                    $changes[$field] = [$old, $new];
                }
            }
            if ($changes === []) {
                continue;
            }

            // Moving a trip out of a closed month is a change of that month, too.
            $locked = $this->lockService->isTripLocked($entity);
            if (isset($changes['date'][0]) && $entity->getUser() !== null && \is_string($changes['date'][0])) {
                $locked = $locked || $this->lockService->isLocked($entity->getUser(), new \DateTimeImmutable($changes['date'][0]));
            }
            if ($locked) {
                $this->assertMayEditLocked();
            }

            $audit = new TripAudit((int) $entity->getId(), $entity->getUser(), $this->currentUser(), TripAudit::UPDATE, $changes, $locked);
            $em->persist($audit);
            $uow->computeChangeSet($auditMeta, $audit);
        }

        foreach ($uow->getScheduledEntityDeletions() as $entity) {
            if (!$entity instanceof Trip) {
                continue;
            }
            $locked = $this->lockService->isTripLocked($entity);
            if ($locked) {
                $this->assertMayEditLocked();
            }
            $audit = new TripAudit((int) $entity->getId(), $entity->getUser(), $this->currentUser(), TripAudit::DELETE, self::snapshot($entity), $locked);
            $em->persist($audit);
            $uow->computeChangeSet($auditMeta, $audit);
        }
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        if ($this->flushing || $this->inserted === []) {
            return;
        }

        $em = $args->getObjectManager();
        $inserted = $this->inserted;
        $this->inserted = [];

        foreach ($inserted as $trip) {
            if ($trip->getId() !== null) {
                $em->persist(new TripAudit($trip->getId(), $trip->getUser(), $this->currentUser(), TripAudit::CREATE, self::snapshot($trip), $this->lockService->isTripLocked($trip)));
            }
        }

        $this->flushing = true;
        try {
            $em->flush();
        } finally {
            $this->flushing = false;
        }
    }

    private function auditReceipt(OnFlushEventArgs $args, Attachment $attachment, string $action): void
    {
        $em = $args->getObjectManager();
        /** @var Trip $trip */
        $trip = $attachment->getTrip();
        $audit = new TripAudit((int) $trip->getId(), $trip->getUser(), $this->currentUser(), $action, ['receipt' => [null, $attachment->getOriginalName()]], $this->lockService->isTripLocked($trip));
        $em->persist($audit);
        $em->getUnitOfWork()->computeChangeSet($em->getClassMetadata(TripAudit::class), $audit);
    }

    private function assertWritable(Trip $trip): void
    {
        if ($this->lockService->isTripLocked($trip)) {
            $this->assertMayEditLocked();
        }
    }

    private function assertMayEditLocked(): void
    {
        // Without a logged-in user (console) there is nobody who could have the permission.
        if ($this->tokenStorage->getToken() === null || !$this->authorizationChecker->isGranted('edit_locked_mileage')) {
            throw new AccessDeniedException('This month of the logbook is closed.');
        }
    }

    private function currentUser(): ?User
    {
        $user = $this->tokenStorage->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private static function snapshot(Trip $trip): array
    {
        $values = [
            'date' => $trip->getDate(),
            'departureAt' => $trip->getDepartureAt(),
            'arrivalAt' => $trip->getArrivalAt(),
            'purpose' => $trip->getPurpose(),
            'vehicle' => $trip->getVehicle(),
            'licensePlate' => $trip->getLicensePlate(),
            'startLocation' => $trip->getStartLocation(),
            'destination' => $trip->getDestination(),
            'distanceKm' => $trip->getDistanceKm(),
            'roundTrip' => $trip->isRoundTrip(),
            'odometerStart' => $trip->getOdometerStart(),
            'odometerEnd' => $trip->getOdometerEnd(),
            'costs' => $trip->getCosts(),
            'comment' => $trip->getComment(),
        ];

        $result = [];
        foreach ($values as $field => $value) {
            $value = self::normalize($value);
            if ($value !== null) {
                $result[$field] = [null, $value];
            }
        }

        return $result;
    }

    private static function normalize(mixed $value): string|int|float|bool|null
    {
        return match (true) {
            $value === null, \is_scalar($value) => $value,
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::ATOM),
            $value instanceof \BackedEnum => $value->value,
            \is_object($value) && method_exists($value, 'getId') => $value->getId(),
            default => get_debug_type($value),
        };
    }
}
