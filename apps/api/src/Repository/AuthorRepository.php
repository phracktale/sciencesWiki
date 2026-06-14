<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Author;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Author>
 */
class AuthorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Author::class);
    }

    /**
     * Dédoublonnage léger : par ORCID si présent, sinon par nom exact.
     */
    public function findOneByOrcidOrName(?string $orcid, string $name): ?Author
    {
        if (null !== $orcid && '' !== $orcid) {
            $byOrcid = $this->findOneBy(['orcid' => $orcid]);
            if (null !== $byOrcid) {
                return $byOrcid;
            }
        }

        return $this->findOneBy(['name' => $name, 'orcid' => null]);
    }
}
