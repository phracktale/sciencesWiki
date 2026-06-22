<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\AnswerVoteRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vote « OK / Pas OK » d'un lecteur sur une réponse (Q/R).
 *
 * Ouvert à tous : un votant est soit un utilisateur connecté (user), soit un
 * anonyme identifié par une empreinte d'IP (voterKey). Unicité par (réponse,
 * votant) → un seul vote, modifiable. Le poids (weight) est figé au moment du
 * vote selon le rôle (chercheur / comité = davantage), pour que le re-calcul
 * du score n'évolue pas si les rôles changent ensuite.
 */
#[ORM\Entity(repositoryClass: AnswerVoteRepository::class)]
#[ORM\Table(name: 'answer_vote')]
#[ORM\UniqueConstraint(name: 'uniq_answer_voter', columns: ['answer_id', 'voter_key'])]
class AnswerVote
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Answer::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Answer $answer;

    /** Utilisateur connecté (null = vote anonyme par IP). */
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    /** Clé de dédoublonnage : « u:<id> » (connecté) ou « ip:<hash> » (anonyme). */
    #[ORM\Column(length: 80)]
    private string $voterKey;

    /** +1 = OK / -1 = Pas OK. */
    #[ORM\Column(type: 'smallint')]
    private int $value;

    /** Poids du vote selon le rôle (1 = public, 3 = chercheur, 5 = comité). */
    #[ORM\Column(type: 'smallint')]
    private int $weight = 1;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Answer $answer, string $voterKey)
    {
        $this->answer = $answer;
        $this->voterKey = $voterKey;
        $this->value = 1;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAnswer(): Answer
    {
        return $this->answer;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getVoterKey(): string
    {
        return $this->voterKey;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): self
    {
        $this->value = $value >= 0 ? 1 : -1;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getWeight(): int
    {
        return $this->weight;
    }

    public function setWeight(int $weight): self
    {
        $this->weight = max(1, $weight);

        return $this;
    }
}
