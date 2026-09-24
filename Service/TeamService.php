<?php

namespace KimaiPlugin\MileageBundle\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Who may see and approve whose trips.
 */
class TeamService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly AuthorizationCheckerInterface $auth,
    ) {
    }

    /**
     * Users the current user may see in the team report, sorted by name.
     *
     * @return User[]
     */
    public function visibleUsers(User $current): array
    {
        if ($this->auth->isGranted('view_other_mileage') || $this->auth->isGranted('approve_other_mileage')) {
            $users = $this->userRepository->findBy(['enabled' => true]);
        } else {
            $users = [$current->getId() => $current];
            foreach ($current->getTeams() as $team) {
                if (!$current->isTeamleadOf($team)) {
                    continue;
                }
                foreach ($team->getUsers() as $member) {
                    $users[$member->getId()] = $member;
                }
            }
            $users = array_values($users);
        }

        usort($users, static fn (User $a, User $b) => strcasecmp((string) $a->getDisplayName(), (string) $b->getDisplayName()));

        return $users;
    }

    public function canApprove(User $current, User $member): bool
    {
        if ($this->auth->isGranted('approve_other_mileage')) {
            return true;
        }

        // Team leads approve their members, never themselves.
        return $member !== $current && $this->auth->isGranted('approve_mileage') && $current->isTeamleadOfUser($member);
    }

    public function canSeeTeam(): bool
    {
        return $this->auth->isGranted('approve_mileage') || $this->auth->isGranted('approve_other_mileage')
            || $this->auth->isGranted('view_other_mileage') || $this->auth->isGranted('view_team_mileage');
    }
}
