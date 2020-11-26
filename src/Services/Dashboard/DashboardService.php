<?php

namespace ANOITCOM\TaskmanagementBundle\Services\Dashboard;

use ANOITCOM\Wiki\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;


    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }


    public function userCanChangeColors(User $user)
    {
        if ($user->isAdminPanelAllow()) {
            return true;
        }

        $groupId = isset($_ENV['DASHBOARD_EDIT_ROLE']) ? $_ENV['DASHBOARD_EDIT_ROLE'] : null;

        if (null === $groupId) {
            return false;
        }

        if (in_array($groupId, $user->getGroupsIds())) {
            return true;
        }

        return false;
    }


    public function getRegionsData()
    {
        $regionsSql = '
            SELECT *
            FROM project_districts pd;
        ';

        $regionsRequest = $this->entityManager->getConnection()->prepare($regionsSql);
        $regionsRequest->execute();

        $regionsResult = $regionsRequest->fetchAll();

        usort($regionsResult, function ($a, $b) {
            return $a['project'] > $b['project'];
        });

        return $regionsResult;
    }


    public function updateRegionsStatuses($statuses, $reset = false)
    {
        try {
            if ($reset) {
                $sql = '
                    UPDATE project_districts SET color = \'\';
                ';

                $regionsRequest = $this->entityManager->getConnection()->prepare($sql);

                $regionsRequest->execute();

                return true;
            }

            $colors = [];

            foreach ($statuses as $regionName => $colorValue) {
                $colors[$colorValue['color']] = [];
            }

            foreach ($statuses as $regionName => $colorValue) {
                $colors[$colorValue['color']][] = $regionName;
            }

            foreach ($colors as $color => $regionNames) {
                if (empty($regionNames)) {
                    continue;
                }

                $regionNamesString = implode('\',\'', $regionNames);

                $sql = '
                    UPDATE project_districts SET color = \'' . $color . '\'
                    WHERE project IN (\'' . $regionNamesString . '\');
                ';

                $regionsRequest = $this->entityManager->getConnection()->prepare($sql);

                $regionsRequest->execute();
            }

        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }


    public function getMapData(): array
    {
        $statusesSql = '
            SELECT TRIM(wvt_subquery.region) region, wvt_subquery.stage, wvt_subquery.tracker, wvt_subquery.status, pd.district, pd.color, COUNT(wvt_subquery.status)
            FROM (
              SELECT DISTINCT(wvt1.task), wvt1.region, wvt1.tracker, wvt1.status, wvt1.stage
              FROM wiki_views_tasks wvt1
            ) as wvt_subquery
            JOIN project_districts pd ON TRIM(pd.project) = TRIM(wvt_subquery.region)
            WHERE wvt_subquery.status IS NOT NULL
            GROUP BY wvt_subquery.region, wvt_subquery.stage, wvt_subquery.tracker, wvt_subquery.status, pd.district, pd.color;
        ';

        $statusesRequest = $this->entityManager->getConnection()->prepare($statusesSql);
        $statusesRequest->execute();

        $statusesResult = $statusesRequest->fetchAll();

        $regionTasksCount           = [];
        $regionTasksStageOneCount   = [];
        $regionTasksStageTwoCount   = [];
        $regionDoneStageOneStatuses = [];
        $regionDoneStageTwoStatuses = [];
        $regionPercentOfDone        = [];
        $regionMarkOfDone           = [];
        $districtsGrouped           = [];
        $groupedByStages            = [];
        $percentsOfDone             = [];
        $customColors               = [];

        foreach ($statusesResult as $status) {
            $percentsOfDone[$status['region']]  = [];
            $groupedByStages[$status['region']] = [];
        }

        foreach ($statusesResult as $status) {
            $percentsOfDone[$status['region']][$status['tracker']]  = 0;
            $groupedByStages[$status['region']][$status['tracker']] = [];
        }

        foreach ($statusesResult as $status) {
            if (trim($status['stage']) === '') {
                continue;
            }

            $groupedByStages[$status['region']][$status['tracker']][$status['stage']] = [
                'total'         => 0,
                'done'          => 0,
                'percentOfDone' => 0,
            ];
        }

        foreach ($statusesResult as $status) {
            if (trim($status['stage']) === '') {
                continue;
            }

            if ($status['status'] === 'Закрыта') {
                $groupedByStages[$status['region']][$status['tracker']][$status['stage']]['done']  += $status['count'];
                $groupedByStages[$status['region']][$status['tracker']][$status['stage']]['total'] += $status['count'];
            } else {
                $groupedByStages[$status['region']][$status['tracker']][$status['stage']]['total'] += $status['count'];
            }
        }

        foreach ($groupedByStages as $regionName => $trackers) {
            foreach ($trackers as $trackerName => $stages) {
                foreach ($stages as $stageName => $stageValues) {
                    $groupedByStages[$regionName][$trackerName][$stageName]['percentOfDone'] = round($stageValues['done'] / $stageValues['total'] * 100);
                }
            }
        }

        foreach ($groupedByStages as $regionName => $trackers) {
            foreach ($trackers as $trackerName => $stages) {
                $percentOfDone = 0;

                foreach ($stages as $stageName => $stageValues) {
                    $percentOfDone += $stageValues['percentOfDone'];
                }

                $percentsOfDone[$regionName][$trackerName] = $percentOfDone / count($stages);
            }
        }

        foreach ($statusesResult as $status) {
            if ($status['color'] !== null) {
                $customColors[$status['region']] = $status['color'];
            }

            if ($status['district']) {
                if (isset($districtsGrouped[$status['district']])) {
                    if ( ! array_key_exists($status['region'], $districtsGrouped[$status['district']])) {
                        $districtsGrouped[$status['district']][$status['region']] = null;
                    }
                } else {
                    $districtsGrouped[$status['district']] = [ $status['region'] => null ];
                }
            }

            if ( ! isset($regionTasksCount[$status['region']])) {
                $regionTasksCount[$status['region']] = $status['count'];
            } else {
                $regionTasksCount[$status['region']] += $status['count'];
            }

            if ($status['tracker'] === 'Этап Подготовительный') {
                if ( ! isset($regionTasksStageOneCount[$status['region']])) {
                    $regionTasksStageOneCount[$status['region']] = $status['count'];
                } else {
                    $regionTasksStageOneCount[$status['region']] += $status['count'];
                }
            }

            if ($status['tracker'] === 'Этап Создание') {
                if ( ! isset($regionTasksStageTwoCount[$status['region']])) {
                    $regionTasksStageTwoCount[$status['region']] = $status['count'];
                } else {
                    $regionTasksStageTwoCount[$status['region']] += $status['count'];
                }
            }

            if ($status['status'] === 'Закрыта' && $status['tracker'] === 'Этап 1') {
                if ( ! isset($regionDoneStageOneStatuses[$status['region']])) {
                    $regionDoneStageOneStatuses[$status['region']] = $status['count'];
                } else {
                    $regionDoneStageOneStatuses[$status['region']] += $status['count'];
                }
            }

            if ($status['status'] === 'Закрыта' && $status['tracker'] === 'Этап 2') {
                if ( ! isset($regionDoneStageTwoStatuses[$status['region']])) {
                    $regionDoneStageTwoStatuses[$status['region']] = $status['count'];
                } else {
                    $regionDoneStageTwoStatuses[$status['region']] += $status['count'];
                }
            }
        }

        foreach ($regionTasksCount as $regionName => $valueOfCount) {
            if ( ! isset($regionDoneStageOneStatuses[$regionName])) {
                $regionDoneStageOneStatuses[$regionName] = 0;
            }

            if ( ! isset($regionDoneStageTwoStatuses[$regionName])) {
                $regionDoneStageTwoStatuses[$regionName] = 0;
            }

            if ( ! isset($regionTasksStageOneCount[$regionName])) {
                $regionTasksStageOneCount[$regionName] = 0;
            }

            if ( ! isset($regionTasksStageTwoCount[$regionName])) {
                $regionTasksStageTwoCount[$regionName] = 0;
            }

            $doneCountStageOne  = $regionDoneStageOneStatuses[$regionName];
            $totalCountStageOne = $regionTasksStageOneCount[$regionName];
            $doneCountStageTwo  = $regionDoneStageTwoStatuses[$regionName];
            $totalCountStageTwo = $regionTasksStageTwoCount[$regionName];

            try {
                $dOne = $doneCountStageOne / $totalCountStageOne;
            } catch (\Throwable $exception) {
                $dOne = 0;
            }

            $percentageOfDoneStageOne = round($dOne * 100);

            try {
                $dTwo = $doneCountStageTwo / $totalCountStageTwo;
            } catch (\Throwable $exception) {
                $dTwo = 0;
            }

            $percentageOfDoneStageTwo = round($dTwo * 100);

            try {
                $totalPercent = floor(($percentsOfDone[$regionName]['Этап Подготовительный'] + $percentsOfDone[$regionName]['Этап Создание']) / 1.9);
            } catch (\Throwable $exception) {
                $totalPercent = 0;
            }

            $regionPercentOfDone[$regionName] = $totalPercent;

            //if ($regionName === 'САХА (14)') {
            //    dd($doneCountStageTwo, $totalCountStageTwo, $dTwo);
            //}
            //$totalPercent = round(($totalPercent / 100) * 4.8, 1);

            $regionMarkOfDone[$regionName] = $totalPercent;
        }

        $avgRegionsPercentOfDone = 0;

        foreach ($regionPercentOfDone as $percent) {
            $avgRegionsPercentOfDone += $percent;
        }

        try {
            $avgRegionsPercentOfDone = round($avgRegionsPercentOfDone / count($regionPercentOfDone));
        } catch (\Throwable $exception) {
            $avgRegionsPercentOfDone = 0;
        }

        $regionColors = [];

        foreach ($regionPercentOfDone as $regionName => $percent) {
            $customColor = isset($customColors[$regionName]) ? $customColors[$regionName] : null;

            $regionColors[$regionName] = $this->checkColor($percent, $avgRegionsPercentOfDone, $customColor);
        }

        foreach ($districtsGrouped as $district => $districtRegions) {
            foreach ($districtRegions as $regionName => $regionValue) {
                if (isset($regionColors[$regionName])) {
                    $districtsGrouped[$district][$regionName] = $regionColors[$regionName];
                } else {
                    unset($districtsGrouped[$district][$regionName]);
                }
            }
        }

        foreach ($districtsGrouped as $district => $districtRegions) {
            $districtsGrouped[$district] = $this->groupByColors($districtRegions);
        }

        foreach ($regionMarkOfDone as $markKey => $markOfDone) {
            $regionMarkOfDone[$markKey] = $markOfDone . '%';
        }

        $colorCounts = $this->groupByColors($regionColors);

        return [
            'percents'          => $regionPercentOfDone,
            'marks'             => $regionMarkOfDone,
            'colors'            => $regionColors,
            'colorsCounts'      => json_encode(array_values($colorCounts)),
            'colorsNames'       => json_encode(array_keys($colorCounts)),
            'colorsTotalCount'  => array_sum(array_values($colorCounts)),
            'colorsByDistricts' => $this->createGroupedColorsForTwigData($districtsGrouped)
        ];
    }


    protected function checkColor($regionPercent, $totalPercent, $customColor = null)
    {
        if ($customColor) {
            return $customColor;
        }

        if ($regionPercent > 80) {
            return '#20AF3F';
        } elseif ($regionPercent >= $totalPercent) {
            return '#abd57e';
        } elseif ($regionPercent > 50 && $regionPercent < $totalPercent) {
            return '#fae698';
        } elseif ($regionPercent <= 50) {
            return '#e77b1e';
        } else {
            return '#ffffff';
        }
    }


    protected function groupByColors($colors)
    {
        $colorCounts = [];

        foreach ($colors as $color) {
            switch ($color) {
                case '#20AF3F':
                    $colorName = 'Отлично';
                    break;

                case '#abd57e':
                    $colorName = 'Хорошо';
                    break;

                case '#fae698':
                    $colorName = 'Удовлетворительно';
                    break;

                case '#e77b1e':
                    $colorName = 'Неудовлетворительно';
                    break;

                case '#f44336':
                    $colorName = 'Проблемный регион';
                    break;

                case '#ffffff':
                default:
                    $colorName = 'Регионы не в зоне поручения';
                    break;
            }

            if (isset($colorCounts[$colorName])) {
                $colorCounts[$colorName]++;
            } else {
                $colorCounts[$colorName] = 1;
            }
        }

        $this->setEmptyColorsValues($colorCounts);
        $this->sortColorsValues($colorCounts);

        return $colorCounts;
    }


    protected function setEmptyColorsValues(&$colorCounts)
    {
        foreach ($this->getColorNames() as $colorName) {
            if ( ! isset($colorCounts[$colorName])) {
                $colorCounts[$colorName] = null;
            }
        }
    }


    protected function sortColorsValues(&$colorCounts)
    {
        ksort($colorCounts);
    }


    protected function getColorNames()
    {
        return [
            'Отлично',
            'Хорошо',
            'Удовлетворительно',
            'Неудовлетворительно',
            'Проблемный регион',
            'Регионы не в зоне поручения',
        ];
    }


    public function getColorsArray()
    {
        return [
            'Автоматический расчет'       => '',
            'Отлично'                     => '#20AF3F',
            'Хорошо'                      => '#abd57e',
            'Удовлетворительно'           => '#fae698',
            'Неудовлетворительно'         => '#e77b1e',
            'Проблемный регион'           => '#f44336',
            'Регионы не в зоне поручения' => '#ffffff',
        ];
    }


    protected function createGroupedColorsForTwigData($groupedColors)
    {
        $twigData = [];

        foreach ($groupedColors as $groupedColorDistrict => $groupedColor) {
            $twigData[$groupedColorDistrict] = [
                'counts' => json_encode(array_values($groupedColor)),
                'names'  => json_encode(array_keys($groupedColor)),
                'total'  => array_sum(array_values($groupedColor)),
            ];
        }

        return $twigData;
    }

}
