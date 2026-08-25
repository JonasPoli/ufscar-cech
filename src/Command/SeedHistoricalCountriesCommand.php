<?php

namespace App\Command;

use App\Entity\Country;
use App\Entity\CountryVariation;
use App\Service\Thesaurus\StringNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:geography:seed-historical-countries',
    description: 'Seed and update historical countries with foundation and extinction years and variations'
)]
class SeedHistoricalCountriesCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title("Atualizando Países Históricos e Datas de Fundação/Extinção");

        $countriesData = [
            // Extinct / Dissolved Countries
            [
                'commonName' => 'União Soviética',
                'officialName' => 'União das Repúblicas Socialistas Soviéticas',
                'isoAlpha2' => 'SU',
                'isoAlpha3' => 'SUN',
                'foundationYear' => 1922,
                'extinctionYear' => 1991,
                'variations' => ['URSS', 'USSR', 'Soviet Union', 'CCCP', 'Union of Soviet Socialist Republics', 'União Soviética'],
            ],
            [
                'commonName' => 'Iugoslávia',
                'officialName' => 'República Socialista Federativa da Iugoslávia',
                'isoAlpha2' => 'YU',
                'isoAlpha3' => 'YUG',
                'foundationYear' => 1918,
                'extinctionYear' => 1992,
                'variations' => ['Iugoslávia', 'Yugoslavia', 'SFR Yugoslavia', 'Yugoslávia', 'República Socialista Federativa da Iugoslávia'],
            ],
            [
                'commonName' => 'Sérvia e Montenegro',
                'officialName' => 'República Federal da Iugoslávia',
                'isoAlpha2' => 'CS',
                'isoAlpha3' => 'SCG',
                'foundationYear' => 1992,
                'extinctionYear' => 2006,
                'variations' => ['Sérvia e Montenegro', 'Serbia and Montenegro', 'República Federal da Iugoslávia', 'FR Yugoslavia'],
            ],
            [
                'commonName' => 'Tchecoslováquia',
                'officialName' => 'República da Tchecoslováquia',
                'isoAlpha2' => 'CS',
                'isoAlpha3' => 'CSK',
                'foundationYear' => 1918,
                'extinctionYear' => 1992,
                'variations' => ['Tchecoslováquia', 'Czechoslovakia', 'Checoslováquia', 'CZECHOSLOVAKIA'],
            ],
            [
                'commonName' => 'Alemanha Oriental',
                'officialName' => 'República Democrática Alemã',
                'isoAlpha2' => 'DD',
                'isoAlpha3' => 'DDR',
                'foundationYear' => 1949,
                'extinctionYear' => 1990,
                'variations' => ['Alemanha Oriental', 'RDA', 'RDT', 'German Democratic Republic', 'GDR', 'East Germany'],
            ],
            [
                'commonName' => 'Iêmen do Sul',
                'officialName' => 'República Democrática Popular do Iêmen',
                'isoAlpha2' => 'YD',
                'isoAlpha3' => 'YMD',
                'foundationYear' => 1967,
                'extinctionYear' => 1990,
                'variations' => ['Iêmen do Sul', 'South Yemen', 'Democratic Yemen'],
            ],

            // Newly Formed Countries & Major Dates
            [
                'commonName' => 'Brasil',
                'officialName' => 'República Federativa do Brasil',
                'isoAlpha2' => 'BR',
                'isoAlpha3' => 'BRA',
                'foundationYear' => 1822,
                'extinctionYear' => null,
                'variations' => ['Brazil', 'República Federativa do Brasil', 'BR', 'Federative Republic of Brazil'],
            ],
            [
                'commonName' => 'Afeganistão',
                'officialName' => 'Islamic Republic of Afghanistan',
                'isoAlpha2' => 'AF',
                'isoAlpha3' => 'AFG',
                'foundationYear' => 1919,
                'extinctionYear' => null,
                'variations' => ['Afghanistan', 'Islamic Republic of Afghanistan', 'AF'],
            ],
            [
                'commonName' => 'Sudão do Sul',
                'officialName' => 'República do Sudão do Sul',
                'isoAlpha2' => 'SS',
                'isoAlpha3' => 'SSD',
                'foundationYear' => 2011,
                'extinctionYear' => null,
                'variations' => ['Sudão do Sul', 'South Sudan', 'Republic of South Sudan'],
            ],
            [
                'commonName' => 'Kosovo',
                'officialName' => 'República do Kosovo',
                'isoAlpha2' => 'XK',
                'isoAlpha3' => 'XKX',
                'foundationYear' => 2008,
                'extinctionYear' => null,
                'variations' => ['Kosovo', 'Republic of Kosovo'],
            ],
            [
                'commonName' => 'Montenegro',
                'officialName' => 'Montenegro',
                'isoAlpha2' => 'ME',
                'isoAlpha3' => 'MNE',
                'foundationYear' => 2006,
                'extinctionYear' => null,
                'variations' => ['Montenegro'],
            ],
            [
                'commonName' => 'Sérvia',
                'officialName' => 'República da Sérvia',
                'isoAlpha2' => 'RS',
                'isoAlpha3' => 'SRB',
                'foundationYear' => 2006,
                'extinctionYear' => null,
                'variations' => ['Sérvia', 'Serbia', 'Republic of Serbia'],
            ],
            [
                'commonName' => 'Timor-Leste',
                'officialName' => 'República Democrática de Timor-Leste',
                'isoAlpha2' => 'TL',
                'isoAlpha3' => 'TLS',
                'foundationYear' => 2002,
                'extinctionYear' => null,
                'variations' => ['Timor-Leste', 'East Timor', 'Democratic Republic of Timor-Leste'],
            ],
            [
                'commonName' => 'Eritreia',
                'officialName' => 'Estado da Eritreia',
                'isoAlpha2' => 'ER',
                'isoAlpha3' => 'ERI',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['Eritreia', 'Eritrea'],
            ],
            [
                'commonName' => 'República Tcheca',
                'officialName' => 'República Tcheca',
                'isoAlpha2' => 'CZ',
                'isoAlpha3' => 'CZE',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['República Tcheca', 'Czech Republic', 'Czechia', 'Tchequia'],
            ],
            [
                'commonName' => 'Eslováquia',
                'officialName' => 'República Eslovaca',
                'isoAlpha2' => 'SK',
                'isoAlpha3' => 'SVK',
                'foundationYear' => 1993,
                'extinctionYear' => null,
                'variations' => ['Eslováquia', 'Slovakia', 'Slovak Republic'],
            ],
            [
                'commonName' => 'Croácia',
                'officialName' => 'República da Croácia',
                'isoAlpha2' => 'HR',
                'isoAlpha3' => 'HRV',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Croácia', 'Croatia'],
            ],
            [
                'commonName' => 'Eslovênia',
                'officialName' => 'República da Eslovênia',
                'isoAlpha2' => 'SI',
                'isoAlpha3' => 'SVN',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Eslovênia', 'Slovenia'],
            ],
            [
                'commonName' => 'Bósnia e Herzegovina',
                'officialName' => 'Bósnia e Herzegovina',
                'isoAlpha2' => 'BA',
                'isoAlpha3' => 'BIH',
                'foundationYear' => 1992,
                'extinctionYear' => null,
                'variations' => ['Bósnia e Herzegovina', 'Bosnia and Herzegovina', 'Bosnia'],
            ],
            [
                'commonName' => 'Macedônia do Norte',
                'officialName' => 'República da Macedônia do Norte',
                'isoAlpha2' => 'MK',
                'isoAlpha3' => 'MKD',
                'foundationYear' => 1991,
                'extinctionYear' => null,
                'variations' => ['Macedônia do Norte', 'North Macedonia', 'Macedonia', 'FYROM'],
            ],
            [
                'commonName' => 'Namíbia',
                'officialName' => 'República da Namíbia',
                'isoAlpha2' => 'NA',
                'isoAlpha3' => 'NAM',
                'foundationYear' => 1990,
                'extinctionYear' => null,
                'variations' => ['Namíbia', 'Namibia'],
            ],
        ];

        $repo = $this->em->getRepository(Country::class);
        $addedCount = 0;
        $updatedCount = 0;

        foreach ($countriesData as $item) {
            $country = null;
            if (!empty($item['isoAlpha3'])) {
                $country = $repo->findOneBy(['isoAlpha3' => $item['isoAlpha3']]);
            }
            if (!$country && !empty($item['isoAlpha2'])) {
                $country = $repo->findOneBy(['isoAlpha2' => $item['isoAlpha2']]);
            }
            if (!$country) {
                $country = $repo->findOneBy(['commonName' => $item['commonName']]);
            }

            if (!$country) {
                $country = new Country();
                $addedCount++;
            } else {
                $updatedCount++;
            }

            $country->setCommonName($item['commonName']);
            $country->setOfficialName($item['officialName']);
            $country->setIsoAlpha2($item['isoAlpha2']);
            $country->setIsoAlpha3($item['isoAlpha3']);
            $country->setFoundationYear($item['foundationYear']);
            $country->setExtinctionYear($item['extinctionYear']);
            $country->setStatus(true);

            $this->em->persist($country);
            $this->em->flush();

            // Sync variations
            $existingVars = [];
            foreach ($country->getVariations() as $v) {
                $existingVars[$v->getNormalizedName()] = true;
            }

            foreach ($item['variations'] as $varName) {
                $norm = StringNormalizer::normalizeString($varName, true);
                if ($norm !== '' && !isset($existingVars[$norm])) {
                    $v = new CountryVariation();
                    $v->setCountry($country);
                    $v->setVariationName($varName);
                    $v->setNormalizedName($norm);
                    $v->setVariationType('alternative');
                    $this->em->persist($v);
                    $existingVars[$norm] = true;
                }
            }
        }

        $this->em->flush();

        $io->success("Concluído: {$addedCount} novos países adicionados, {$updatedCount} países atualizados com datas de fundação e extinção!");
        return Command::SUCCESS;
    }
}
