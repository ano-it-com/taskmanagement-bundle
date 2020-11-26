<?php

namespace ANOITCOM\TaskmanagementBundle\Services\WikiPageBlock\BlockRenderHandlers\Handlers;

use ANOITCOM\Wiki\DTO\Assemblers\FormDataAssembler;
use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\WikiPage\WikiPage;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageHtmlBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageObjectBlock;
use ANOITCOM\Wiki\Repository\WikiPage\WikiPageRepository;
use ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks\TasksTreeService;
use ANOITCOM\Wiki\Services\PageQuery\PageQueryService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;
use function Doctrine\ORM\QueryBuilder;

class TasksMatrixProject implements HandlerInterface
{

    private $em;

    /**
     * @var WikiPageRepository
     */
    private $pageRepo;

    /**
     * @var Security
     */
    private $security;

    /**
     * @var TasksTreeService
     */
    private $treeService;

    /**
     * @var mixed|null
     */
    private $personTypeId;

    /**
     * @var mixed|null
     */
    private $taskTypeId;

    /**
     * @var mixed|null
     */
    private $taskPersonFieldId;

    /**
     * @var mixed|null
     */
    private $taskStatusFieldId;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var FormDataAssembler
     */
    private $formDataAssembler;

    /**
     * @var mixed|null
     */
    private $taskParentFieldId;

    /**
     * @var PageQueryService
     */
    private $pageQueryService;

    /**
     * @var mixed|null
     */
    private $trackerType;

    /**
     * @var mixed|null
     */
    private $projectTypeId;

    /**
     * @var mixed|null
     */
    private $stageType;

    protected $taskStageFieldId;

    protected $taskProjectFieldId;

    /**
     * @var Request
     */
    private $request;


    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        TasksTreeService $treeService,
        UrlGeneratorInterface $urlGenerator,
        FormDataAssembler $formDataAssembler,
        PageQueryService $pageQueryService,
        RequestStack $requestStack
    ) {
        $this->em                 = $entityManager;
        $this->pageRepo           = $entityManager->getRepository(WikiPage::class);
        $this->security           = $security;
        $this->personTypeId       = isset($_ENV['PERSON_TYPE']) ? $_ENV['PERSON_TYPE'] : null;
        $this->taskTypeId         = isset($_ENV['TASK_TYPE']) ? $_ENV['TASK_TYPE'] : null;
        $this->projectTypeId      = isset($_ENV['REGION_TYPE']) ? $_ENV['REGION_TYPE'] : null;
        $this->taskPersonFieldId  = isset($_ENV['TASK_EMPLOYER_FIELD']) ? $_ENV['TASK_EMPLOYER_FIELD'] : null;
        $this->taskStatusFieldId  = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->taskParentFieldId  = isset($_ENV['TASK_TRACKER_FIELD']) ? $_ENV['TASK_TRACKER_FIELD'] : null;
        $this->taskStageFieldId   = isset($_ENV['TASK_VERSION_FIELD']) ? $_ENV['TASK_VERSION_FIELD'] : null;
        $this->taskProjectFieldId = isset($_ENV['TASK_REGION_FIELD']) ? $_ENV['TASK_REGION_FIELD'] : null;
        $this->trackerType        = isset($_ENV['TRACKER_TYPE']) ? $_ENV['TRACKER_TYPE'] : null;
        $this->stageType          = isset($_ENV['STAGE_TYPE']) ? $_ENV['STAGE_TYPE'] : null;
        $this->treeService        = $treeService;
        $this->urlGenerator       = $urlGenerator;
        $this->formDataAssembler  = $formDataAssembler;
        $this->pageQueryService   = $pageQueryService;
        $this->request            = $requestStack->getCurrentRequest();
    }


    /**
     *
     * @param WikiPageBlock $block
     *
     * @return WikiPageBlock|null
     */
    public function handle(WikiPageBlock $block): ?WikiPageBlock
    {
        $html = $block->getValue()->getValue();

        $pregMatch = '/\%tasksMatrixProject%([\s\S]+?)\%%/';

        $matches      = [];
        $matchesCount = preg_match_all($pregMatch, $html, $matches);

        if (empty($matches[0])) {
            return $block;
        }

        $pageProject = null;

        foreach ($block->getPage()->getBlocks() as $pageBlock) {
            if ( ! $pageBlock instanceof WikiPageObjectBlock) {
                continue;
            }

            foreach ($pageBlock->getObjects() as $blockObject) {
                if ($blockObject->getType()->getId() === $this->projectTypeId) {
                    $pageProject = $blockObject;
                }
            }
        }

        if ( ! $pageProject) {
            return $block;
        }

        $allowedProjectNames = [ $pageProject->getTitle() ];

        foreach ($matches[1] as $key => $match) {
            $trackers = $this->em->getRepository(DataObject::class)->findBy([
                'type' => $this->trackerType
            ], [ 'title' => 'ASC' ]);

            $newHtml = '';

            foreach ($trackers as $tracker) {
                $tasks = $this->treeService->getTasksByStage($tracker->getId());

                if (empty($tasks)) {
                    continue;
                }

                $newHtml .= $this->makeTable($tasks, $tracker->getId(), $allowedProjectNames, $tracker) . PHP_EOL;
            }

            $html = str_replace($matches[0][$key], $newHtml, $html);
        }

        $block->getValue()->setValue($html);

        return $block;
    }


    protected function makeFilter($allowedProjectNames, $selectedValue = 'all')
    {
        $filterSelectWrapper = '<select name="project">%s</select>';
        $filterWrapper       = '
            <form>
                %s
                <br>
                <button class="btn-primary">Фильтровать</button>
            </form>
            <br>
        ';

        if ($selectedValue === 'all') {
            $filterOptions = [
                '<option name="project" selected value="all">Все</option>'
            ];
        } else {
            $filterOptions = [
                '<option name="project" value="all">Все</option>'
            ];
        }

        foreach ($allowedProjectNames as $allowedProjectName) {
            if ($selectedValue === $allowedProjectName) {
                $filterOptions[] = sprintf('<option selected value="%s">%s</option>', $allowedProjectName, $allowedProjectName);
            } else {
                $filterOptions[] = sprintf('<option value="%s">%s</option>', $allowedProjectName, $allowedProjectName);
            }
        }

        if (empty($filterOptions)) {
            return PHP_EOL;
        }

        $filter = sprintf($filterSelectWrapper, implode(PHP_EOL, $filterOptions));
        $filter = sprintf($filterWrapper, $filter) . PHP_EOL;

        return $filter;
    }

  
    protected function makeTable($tasks, $trackerId, $allowedProjectNames, $tracker)
    {
        $findProject = false;

        foreach ($tasks as $project) {
            if ( ! in_array($project['title'], $allowedProjectNames)) {
                continue;
            }

            $findProject = true;
        }

        if ($findProject !== true) {
            return '';
        }

        $table = sprintf('<h3 style="margin-top: 5px;">%s</h3>', $tracker->getTitle()) . PHP_EOL;

        /** @var DataObject[] $stages */
        $stages = $this->em->getRepository(DataObject::class)->findBy([
            'type' => $this->stageType
        ], [ 'title' => 'ASC' ]);

        $stageCounts      = $this->getCountsByStages($tasks, $stages);
        $stagesTotalCount = $this->getTotalCountsOfStages($tasks, $stages);

        $filteredProjectName = urldecode($this->request->query->get('project', 'all'));

        //$table = $this->makeFilter($allowedProjectNames, $filteredProjectName);

        $table .= '<table>';

        $table .= '
                <thead>
                    <tr>
                    <th>Готовность</th>';

        foreach ($stages as $stageKey => $stage) {
            $find = false;
            foreach ($tasks as $project) {
                foreach ($project['children'] as $projectStage) {
                    if ($projectStage['title'] === $stage->getTitle()) {
                        $find = true;
                    }
                }
            }

            if ( ! $find) {
                unset($stages[$stageKey]);
                unset($stageCounts[$stage->getTitle()]);
            }
        }

        $totalPercents = 0;

        foreach ($stageCounts as $stageCount) {
            $totalPercents += (int)$this->getPercentsString($stageCount['done_tasks'], $stageCount['total_tasks'], false);
        }

        if ($totalPercents !== 0 && count($stageCounts) !== 0) {
            $totalPercents = floor($totalPercents / count($stageCounts));
        }

        $totalPercents = (string)$totalPercents;

        foreach ($stages as $stage) {
            $table .= sprintf('<th data-matrix-stage-name="%s">%s</th>', $stage->getTitle(), $stage->getTitle());
        }

        $table .= '</tr>';
        $table .= '</thead><tbody>';

        //$table .= '<tr class="nosort">';
        //$table .= '<td>Итог</td>';
        //$table .= sprintf(
        //    '<td data-sort-type="number" data-sort-value="%s"><span class="white-bg"><span class="percent">%s</span><span class="min">%s / %s</span></span></td>',
        //    $totalPercents,
        //    $totalPercents . '%',
        //    $stagesTotalCount['done_tasks'],
        //    $stagesTotalCount['total_tasks']
        //);

        //foreach ($stages as $stage) {
        //    if (array_key_exists($stage->getTitle(), $stageCounts)) {
        //        $table .= sprintf('<td data-sort-type="number" data-sort-value="%s"><span class="white-bg"><span class="percent">%s</span><span class="min">%s / %s</span></span></td>',
        //            $this->getPercentsString($stageCounts[$stage->getTitle()]['done_tasks'], $stageCounts[$stage->getTitle()]['total_tasks'], false),
        //            $this->getPercentsString($stageCounts[$stage->getTitle()]['done_tasks'], $stageCounts[$stage->getTitle()]['total_tasks']),
        //            $stageCounts[$stage->getTitle()]['done_tasks'],
        //            $stageCounts[$stage->getTitle()]['total_tasks']
        //        );
        //    }
        //}
        //
        //$table .= '</tr>';

        $percentOfDoneCalc = function ($project) use ($stages) {
            $percentOfDone  = 0;
            $countOfPercent = 0;
            $sumOfPercent   = 0;

            foreach ($stages as $stage) {
                foreach ($project['children'] as $projectStage) {
                    if ($projectStage['title'] === $stage->getTitle()) {
                        $countOfPercent++;
                        $sumOfPercent += (int)$this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks'], false);
                        break;
                    }
                }
            }

            if ($sumOfPercent !== 0) {
                $percentOfDone = $sumOfPercent / $countOfPercent;
                $percentOfDone = floor($percentOfDone);
            }

            return $percentOfDone;
        };

        usort($tasks, function ($a, $b) use ($percentOfDoneCalc) {
            return $percentOfDoneCalc($a) > $percentOfDoneCalc($b) ? -1 : 1;
        });

        foreach ($tasks as $project) {
            if ( ! in_array($project['title'], $allowedProjectNames)) {
                continue;
            }

            if ($filteredProjectName !== 'all' && $filteredProjectName !== null) {
                if ($project['title'] !== $filteredProjectName) {
                    continue;
                }
            }

            $percentOfDone = $percentOfDoneCalc($project);

            $table .= sprintf('<tr>');
            $table .= sprintf(
                '<td data-sort-type="number" data-sort-value="%s"><span class="white-bg"><span class="percent">%s</span><span class="min">%s / %s</span></span></td>',
                $percentOfDone,
                $percentOfDone . '%',
                $project['done_tasks'],
                $project['total_tasks']
            );

            foreach ($stages as $stage) {
                $count = null;
                $color = 'white';

                foreach ($project['children'] as $projectStage) {
                    if ($projectStage['title'] === $stage->getTitle()) {
                        $color = $projectStage['color'];
                        $count = sprintf(
                            '<a href="%s"><span class="percent">%s</span><span class="min">%s / %s</span></a>',
                            $this->makeLinkForProjectStageTask($project['id'], $projectStage['id'], $trackerId),
                            $this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks']),
                            $projectStage['done_tasks'],
                            $projectStage['total_tasks']
                        );
                        break;
                    }
                }

                if ($count === null) {
                    $table .= sprintf(
                        '<td data-sort-type="number" data-sort-value="0"><div style="background-color: %s;" class="td-color"><span class="td-color-no-data">—</span></td>',
                        $color
                    );
                } else {
                    if ($color === 'white') {
                        $table .= sprintf(
                            '<td data-sort-type="number" data-sort-value="%s"><div style="background-color: %s;" class="td-color"><div class="td-color-progress" style="width: %s;"></div></div><span class="white-bg">%s</span></td>',
                            $this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks'], false),
                            $color,
                            $this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks']),
                            $count
                        );
                    } else {
                        $table .= sprintf(
                            '<td data-sort-type="number" data-sort-value="%s"><div style="background-color: %s;" class="td-color"><div class="td-color-progress" style="width: %s;"></div></div>%s</td>',
                            $this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks'], false),
                            $color,
                            $this->getPercentsString($projectStage['done_tasks'], $projectStage['total_tasks']),
                            $count
                        );
                    }
                }
            }

            $table .= '</tr>';
        }

        $table .= '</tbody></table>';

        return $table;
    }


    protected function getCountsByStages($tasks, $stages)
    {
        $counts = [];

        foreach ($stages as $stage) {
            $stageTotal = 0;
            $stageDone  = 0;

            foreach ($tasks as $project) {
                foreach ($project['children'] as $projectStage) {
                    if ($projectStage['title'] === $stage->getTitle()) {
                        $stageTotal += $projectStage['total_tasks'];
                        $stageDone  += $projectStage['done_tasks'];
                    }
                }
            }

            $counts[$stage->getTitle()] = [
                'total_tasks' => $stageTotal,
                'done_tasks'  => $stageDone
            ];
        }

        return $counts;
    }


    protected function getTotalCountsOfStages($tasks, $stages)
    {
        $stageTotal = 0;
        $stageDone  = 0;

        foreach ($stages as $stage) {
            foreach ($tasks as $project) {
                foreach ($project['children'] as $projectStage) {
                    if ($projectStage['title'] === $stage->getTitle()) {
                        $stageTotal += $projectStage['total_tasks'];
                        $stageDone  += $projectStage['done_tasks'];
                    }
                }
            }
        }

        return [
            'total_tasks' => $stageTotal,
            'done_tasks'  => $stageDone
        ];
    }


    protected function getPercentsString($doneCount, $totalCount, $withPercent = true)
    {
        if ($totalCount === 0) {
            $d = 0;
        } else {
            $d = $doneCount / $totalCount;
        }

        $percentage = round($d * 100);

        return $withPercent ? $percentage . '%' : $percentage;
    }


    protected function makeLinkForProjectStageTask($projectId, $stageId, $trackerId)
    {
        $query = '/wiki/page/queries?fields[%s][]=%s&fields[%s][]=%s&fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
        $query = sprintf($query, $this->taskProjectFieldId, $projectId, $this->taskStageFieldId, $stageId, $this->taskParentFieldId, $trackerId, $this->taskTypeId);

        return $query;
    }


    public function support(WikiPageBlock $block): bool
    {
        return $block instanceof WikiPageHtmlBlock;
    }


    public function getSort(): int
    {
        return 15000;
    }
}
