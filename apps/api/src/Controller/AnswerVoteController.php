<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AnswerVote;
use App\Entity\User;
use App\Repository\AnswerRepository;
use App\Repository\AnswerVoteRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Votes « OK / Pas OK » sur les réponses, OUVERTS À TOUS (anonymes inclus).
 *
 * Pondération par rôle : public = 1, chercheur = 3, comité = 5. L'identité vient
 * du JWT s'il est présent (poids + dédoublonnage par utilisateur), sinon d'une
 * empreinte d'IP transmise par le front (en-tête X-Voter-Ip). Re-cliquer le même
 * choix retire le vote (bascule).
 */
final class AnswerVoteController
{
    private const WEIGHT_RESEARCHER = 3;
    private const WEIGHT_COMMITTEE = 5;

    public function __construct(
        private readonly AnswerRepository $answers,
        private readonly AnswerVoteRepository $votes,
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/api/answers/{id}/vote', name: 'api_answer_vote', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function vote(int $id, Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $raw = (string) ($data['value'] ?? '');
        $value = match ($raw) {
            'ok' => 1,
            'notok' => -1,
            default => 0,
        };
        if (0 === $value) {
            return new JsonResponse(['error' => 'Valeur de vote invalide (ok|notok).'], 400);
        }

        $answer = $this->answers->find($id) ?? throw new NotFoundHttpException('Réponse introuvable.');

        $user = $this->security->getUser();
        $user = $user instanceof User ? $user : null;
        $voterKey = $this->voterKey($request, $user);
        $weight = $this->weight();

        $existing = $this->votes->findOneByAnswerAndKey($id, $voterKey);
        if (null !== $existing) {
            if ($existing->getValue() === $value) {
                // Re-clic sur le même choix → on retire le vote.
                $this->em->remove($existing);
                $my = 0;
            } else {
                $existing->setValue($value)->setWeight($weight)->setUser($user);
                $my = $value;
            }
        } else {
            $vote = (new AnswerVote($answer, $voterKey))->setValue($value)->setWeight($weight)->setUser($user);
            $this->em->persist($vote);
            $my = $value;
        }
        $this->em->flush();

        return new JsonResponse($this->tally($id) + ['my' => $my]);
    }

    /** Agrégats (+ vote courant) pour un lot de réponses : GET /api/answer-votes?ids=1,2,3 */
    #[Route('/api/answer-votes', name: 'api_answer_votes', methods: ['GET'])]
    public function batch(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map(
            static fn (string $s): int => (int) trim($s),
            explode(',', (string) $request->query->get('ids', '')),
        ), static fn (int $i): bool => $i > 0));
        $ids = \array_slice(array_unique($ids), 0, 100);

        $tallies = [];
        foreach ($this->votes->tallyFor($ids) as $aid => $t) {
            $tallies[$aid] = $this->shape($t);
        }
        $voterKey = $this->voterKey($request, $this->security->getUser() instanceof User ? $this->security->getUser() : null);

        return new JsonResponse([
            'tallies' => (object) $tallies,
            'mine' => (object) $this->votes->myVotes($ids, $voterKey),
        ]);
    }

    private function weight(): int
    {
        if ($this->security->isGranted('ROLE_COMITE')) {
            return self::WEIGHT_COMMITTEE;
        }
        if ($this->security->isGranted('ROLE_RESEARCHER')) {
            return self::WEIGHT_RESEARCHER;
        }

        return 1;
    }

    private function voterKey(Request $request, ?User $user): string
    {
        if (null !== $user) {
            return 'u:'.$user->getId();
        }
        // Anti-bourrage : l'en-tête X-Voter-Ip (IP réelle du visiteur, posée par le
        // front qui appelle l'API côté serveur) n'est HONORÉ que si le pair immédiat
        // est un proxy de CONFIANCE (front interne). En accès direct à l'API par un
        // pair non fiable, l'en-tête est ignoré → on retombe sur l'IP de connexion
        // réelle, non usurpable (plus de clés infinies via un header forgé).
        $ip = null;
        $remote = $request->server->get('REMOTE_ADDR');
        $trusted = Request::getTrustedProxies();
        if ([] !== $trusted && \is_string($remote) && IpUtils::checkIp($remote, $trusted)) {
            $ip = $request->headers->get('X-Voter-Ip');
        }
        $ip = $ip ?: ($request->getClientIp() ?? '0.0.0.0');

        return 'ip:'.substr(hash('sha256', $ip), 0, 40);
    }

    /**
     * @return array{okCount:int,notOkCount:int,okWeight:int,notOkWeight:int,score:int,approval:int|null}
     */
    private function tally(int $id): array
    {
        $t = $this->votes->tallyFor([$id])[$id] ?? ['okWeight' => 0, 'notOkWeight' => 0, 'okCount' => 0, 'notOkCount' => 0];

        return $this->shape($t);
    }

    /**
     * @param array{okWeight:int,notOkWeight:int,okCount:int,notOkCount:int} $t
     *
     * @return array{okCount:int,notOkCount:int,okWeight:int,notOkWeight:int,score:int,approval:int|null}
     */
    private function shape(array $t): array
    {
        $total = $t['okWeight'] + $t['notOkWeight'];

        return [
            'okCount' => $t['okCount'],
            'notOkCount' => $t['notOkCount'],
            'okWeight' => $t['okWeight'],
            'notOkWeight' => $t['notOkWeight'],
            'score' => $t['okWeight'] - $t['notOkWeight'],
            'approval' => $total > 0 ? (int) round($t['okWeight'] / $total * 100) : null,
        ];
    }
}
