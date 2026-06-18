<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\TreeEdge;
use App\Entity\TreeNode;
use App\Repository\TreeNodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Édition de la taxonomie réservée à l'admin (cf. spec §7). Créer, renommer,
 * déplacer une rubrique. Toutes les routes /api/admin/* exigent ROLE_ADMIN
 * (cf. security.yaml access_control).
 */
final class AdminNodeController
{
    public function __construct(
        private readonly TreeNodeRepository $nodes,
        private readonly EntityManagerInterface $em,
        private readonly \App\Service\ActivityLogger $activity,
        private readonly \Symfony\Bundle\SecurityBundle\Security $security,
    ) {
    }

    private function actor(): string
    {
        return $this->security->getUser()?->getUserIdentifier() ?? 'admin';
    }

    #[Route('/api/admin/nodes', name: 'admin_node_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $label = trim((string) ($data['label'] ?? ''));
        if ('' === $label) {
            return $this->err('Le libellé est obligatoire.', 422);
        }

        $parent = null;
        if (!empty($data['parentId'])) {
            $parent = $this->nodes->find((int) $data['parentId']);
            if (null === $parent) {
                return $this->err('Rubrique parente introuvable.', 404);
            }
        }

        $node = new TreeNode($this->uniqueSlug($label), $label);
        $node->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $node->setLevel(null !== $parent ? $parent->getLevel() + 1 : 0);
        $this->em->persist($node);
        if (null !== $parent) {
            $this->em->persist(new TreeEdge($parent, $node, true));
        }
        $this->em->flush();

        $this->activity->log('node', 'create', $this->actor(), \sprintf('Rubrique créée : « %s »%s', $label, null !== $parent ? ' sous « '.$parent->getLabel().' »' : ' (domaine racine)'), ['slug' => $node->getSlug()]);

        return new JsonResponse($this->view($node), Response::HTTP_CREATED);
    }

    #[Route('/api/admin/nodes/{id}', name: 'admin_node_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return $this->err('Rubrique introuvable.', 404);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];

        if (isset($data['label']) && '' !== trim((string) $data['label'])) {
            $node->setLabel(trim((string) $data['label']));
        }
        if (\array_key_exists('description', $data)) {
            $node->setDescription(null !== $data['description'] ? (string) $data['description'] : null);
        }
        $this->em->flush();

        $this->activity->log('node', 'rename', $this->actor(), \sprintf('Rubrique modifiée : « %s »', $node->getLabel()), ['slug' => $node->getSlug()]);

        return new JsonResponse($this->view($node));
    }

    #[Route('/api/admin/nodes/{id}/move', name: 'admin_node_move', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function move(int $id, Request $request): JsonResponse
    {
        $node = $this->nodes->find($id);
        if (null === $node) {
            return $this->err('Rubrique introuvable.', 404);
        }
        /** @var array<string,mixed> $data */
        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $parent = !empty($data['parentId']) ? $this->nodes->find((int) $data['parentId']) : null;
        if (null === $parent) {
            return $this->err('Rubrique parente introuvable.', 404);
        }
        if ($parent === $node) {
            return $this->err('Une rubrique ne peut pas être son propre parent.', 422);
        }
        if ($this->isDescendantOrSelf($parent, $node)) {
            return $this->err('Déplacement impossible : créerait un cycle (le parent visé est sous cette rubrique).', 422);
        }

        // Remplace le(s) parent(s) principal(aux) par le nouveau (réattachement simple).
        foreach ($node->getParentEdges() as $edge) {
            $this->em->remove($edge);
        }
        $this->em->persist(new TreeEdge($parent, $node, true));
        $node->setLevel($parent->getLevel() + 1);
        $this->em->flush();

        $this->activity->log('node', 'move', $this->actor(), \sprintf('Rubrique « %s » déplacée sous « %s »', $node->getLabel(), $parent->getLabel()), ['slug' => $node->getSlug(), 'parent' => $parent->getSlug()]);

        return new JsonResponse($this->view($node));
    }

    /** Vrai si $candidate est $node ou un descendant de $node (anti-cycle). */
    private function isDescendantOrSelf(TreeNode $candidate, TreeNode $node): bool
    {
        $stack = [$node];
        $seen = [];
        while ([] !== $stack) {
            $current = array_pop($stack);
            $cid = $current->getId();
            if (null !== $cid && isset($seen[$cid])) {
                continue;
            }
            if (null !== $cid) {
                $seen[$cid] = true;
            }
            if ($current === $candidate) {
                return true;
            }
            foreach ($current->getChildEdges() as $edge) {
                $stack[] = $edge->getChild();
            }
        }

        return false;
    }

    private function uniqueSlug(string $label): string
    {
        $base = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', '-', mb_strtolower($label)), '-');
        $base = '' !== $base ? $base : 'rubrique';
        $slug = $base;
        $i = 2;
        while (null !== $this->nodes->findOneBy(['slug' => $slug])) {
            $slug = $base.'-'.$i++;
        }

        return mb_substr($slug, 0, 255);
    }

    /** @return array<string,mixed> */
    private function view(TreeNode $node): array
    {
        return [
            'id' => $node->getId(),
            'slug' => $node->getSlug(),
            'label' => $node->getLabel(),
            'level' => $node->getLevel(),
        ];
    }

    private function err(string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $message], $status);
    }
}
