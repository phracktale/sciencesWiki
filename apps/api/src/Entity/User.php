<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\ProfileType;
use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Compte utilisateur (cf. spec §4/§9.4). « Rien d'anonyme » côté contenu :
 * identité vérifiée obligatoire pour rédiger (nom réel ou pseudo, mais traçable).
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(name: 'uniq_user_email', columns: ['email'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    #[ORM\Column]
    private string $password = '';

    #[ORM\Column(length: 255)]
    private string $realName;

    /** Pseudonyme public (optionnel) ; affiché à la place du nom réel s'il existe. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $pseudo = null;

    #[ORM\Column(length: 16, enumType: ProfileType::class)]
    private ProfileType $profileType = ProfileType::Contributor;

    #[ORM\Column]
    private bool $identityVerified = false;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $verificationMethod = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $orcid = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $affiliation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $bio = null;

    #[ORM\Column]
    private int $reputation = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /** @var Collection<int,DomainExpertise> périmètre de compétence (comité/relecteur) */
    #[ORM\OneToMany(targetEntity: DomainExpertise::class, mappedBy: 'user', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $expertise;

    public function __construct(string $email, string $realName)
    {
        $this->email = $email;
        $this->realName = $realName;
        $this->createdAt = new \DateTimeImmutable();
        $this->expertise = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    public function setRoles(array $roles): self
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    public function addRole(string $role): self
    {
        if (!\in_array($role, $this->roles, true)) {
            $this->roles[] = $role;
        }

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
    }

    public function getRealName(): string
    {
        return $this->realName;
    }

    public function setRealName(string $realName): self
    {
        $this->realName = $realName;

        return $this;
    }

    public function getPseudo(): ?string
    {
        return $this->pseudo;
    }

    public function setPseudo(?string $pseudo): self
    {
        $this->pseudo = $pseudo;

        return $this;
    }

    /** Nom affiché publiquement : pseudo si présent, sinon nom réel. */
    public function getDisplayName(): string
    {
        return $this->pseudo ?? $this->realName;
    }

    public function getProfileType(): ProfileType
    {
        return $this->profileType;
    }

    public function setProfileType(ProfileType $profileType): self
    {
        $this->profileType = $profileType;

        return $this;
    }

    public function isIdentityVerified(): bool
    {
        return $this->identityVerified;
    }

    public function verifyIdentity(string $method): self
    {
        $this->identityVerified = true;
        $this->verificationMethod = $method;

        return $this;
    }

    public function getVerificationMethod(): ?string
    {
        return $this->verificationMethod;
    }

    public function getOrcid(): ?string
    {
        return $this->orcid;
    }

    public function setOrcid(?string $orcid): self
    {
        $this->orcid = $orcid;

        return $this;
    }

    public function getAffiliation(): ?string
    {
        return $this->affiliation;
    }

    public function setAffiliation(?string $affiliation): self
    {
        $this->affiliation = $affiliation;

        return $this;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;

        return $this;
    }

    public function getReputation(): int
    {
        return $this->reputation;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int,DomainExpertise> */
    public function getExpertise(): Collection
    {
        return $this->expertise;
    }

    public function addExpertise(DomainExpertise $expertise): self
    {
        if (!$this->expertise->contains($expertise)) {
            $this->expertise->add($expertise);
            $expertise->setUser($this);
        }

        return $this;
    }

    /** L'utilisateur est-il compétent (comité/relecteur) sur ce nœud ? */
    public function hasExpertiseOn(TreeNode $node): bool
    {
        foreach ($this->expertise as $expertise) {
            if ($expertise->getTreeNode() === $node) {
                return true;
            }
        }

        return false;
    }
}
