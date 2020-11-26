<?php

namespace ANOITCOM\TaskmanagementBundle\Services\Charts\ProcessCards;

use Doctrine\ORM\EntityManagerInterface;

class ProcessCardsChartsService
{

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;


    public function __construct(EntityManagerInterface $entityManager)
    {

        $this->entityManager = $entityManager;
    }


    public function getChartTypes()
    {
        return [
            'perWeek'             => 'Количество процессов по неделям',
            'avgPerDay'           => 'Среднее время выполнение процесса по дням',
            'cardsByBlock'        => 'Внедренные процессы по блокам',
            'donePercentsData'    => 'Процент готовности',
            'upgradesByDistricts' => 'Улучшение процессов по округам',
            'avgCompleteTime'     => 'Среднее время выполнения процесса',
        ];
    }


    public function getChartsDataByType($type)
    {
        switch ($type) {
            case 'perWeek':
                return $this->getCardsPerWeek();

                break;
            case 'avgPerDay':
                return $this->getCardsAvgPerDay();

                break;
            case 'cardsByBlock':
                return $this->getCardsByBlocks();

                break;
            case 'donePercentsData':
                return $this->getCardsDonePercents();

                break;
            case 'upgradesByDistricts':
                return $this->getCardsUpgradesByDistricts();

                break;
            case 'avgCompleteTime':
                return $this->getCardsAvgCompleteTime();

                break;
            default:
                return [];
                break;
        }
    }


    public function getChartsData()
    {
        return [
            'cardsPerWeekData'             => $this->getCardsPerWeek(),
            'cardsAvgPerDayData'           => $this->getCardsAvgPerDay(),
            'cardsByBlocksData'            => $this->getCardsByBlocks(),
            'cardsDonePercentsData'        => $this->getCardsDonePercents(),
            'cardsUpgradesByDistrictsData' => $this->getCardsUpgradesByDistricts(),
            'cardsAvgCompleteTimeData'     => $this->getCardsAvgCompleteTime()
        ];
    }


    private function getCardsPerWeek()
    {
        $sql = '
            SELECT DATE_TRUNC(\'week\', date_end) AS date,
            max(count_on_week) AS "value"
            FROM
              (select project_districts.district,
                      case
                          when wiki_views_process_cards.date_end < now()
                               AND status = \'Закрыта\' then true
                          ELSE false
                      END as vnedren,
                      EXTRACT(WEEK
                              FROM date_end) as week,
                                        row_number() OVER (
                                         ORDER BY date_end ASC) AS count_on_week,
                                        case
                                            when 1 = 1 then 30
                                            ELSE 2
                                        END as was_day,
                                        case
                                            when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                            when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                            when activity_unit = \'Календарные дни\' then duration_after
                                            when activity_unit = \'Часы\' then duration_after/24
                                            ELSE 0
                                        END as duration_new_day,
                                        wiki_views_process_cards.object_id,
                                        wiki_views_process_cards.title,
                                        wiki_views_process_cards.parent_id,
                                        wiki_views_process_cards.parent,
                                        wiki_views_process_cards.project_id,
                                        wiki_views_process_cards.project,
                                        wiki_views_process_cards.priority,
                                        wiki_views_process_cards.status,
                                        wiki_views_process_cards.person_id,
                                        wiki_views_process_cards.person,
                                        wiki_views_process_cards.date_start,
                                        wiki_views_process_cards.date_end,
                                        TRIM(wiki_views_process_cards.block) block,
                                        wiki_views_process_cards.tasks_count_after,
                                        wiki_views_process_cards.person_count_before,
                                        wiki_views_process_cards.person_count_after,
                                        wiki_views_process_cards.duration_before,
                                        wiki_views_process_cards.duration_after,
                                        wiki_views_process_cards.activity_unit,
                                        wiki_views_process_cards.messages_per_month,
                                        wiki_views_process_cards.canal,
                                        wiki_views_process_cards.version,
                                        wiki_views_process_cards.tasks_count_before,
                                        wiki_views_process_cards.tracker_id,
                                        wiki_views_process_cards.tracker,
                                        wiki_views_process_cards.updated_at,
                                        wiki_views_process_cards.last_editor,
                                        wiki_views_process_cards.theme,
                                        wiki_views_process_cards.author,
                                        wiki_views_process_cards.closed,
                                        wiki_views_process_cards.created_at
               from wiki_views_process_cards
               left join project_districts on project_districts.project = wiki_views_process_cards.project
               where wiki_views_process_cards.date_end < now()
                 AND status = \'Закрыта\') AS expr_qry
            GROUP BY DATE_TRUNC(\'week\', date_end)
            ORDER BY "value" DESC
            LIMIT 50000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return
            [
                'title'  => 'Количество процессов по неделям',
                'values' => $request->fetchAll()
            ];
    }


    private function getCardsAvgPerDay()
    {
        $sql = '
            SELECT DATE_TRUNC(\'week\', date_end) AS date,
            min(avg_week) AS "value"
            FROM
              (select project_districts.district,
                      case
                          when wiki_views_process_cards.date_end < now()
                               AND status = \'Закрыта\' then true
                          ELSE false
                      END as vnedren,
                      EXTRACT(WEEK
                              FROM date_end) as week,
                      row_number() OVER (
                                         ORDER BY date_end ASC) AS count_on_week,
                                        case
                                            when 1 = 1 then 30
                                            ELSE 2
                                        END as was_day,
                                        case
                                            when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                            when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                            when activity_unit = \'Календарные дни\' then duration_after
                                            when activity_unit = \'Часы\' then duration_after/24
                                            ELSE 0
                                        END as duration_new_day,
                            sum(case
                                    when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                    when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                    when activity_unit = \'Календарные дни\' then duration_after
                                    when activity_unit = \'Часы\' then duration_after/24
                                    ELSE 0
                                END) OVER (
                                           ORDER BY date_end ASC) AS sum_on_week,
                                          round(sum(case
                                                        when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                                        when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                                        when activity_unit = \'Календарные дни\' then duration_after
                                                        when activity_unit = \'Часы\' then duration_after/24
                                                        ELSE 0
                                                    END) OVER (
                                                               ORDER BY date_end ASC) / row_number() OVER (
                                                                                                           ORDER BY date_end ASC)) as avg_week,
                                          wiki_views_process_cards.object_id,
                                          wiki_views_process_cards.title,
                                          wiki_views_process_cards.parent_id,
                                          wiki_views_process_cards.parent,
                                          wiki_views_process_cards.project_id,
                                          wiki_views_process_cards.project,
                                          wiki_views_process_cards.priority,
                                          wiki_views_process_cards.status,
                                          wiki_views_process_cards.person_id,
                                          wiki_views_process_cards.person,
                                          wiki_views_process_cards.date_start,
                                          wiki_views_process_cards.date_end,
                                          TRIM(wiki_views_process_cards.block) block,
                                          wiki_views_process_cards.tasks_count_after,
                                          wiki_views_process_cards.person_count_before,
                                          wiki_views_process_cards.person_count_after,
                                          wiki_views_process_cards.duration_before,
                                          wiki_views_process_cards.duration_after,
                                          wiki_views_process_cards.activity_unit,
                                          wiki_views_process_cards.messages_per_month,
                                          wiki_views_process_cards.canal,
                                          wiki_views_process_cards.version,
                                          wiki_views_process_cards.tasks_count_before,
                                          wiki_views_process_cards.tracker_id,
                                          wiki_views_process_cards.tracker,
                                          wiki_views_process_cards.updated_at,
                                          wiki_views_process_cards.last_editor,
                                          wiki_views_process_cards.theme,
                                          wiki_views_process_cards.author,
                                          wiki_views_process_cards.closed,
                                          wiki_views_process_cards.created_at
               from wiki_views_process_cards
               left join project_districts on project_districts.project = wiki_views_process_cards.project
               where wiki_views_process_cards.date_end < now()
                 AND status = \'Закрыта\'
               order by week asc) AS expr_qry
            WHERE date_end >= \'1920-11-05 00:00:00.000000\'
            GROUP BY DATE_TRUNC(\'week\', date_end)
            ORDER BY "value" DESC
            LIMIT 1000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return [
            'title'  => 'Среднее время выполнение процесса по дням',
            'values' => $request->fetchAll()
        ];
    }


    private function getCardsByBlocks()
    {
        $sql = '
            SELECT TRIM(block) AS block,
                count(object_id) AS "value"
            FROM
              (select trim(project_districts.district),
                      case
                          when wiki_views_process_cards.date_end < now()
                               AND status = \'Закрыта\' then true
                          ELSE false
                      END as vnedren,
                      EXTRACT(WEEK
                              FROM date_end) as week,
                      case
                          when 1 = 1 then 30
                          ELSE 2
                      END as was_day,
                      case
                          when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                          when activity_unit = \'Рабочие дни\' then duration_after*1.3
                          when activity_unit = \'Календарные дни\' then duration_after
                          when activity_unit = \'Часы\' then duration_after/24
                          ELSE 0
                      END as duration_new_day,
                      wiki_views_process_cards.object_id,
                      wiki_views_process_cards.title,
                      wiki_views_process_cards.parent_id,
                      wiki_views_process_cards.parent,
                      wiki_views_process_cards.project_id,
                      wiki_views_process_cards.project,
                      wiki_views_process_cards.priority,
                      wiki_views_process_cards.status,
                      wiki_views_process_cards.person_id,
                      wiki_views_process_cards.person,
                      wiki_views_process_cards.date_start,
                      wiki_views_process_cards.date_end,
                      TRIM(wiki_views_process_cards.block) block,
                      wiki_views_process_cards.tasks_count_after,
                      wiki_views_process_cards.person_count_before,
                      wiki_views_process_cards.person_count_after,
                      wiki_views_process_cards.duration_before,
                      wiki_views_process_cards.duration_after,
                      wiki_views_process_cards.activity_unit,
                      wiki_views_process_cards.messages_per_month,
                      wiki_views_process_cards.canal,
                      wiki_views_process_cards.version,
                      wiki_views_process_cards.tasks_count_before,
                      wiki_views_process_cards.tracker_id,
                      wiki_views_process_cards.tracker,
                      wiki_views_process_cards.updated_at,
                      wiki_views_process_cards.last_editor,
                      wiki_views_process_cards.theme,
                      wiki_views_process_cards.author,
                      wiki_views_process_cards.closed,
                      wiki_views_process_cards.created_at
               from wiki_views_process_cards
               left join project_districts on trim(project_districts.project) = trim(wiki_views_process_cards.project)) AS expr_qry
            WHERE vnedren = \'true\'
            GROUP BY TRIM(block)
            ORDER BY "value" DESC
            LIMIT 50000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return [
            'title'  => 'Внедренные процессы по блокам',
            'values' => $request->fetchAll()
        ];
    }


    private function getCardsDonePercents()
    {
        $sql = '
            SELECT district AS district,
                   "all" AS "all",
                   done AS done,
                   percent AS percent
            FROM
              (select project_districts.district,
                      count(object_id) as all,
            
                 (select count(object_id)
                  from wiki_views_process_cards
                  left join project_districts as dis on trim(dis.project) = trim(wiki_views_process_cards.project)
                  where wiki_views_process_cards.date_end < now()
                    AND status = \'Закрыта\'
                    AND trim(dis.district) = trim(project_districts.district)) as done,
            
                 (select count(object_id)
                  from wiki_views_process_cards
                  left join project_districts as dis on trim(dis.project) = trim(wiki_views_process_cards.project)
                  where wiki_views_process_cards.date_end < now()
                    AND status = \'Закрыта\'
                    AND dis.district = project_districts.district)*100/count(object_id) as percent
               from wiki_views_process_cards
               left join project_districts on trim(project_districts.project) = trim(wiki_views_process_cards.project)
               where project_districts.district is not null
               group by project_districts.district
               order by done DESC) AS expr_qry
            LIMIT 1000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return [
            'title'  => 'Процент готовности',
            'values' => $request->fetchAll()
        ];
    }


    private function getCardsAvgCompleteTime()
    {
        $sql = '
            SELECT TRIM(block) AS block,
                sum(duration_new_day) AS "value"
            FROM
              (select TRIM(block) block,
                      case
                          when 1 = 1 then 30
                          ELSE 2
                      END as was_day,
                      ROUND(AVG(case
                                    when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                    when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                    when activity_unit = \'Календарные дни\' then duration_after
                                    when activity_unit = \'Часы\' then duration_after/24
                                    ELSE 0
                                END)) as duration_new_day,
                      (case
                           when 1 = 1 then 30
                           ELSE 2
                       END) - ROUND(AVG(case
                                            when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                            when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                            when activity_unit = \'Календарные дни\' then duration_after
                                            when activity_unit = \'Часы\' then duration_after/24
                                            ELSE 0
                                        END)) as diff
               from wiki_views_process_cards
               left join project_districts on trim(project_districts.project) = trim(wiki_views_process_cards.project)
               where project_districts.district is not null
                 AND wiki_views_process_cards.date_end < now()
                 AND status = \'Закрыта\'
               group by TRIM(block)
               order by duration_new_day ASC) AS expr_qry
            GROUP BY TRIM(block)
            ORDER BY "value" DESC
            LIMIT 1000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return [
            'title'  => 'Среднее время выполнения процесса',
            'values' => $request->fetchAll()
        ];
    }


    private function getCardsUpgradesByDistricts()
    {
        $sql = '
            SELECT district AS district,
                   was_day AS was_day,
                   duration_new_day AS duration_new_day,
                   diff AS diff
            FROM
              (select project_districts.district,
                      case
                          when 1 = 1 then 30
                          ELSE 2
                      END as was_day,
                      ROUND(AVG(case
                                    when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                    when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                    when activity_unit = \'Календарные дни\' then duration_after
                                    when activity_unit = \'Часы\' then duration_after/24
                                    ELSE 0
                                END)) as duration_new_day,
                      ROUND(AVG(case
                                    when activity_unit = \'Рабочие часы\' then duration_after/8*1.3
                                    when activity_unit = \'Рабочие дни\' then duration_after*1.3
                                    when activity_unit = \'Календарные дни\' then duration_after
                                    when activity_unit = \'Часы\' then duration_after/24
                                    ELSE 0
                                END))- (case
                                            when 1 = 1 then 30
                                            ELSE 2
                                        END) as diff
               from wiki_views_process_cards
               left join project_districts on trim(project_districts.project) = trim(wiki_views_process_cards.project)
               where wiki_views_process_cards.date_end < now()
                 AND status = \'Закрыта\'
               group by project_districts.district
               order by diff DESC) AS expr_qry
            LIMIT 1000;
        ';

        $request = $this->entityManager->getConnection()->prepare($sql);
        $request->execute();

        return [
            'title'  => 'Улучшение процессов по округам',
            'values' => $request->fetchAll()
        ];
    }
}