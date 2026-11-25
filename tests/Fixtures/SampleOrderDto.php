<?php

declare(strict_types=1);

namespace Zokeibi\Tests\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Required {}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Email {}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Date {}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Min
{
    public function __construct(public int $value) {}
}

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class In
{
    /** @param list<string> $allowed */
    public function __construct(public array $allowed) {}
}

enum PlanTier: string
{
    case Starter = 'starter';
    case Pro = 'pro';
    case Enterprise = 'enterprise';
}

final readonly class SampleOrderDto
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        #[Required]
        #[Email]
        public string $customerEmail,
        #[Required]
        #[Min(8)]
        public string $password,
        public PlanTier $plan,
        #[In(['us-east', 'eu-central', 'ap-northeast'])]
        public string $region,
        #[In(['read', 'write', 'deploy'])]
        public array $scopes,
        #[Date]
        public string $launchDate,
        public bool $autoRenew = true,
        public string $body = '',
        public string $notes = '',
    ) {}
}
