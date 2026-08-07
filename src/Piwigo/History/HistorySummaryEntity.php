<?php

declare(strict_types=1);

namespace Piwigo\History;

use Doctrine\ORM\Mapping as ORM;

/**
 * Maps the `history_summary` table -- year/month/day/hour rollup of
 * `history`, one row per granularity level. `month`/`day`/`hour` are
 * nullable on the schema (a lower-granularity summary row leaves the
 * finer columns null).
 */
#[ORM\Entity(repositoryClass: HistoryRepository::class)]
#[ORM\Table(name: 'history_summary')]
final class HistorySummaryEntity
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'summary_id', type: 'integer')]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'integer')]
        public int $year,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $month,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $day,
        #[ORM\Column(type: 'integer', nullable: true)]
        public ?int $hour,
        #[ORM\Column(name: 'nb_pages', type: 'integer', nullable: true)]
        public ?int $nbPages,
        #[ORM\Column(name: 'history_id_from', type: 'integer', nullable: true)]
        public ?int $historyIdFrom,
        #[ORM\Column(name: 'history_id_to', type: 'integer', nullable: true)]
        public ?int $historyIdTo,
    ) {}
}
