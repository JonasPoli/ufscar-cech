<?php

namespace App\Repository;

use App\Entity\SiteSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SiteSetting>
 */
class SiteSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SiteSetting::class);
    }

    /**
     * Retrieves the single SiteSetting instance, creating default one if none exists.
     */
    public function getSettings(): SiteSetting
    {
        $setting = $this->find(SiteSetting::SINGLETON_ID);
        if (!$setting) {
            $setting = new SiteSetting();
            $this->getEntityManager()->persist($setting);
            $this->getEntityManager()->flush();
        }

        return $setting;
    }
}
