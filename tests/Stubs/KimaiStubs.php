<?php

/**
 * Minimal stand-ins for Kimai core classes (same public signatures as Kimai 2.67).
 * Only loaded by the unit test bootstrap, never in production.
 */

namespace App\Configuration {
    if (!class_exists(SystemConfiguration::class, false)) {
        final class SystemConfiguration
        {
            /** @param array<string, string|int|bool|float|null> $values */
            public function __construct(private array $values = [])
            {
            }

            public function find(string $key): string|int|bool|float|null
            {
                return $this->values[$key] ?? null;
            }
        }
    }
}

namespace App\Entity {
    if (!class_exists(User::class, false)) {
        class User
        {
            private ?int $id = null;
            /** @var array<string, bool|int|float|string|null> */
            private array $preferences = [];

            public function __construct(?int $id = null)
            {
                $this->id = $id;
            }

            public function getId(): ?int
            {
                return $this->id;
            }

            public function getUserIdentifier(): string
            {
                return 'user' . $this->id;
            }

            public function getDisplayName(): string
            {
                return 'User ' . $this->id;
            }

            public function setPreferenceValue(string $name, mixed $value = null): void
            {
                $this->preferences[$name] = $value;
            }

            public function getPreferenceValue(string $name, mixed $default = null, bool $allowNull = true): bool|int|float|string|null
            {
                $value = $this->preferences[$name] ?? null;
                if ($value === null && !$allowNull) {
                    return $default;
                }

                return $value ?? $default;
            }
        }
    }

    if (!class_exists(Customer::class, false)) {
        class Customer
        {
            public function __construct(private string $name = '', private ?string $address = null, private ?int $id = null)
            {
            }

            public function getId(): ?int
            {
                return $this->id;
            }

            public function getName(): ?string
            {
                return $this->name;
            }

            public function getAddress(): ?string
            {
                return $this->address;
            }
        }
    }

    if (!class_exists(Project::class, false)) {
        class Project
        {
            public function __construct(private string $name = '', private ?Customer $customer = null, private ?int $id = null)
            {
            }

            public function getId(): ?int
            {
                return $this->id;
            }

            public function getName(): ?string
            {
                return $this->name;
            }

            public function getCustomer(): ?Customer
            {
                return $this->customer;
            }
        }
    }

    if (!class_exists(Timesheet::class, false)) {
        class Timesheet
        {
            public function __construct(
                private ?\DateTime $begin = null,
                private ?\DateTime $end = null,
                private ?Project $project = null,
                private ?User $user = null,
                private ?int $id = null,
            ) {
            }

            public function getId(): ?int
            {
                return $this->id;
            }

            public function getBegin(): ?\DateTime
            {
                return $this->begin;
            }

            public function getEnd(): ?\DateTime
            {
                return $this->end;
            }

            public function getDuration(bool $calculate = true): ?int
            {
                if ($this->begin === null || $this->end === null) {
                    return null;
                }

                return $this->end->getTimestamp() - $this->begin->getTimestamp();
            }

            public function getProject(): ?Project
            {
                return $this->project;
            }

            public function getUser(): ?User
            {
                return $this->user;
            }
        }
    }
}
