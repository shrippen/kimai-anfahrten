<?php

namespace KimaiPlugin\MileageBundle\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

trait TargetUserTrait
{
    protected function getTargetUser(Request $request, UserRepository $userRepository): User
    {
        /** @var User $current */
        $current = $this->getUser();
        $userId = $request->query->getInt('user');

        if ($userId <= 0 || $userId === $current->getId()) {
            return $current;
        }

        $user = $userRepository->find($userId);
        if (!$user instanceof User || !$this->canViewTripsOf($user)) {
            // same answer for unknown and foreign users: do not reveal which ids exist
            throw new AccessDeniedException('You cannot view this user.');
        }

        return $user;
    }

    /**
     * Own trips; everybody with view_other/edit_other; team leads (view_team_mileage) their members.
     */
    protected function canViewTripsOf(?User $user): bool
    {
        $current = $this->getUser();
        if ($user === null || !$current instanceof User) {
            return false;
        }
        if ($user === $current || $this->isGranted('view_other_mileage') || $this->isGranted('edit_other_mileage')) {
            return true;
        }

        return $this->isGranted('view_team_mileage') && $current->isTeamleadOfUser($user);
    }

    protected function canEditTripsOf(?User $user): bool
    {
        if ($user === $this->getUser()) {
            return $this->isGranted('edit_own_mileage');
        }

        return $this->isGranted('edit_other_mileage');
    }

    protected function canDeleteTripsOf(?User $user): bool
    {
        if ($user === $this->getUser()) {
            return $this->isGranted('delete_own_mileage');
        }

        return $this->isGranted('delete_other_mileage');
    }
}
