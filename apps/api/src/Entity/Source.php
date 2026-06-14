<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ApiType;
use App\Repository\SourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Une source de publications en libre accès branchée à la moissonneuse (cf. spec §3.1).
 */
#[ORM\Entity(repositoryClass: SourceRepository::class)]
#[ORM\Table(name: 'source')]
class Source
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $code;

    #[ORM\Column(length: 255)]
    private string $name;

    #[ORM\Column(enumType: ApiType::class)]
    private ApiType $apiType;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $defaultLicense = null;

    #[ORM\Column]
    private bool $active = false;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $phase = 1;

    /** @var array<string,mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $config = [];

    public function __construct(string $code, string $name, ApiType $apiType)
    {
        $this->code = $code;
        $this->name = $name;
        $this->apiType = $apiType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getApiType(): ApiType
    {
        return $this->apiType;
    }

    public function setApiType(ApiType $apiType): self
    {
        $this->apiType = $apiType;

        return $this;
    }

    public function getDefaultLicense(): ?string
    {
        return $this->defaultLicense;
    }

    public function setDefaultLicense(?string $defaultLicense): self
    {
        $this->defaultLicense = $defaultLicense;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getPhase(): int
    {
        return $this->phase;
    }

    public function setPhase(int $phase): self
    {
        $this->phase = $phase;

        return $this;
    }

    /** @return array<string,mixed> */
    public function getConfig(): array
    {
        return $this->config;
    }

    /** @param array<string,mixed> $config */
    public function setConfig(array $config): self
    {
        $this->config = $config;

        return $this;
    }
}
