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

        if (!$this->isGranted('view_other_mileage') && !$this->isGranted('edit_other_mileage')) {
            throw new AccessDeniedException('You cannot view other users.');
        }

        $user = $userRepository->find($userId);
        if (!$user instanceof User) {
            throw $this->createNotFoundException('User not found');
        }

        return $user;
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
