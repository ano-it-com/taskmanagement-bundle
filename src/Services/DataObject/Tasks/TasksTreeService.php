<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks;

use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\ObjectTypeField;
use ANOITCOM\Wiki\Entity\Objects\ObjectValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLinkValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLiteralValue;
use ANOITCOM\Wiki\Entity\PageQuery\PageQuery;
use Doctrine\ORM\Cache;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Security\Core\Security;
use Symfony\Contracts\Cache\ItemInterface;
use WikiAclBundle\Services\Acl;

class TasksTreeService
{

    /**
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @var Acl
     */
    protected $acl;

    /**
     * @var Security
     */
    protected $security;

    /**
     * @var mixed|null
     */
    protected $parentTaskFieldId;

    /**
     * @var mixed|null
     */
    protected $statusTaskFieldId;

    /**
     * @var mixed|null
     */
    protected $projectTaskFieldId;

    /**
     * @var mixed|null
     */
    protected $personTaskFieldId;

    /**
     * @var mixed|null
     */
    protected $stageTypeId;

    /**
     * @var mixed|null
     */
    protected $stageTaskFieldId;

    /**
     * @var mixed|null
     */
    private $trackerTaskFieldId;

    /**
     * @var string
     */
    private $doneStatusName;

    /**
     * @var mixed|null
     */
    private $trackerTypeId;

    /**
     * @var mixed|null
     */
    private $projectTypeId;


    public function __construct(EntityManagerInterface $entityManager, Acl $acl, Security $security)
    {
        $this->entityManager      = $entityManager;
        $this->acl                = $acl;
        $this->security           = $security;
        $this->stageTaskFieldId   = isset($_ENV['TASK_TRACKER_FIELD']) ? $_ENV['TASK_TRACKER_FIELD'] : null;
        $this->trackerTaskFieldId = isset($_ENV['TASK_VERSION_FIELD']) ? $_ENV['TASK_VERSION_FIELD'] : null;
        $this->parentTaskFieldId  = isset($_ENV['TASK_PARENT_FIELD']) ? $_ENV['TASK_PARENT_FIELD'] : null;
        $this->statusTaskFieldId  = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->projectTaskFieldId = isset($_ENV['TASK_REGION_FIELD']) ? $_ENV['TASK_REGION_FIELD'] : null;
        $this->personTaskFieldId  = isset($_ENV['TASK_EMPLOYER_FIELD']) ? $_ENV['TASK_EMPLOYER_FIELD'] : null;
        $this->stageTypeId        = isset($_ENV['TRACKER_TYPE']) ? $_ENV['TRACKER_TYPE'] : null;
        $this->projectTypeId      = isset($_ENV['REGION_TYPE']) ? $_ENV['REGION_TYPE'] : null;
        $this->trackerTypeId      = isset($_ENV['STAGE_TYPE']) ? $_ENV['STAGE_TYPE'] : null;
        $this->doneStatusName     = 'Закрыта';
    }


    public function getGroupedNamesOfChildrenTasks($parentTaskName)
    {
        $tasks = $this->getChildrenTasksFromView($parentTaskName)->fetchAll();

        $tasksRawNames = array_map(function ($task) {
            return $task['task'];
        }, $tasks);

        $re = '/ \([0-9]+\)$/m';

        $tasksNames = preg_replace($re, '', $tasksRawNames);

        $tasksNames = array_map(function ($task) {
            return trim($task);
        }, $tasksNames);

        $tasksGroupedNames = array_unique($tasksNames);

        usort($tasksGroupedNames, function ($a, $b) {
            return $a > $b;
        });

        return $tasksGroupedNames;
    }


    public function getTasksByParentTask($parentTaskName)
    {
        $tasks = $this->getChildrenTasksFromView($parentTaskName)->fetchAll();

        //$tasks = $this->prepareDataTracker($tasks);

        $allowedProjectNames = $this->getAllowedProjectNames();

        $tasksGroupedNames = $this->getGroupedNamesOfChildrenTasks($parentTaskName);

        foreach ($tasks as $taskDataKey => &$tasksDataItem) {
            $tasksDataItem['task_category'] = null;

            foreach ($tasksGroupedNames as $tasksName) {
                if (mb_stripos($tasksDataItem['task'], $tasksName) !== false) {
                    $tasksDataItem['task_category'] = $tasksName;
                }
            }
        }

        $tasksFiltered = $this->groupByStageAndProject($tasks, 'region', 'task_category', $allowedProjectNames);

        $this->loadStatuses($tasksFiltered);
        $this->countOfDone($tasksFiltered);
        $this->checkColors($tasksFiltered);

        $tasksFiltered = $this->cleanKeys($tasksFiltered);
        $tasksFiltered = array_values($tasksFiltered);

        return $tasksFiltered;
    }


    public function getTasksByStage($stageId)
    {
        //$client = RedisAdapter::createConnection($_ENV['REDIS_URL']);
        //$cache  = new RedisAdapter($client);
        //

        //$tasks = $cache->get('stage_tasks_cache1', function (ItemInterface $item) use ($stageId) {
        //    $item->expiresAfter(3600);
        //
        $tasks = $this->getStageTasks($stageId)->fetchAll();
        //
        //    dd($this->getStageTasks($stageId));
        //    return $tasks;
        //});

        //$tasks = $this->getStageTasks($stageId)->fetchAll();

        //dd($this->getStageTasks($stageId)->fetchAll());
        //$tasks = $this->prepareDataTracker($tasks);

        $allowedProjectNames = $this->getAllowedProjectNames();
        $tasksFiltered       = $this->groupByStageAndProject($tasks, 'region', 'stage', $allowedProjectNames);
        $this->loadStatuses($tasksFiltered);
        $this->countOfDone($tasksFiltered);
        $this->checkColors($tasksFiltered);

        $tasksFiltered = $this->cleanKeys($tasksFiltered);
        $tasksFiltered = array_values($tasksFiltered);

        return $tasksFiltered;
    }


    public function getTasksOfProjectByStage($projectId)
    {
        $stages = $this->getStages()->getQuery()->useQueryCache(true)->getResult();
        $stages = $this->prepareStageData($stages);

        $tasks = $this->getProjectTasks($projectId);

        foreach ($tasks as &$task) {
            $task['children'] = [];
        }
        //$tasks = $this->prepareDataStage($tasks);

        $tasksArray = array_merge([], $stages);
        $tasksArray = array_merge($tasksArray, $tasks);

        //foreach ($tasks as $task) {
        //    $filteredTask = $this->parseCoreTasks($task['id']);
        //
        //    if (empty($filteredTask)) {
        //        $tasksArray[] = $task;
        //    } else {
        //        $tasksArray = array_merge($tasksArray, $this->prepareData($filteredTask));
        //    }
        //}

        //$this->loadChildren($tasksArray);

        $tasksFiltered = $this->buildTree($tasksArray);
        $tasksFiltered = $this->cleanKeys($tasksFiltered);

        foreach ($tasksFiltered as $key => $task) {
            if (isset($task['region'])) {
                unset($tasksFiltered[$key]);
            }
        }

        $tasksFiltered = array_values($tasksFiltered);

        $this->loadStatuses($tasksFiltered);

        //foreach ($tasksFiltered as $taskIndex => $taskData) {
        //    $tasksFiltered[$taskIndex]['children'] = [];
        //}

        return $tasksFiltered;
        //$tasks = $this->getProjectTasks($projectId)->getQuery()->useQueryCache(true)->getResult();
        //$tasks = $this->prepareData($tasks);
        //
        //$this->loadChildren($tasks);
        //$this->loadStatuses($tasks);
        //
        //return $tasks;
    }


    public function getTasksOfProject($projectId)
    {
        $tasks        = $this->getProjectTasks($projectId);
        $foldersTasks = $this->getFoldersTasks()->getQuery()->useQueryCache(true)->getResult();

        $tasks        = $this->prepareData($tasks, null, true);
        $foldersTasks = $this->prepareData($foldersTasks, null, true);

        $tasksArray = array_merge($tasks, $foldersTasks);

        //foreach ($tasks as $task) {
        //    $filteredTask = $this->parseCoreTasks($task['id'], $tasksArray);
        //
        //    $tasksArray = array_merge($tasksArray, $filteredTask);
        //}

        $tasksFiltered = $this->buildTree($tasksArray);
        $tasksFiltered = $this->cleanKeys($tasksFiltered);

        foreach ($tasksFiltered as $key => $task) {
            if ($task['parent_id'] === null) {
                unset($tasksFiltered[$key]);
            }
        }

        $tasksFiltered = array_values($tasksFiltered);

        //foreach ($tasksFiltered as $taskIndex => $taskData) {
        //    $tasksFiltered[$taskIndex]['children'] = [];
        //}

        return $tasksFiltered;
        //$tasks = $this->getProjectTasks($projectId)->getQuery()->useQueryCache(true)->getResult();
        //$tasks = $this->prepareData($tasks);
        //
        //$this->loadChildren($tasks);
        //$this->loadStatuses($tasks);
        //
        //return $tasks;
    }


    public function getFoldersTasks()
    {
        $parentTaskFieldId = $this->personTaskFieldId;
        $pTaskFieldId      = $this->stageTaskFieldId;
        $statusFieldId     = $this->statusTaskFieldId;

        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select('o, 0, \'\'');

        $objects = $qb
            ->leftJoin('o.type', 'type')
            ->leftJoin('o.values', 'vals')
            ->leftJoin('vals.object_type_field', 'field')
            ->leftJoin('o.values', 'p_vals')
            ->leftJoin('p_vals.object_type_field', 'p_field')
            ->leftJoin(ObjectLinkValue::class, 'olv', Join::WITH, 'vals.id = olv.id')
            ->leftJoin(ObjectLinkValue::class, 'p_olv', Join::WITH, 'p_vals.id = p_olv.id')
            //->leftJoin('o.values', 'status_vals')
            //->leftJoin('status_vals.object_type_field', 'status_field')
            //->leftJoin(ObjectLiteralValue::class, 'status_olv', Join::WITH, 'status_vals.id = status_olv.id')
            ->leftJoin(DataObject::class, 'olv_obj', Join::WITH, 'olv.value = olv_obj.id')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->isNotNull('p_olv.value'),
                    $qb->expr()->eq('p_field.id', $qb->expr()->literal($pTaskFieldId)),
                //$qb->expr()->eq('status_field.id', $qb->expr()->literal($statusFieldId)),
                )
            );
        //->groupBy('o.id', 'status_olv.value');

        //->setMaxResults(100);

        return $this->acl->acceptPermissionForQuery('show', $objects, $this->security->getUser());
    }


    public function getTasksOfPerson($personId)
    {
        $stages = $this->getStages()->getQuery()->useQueryCache(true)->getResult();
        $stages = $this->prepareStageData($stages);

        $tasks = $this->getPersonTasks($personId)->getQuery()->useQueryCache(true)->getResult();
        $tasks = $this->prepareDataStage($tasks);

        $tasksArray = array_merge([], $stages);
        $tasksArray = array_merge($tasksArray, $tasks);

        //foreach ($tasks as $task) {
        //    $filteredTask = $this->parseCoreTasks($task['id']);
        //
        //    if (empty($filteredTask)) {
        //        $tasksArray[] = $task;
        //    } else {
        //        $tasksArray = array_merge($tasksArray, $this->prepareDataStage($filteredTask));
        //    }
        //}

        //$this->loadChildren($tasksArray);

        $tasksFiltered = $this->buildTree($tasksArray);
        $tasksFiltered = $this->cleanKeys($tasksFiltered);

        foreach ($tasksFiltered as $key => $task) {
            if (isset($task['region'])) {
                unset($tasksFiltered[$key]);
            }
        }

        $tasksFiltered = array_values($tasksFiltered);

        $this->loadStatuses($tasksFiltered);

        //$this->cleanChildren($tasksFiltered);

        return $tasksFiltered;
    }


    public function getCoreTask($id)
    {
        $coreTasks = $this->parseCoreTasks($id);

        if (empty($coreTasks)) {
            return $coreTasks;
        }

        $tasks = $this->buildTree($coreTasks);
        $tasks = $this->cleanKeys($tasks);

        return array_values($tasks);
    }


    public function getCoreTasks()
    {
        $queryResult = $this->getTasksQuery()->getQuery()->useQueryCache(true)->getResult();

        return $this->prepareData($queryResult);
    }


    public function getTask($id)
    {
        $queryResult = $this->getTasksQuery($id)->getQuery()->useQueryCache(true)->getResult();

        return $this->prepareData($queryResult);
    }


    public function getChildrenTasks($id)
    {
        $queryResult = $this->getChildrenTasksQuery($id)->getQuery()->useQueryCache(true)->getResult();

        return $this->prepareData($queryResult);
    }


    protected function getPersonTasks($personId)
    {
        $parentTaskFieldId = $this->personTaskFieldId;
        $pTaskFieldId      = $this->stageTaskFieldId;
        $statusFieldId     = $this->statusTaskFieldId;

        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
            //->select('o, count(c_olv), status_olv.value');
                                  ->select('o, 0, status_olv.value');

        $objects = $qb
            ->leftJoin('o.type', 'type')
            ->leftJoin('o.values', 'vals')
            ->leftJoin('vals.object_type_field', 'field')
            ->leftJoin('o.values', 'p_vals')
            ->leftJoin('p_vals.object_type_field', 'p_field')
            ->leftJoin(ObjectLinkValue::class, 'olv', Join::WITH, 'vals.id = olv.id')
            ->leftJoin(ObjectLinkValue::class, 'p_olv', Join::WITH, 'p_vals.id = p_olv.id')
            ->leftJoin('o.values', 'status_vals')
            ->leftJoin('status_vals.object_type_field', 'status_field')
            ->leftJoin(ObjectLiteralValue::class, 'status_olv', Join::WITH, 'status_vals.id = status_olv.id')
            ->leftJoin(DataObject::class, 'olv_obj', Join::WITH, 'olv.value = olv_obj.id')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('p_field.id', $qb->expr()->literal($pTaskFieldId)),
                    $qb->expr()->eq('status_field.id', $qb->expr()->literal($statusFieldId)),
                    $qb->expr()->eq('olv_obj.id', $qb->expr()->literal($personId)),
                )
            )
            ->groupBy('o.id', 'status_olv.value');

        //->setMaxResults(100);

        return $this->acl->acceptPermissionForQuery('show', $objects, $this->security->getUser());
    }


    protected function getChildrenTasksFromView($parentTaskName)
    {

        $sql = sprintf('
            SELECT DISTINCT(task),  
            MAX(date_start) as date_start, 
            date_end,
            region,
            region_id,
            status,
            tracker,
            tracker_id,
            stage,
            stage_id,
            curator_id,
            curator,
            parent_task,
            parent_task_id
            FROM wiki_views_tasks
            WHERE parent_task LIKE \'%s\'
            GROUP BY
            task,
            date_end,
            region,
            region_id,
            status,
            tracker,
            tracker_id,
            stage,
            stage_id,
            curator_id,
            curator,
            parent_task,
            parent_task_id
        ', '%' . $parentTaskName . '%');

        $query = $this->entityManager->getConnection()->prepare($sql);

        $query->execute();

        return $query;
    }


    protected function getStageTasks($stageId)
    {
        $sql = sprintf('
            SELECT DISTINCT(task),  
            date_start, 
            date_end,
            region_id,
            status,
            tracker,
            stage,
            region,
            stage_id,
            curator,
            curator_id,
            pd.district
            FROM wiki_views_tasks wvt
            LEFT JOIN project_districts pd ON pd.project = wvt.region
            WHERE tracker_id = \'%s\'
        ', $stageId);

        $query = $this->entityManager->getConnection()->prepare($sql);

        $query->execute();

        return $query;
    }


    protected function groupByStageAndProject(&$tasksDataArray, $projectFieldName = 'region', $stageFieldName = 'stage', $allowedProjects = [])
    {
        $grouped = [];

        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            if (null === $tasksDataItem[$projectFieldName] || null === $tasksDataItem[$stageFieldName]) {
                continue;
            }

            $tasksDataItem['children'] = [];

            if (isset($grouped[$tasksDataItem[$projectFieldName]]) && isset($grouped[$tasksDataItem[$projectFieldName]]['children'])) {
                if (isset($grouped[$tasksDataItem[$projectFieldName]]['children'][$tasksDataItem[$stageFieldName]])) {
                    $grouped[$tasksDataItem[$projectFieldName]]['children'][$tasksDataItem[$stageFieldName]]['children'][] = $tasksDataItem;
                } else {
                    if ( ! isset($grouped[$tasksDataItem[$projectFieldName]]['children'])) {
                        $grouped[$tasksDataItem[$projectFieldName]]['children'] = [];
                    }

                    $grouped[$tasksDataItem[$projectFieldName]]['children'][$tasksDataItem[$stageFieldName]] = [
                        'id'         => isset($tasksDataItem[$stageFieldName . '_id']) ? $tasksDataItem[$stageFieldName . '_id'] : $tasksDataItem[$stageFieldName],
                        'title'      => $tasksDataItem[$stageFieldName],
                        'curator'    => $tasksDataItem['curator'],
                        'curator_id' => $tasksDataItem['curator_id'],
                        'district'   => $tasksDataItem['district'],
                        'children'   => [ $tasksDataItem ],
                        'statuses'   => []
                    ];
                }
            } else {
                $grouped[$tasksDataItem[$projectFieldName]] = [
                    'id'         => isset($tasksDataItem[$projectFieldName . '_id']) ? $tasksDataItem[$projectFieldName . '_id'] : $tasksDataItem[$projectFieldName],
                    'title'      => $tasksDataItem[$projectFieldName],
                    'curator'    => $tasksDataItem['curator'],
                    'curator_id' => $tasksDataItem['curator_id'],
                    'district'   => $tasksDataItem['district'],
                    'statuses'   => [],
                    'children'   => [
                        $tasksDataItem[$stageFieldName] => [
                            'id'         => isset($tasksDataItem[$stageFieldName . '_id']) ? $tasksDataItem[$stageFieldName . '_id'] : $tasksDataItem[$stageFieldName],
                            'title'      => $tasksDataItem[$stageFieldName],
                            'curator'    => $tasksDataItem['curator'],
                            'curator_id' => $tasksDataItem['curator_id'],
                            'district'   => $tasksDataItem['district'],
                            'children'   => [ $tasksDataItem ],
                            'statuses'   => []
                        ]
                    ]
                ];
            }
        }

        foreach ($grouped as $projectName => $project) {
            if ( ! in_array($projectName, $allowedProjects)) {
                unset($grouped[$projectName]);
            }
        }

        return $grouped;
    }


    protected function getProjectTasks($projectId)
    {
        $sql = sprintf('
            SELECT DISTINCT(task),
            object_id as id,  
            MAX(date_start) as date_start, 
            date_end,
            status,
            tracker,
            tracker_id,
            curator_id,
            curator,
            tracker_id as parent,
            stage_id,
            stage,
            curator_id,
            curator
            FROM wiki_views_tasks
            WHERE region_id = \'%s\'
            AND tracker_id IS NOT NULL
            GROUP BY
            object_id,
            task,
            date_end,
            status,
            tracker,
            tracker_id,
            curator_id,
            curator,
            parent_task_id,
            parent_task,
            stage,
            stage_id,
            curator_id,
            curator
        ', $projectId);

        $query = $this->entityManager->getConnection()->prepare($sql);

        $query->execute();

        return $query->fetchAll();
        //$parentTaskFieldId = $this->projectTaskFieldId;
        //$pTaskFieldId      = $this->stageTaskFieldId;
        //$statusFieldId     = $this->statusTaskFieldId;
        //
        ///** @var QueryBuilder $qb */
        //$qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
        //                          ->select('o, 0, \'\'');
        //
        //$objects = $qb
        //    ->innerJoin('o.resource', 'object_resource')
        //    //->leftJoin('o.page', 'object_page')
        //    //->leftJoin('object_page.labels', 'labels')
        //    //->leftJoin('object_page.resource', 'page_resource')
        //    //->leftJoin('object_page.group', 'page_group')
        //    //->leftJoin('o.type', 'type')
        //    //->leftJoin('o.user', 'object_user')
        //    //->leftJoin('o.group', 'object_group')
        //    //->leftJoin('o.values', 'object_values')
        //    //->leftJoin('object_values.object_type_field', 'object_values_type')
        //    ->leftJoin('o.values', 'vals')
        //    ->leftJoin('vals.object_type_field', 'field')
        //    ->leftJoin('o.values', 'p_vals')
        //    ->leftJoin('p_vals.object_type_field', 'p_field')
        //    ->leftJoin(ObjectLinkValue::class, 'olv', Join::WITH, 'vals.id = olv.id')
        //    ->leftJoin(ObjectLinkValue::class, 'p_olv', Join::WITH, 'p_vals.id = p_olv.id')
        //    ->leftJoin(DataObject::class, 'olv_obj', Join::WITH, 'olv.value = olv_obj.id')
        //    //->leftJoin(ObjectLinkValue::class, 'c_olv', Join::WITH, 'c_olv.value = o.id')
        //    //->leftJoin(ObjectValue::class, 'c_vals', Join::WITH, 'c_vals.id = c_vals.id')
        //    //->leftJoin('c_vals.object_type_field', 'c_field')
        //    ->where(
        //        $qb->expr()->andX(
        //            $qb->expr()->eq('field.id', $qb->expr()->literal($parentTaskFieldId)),
        //            $qb->expr()->eq('p_field.id', $qb->expr()->literal($pTaskFieldId)),
        //            $qb->expr()->eq('olv_obj.id', $qb->expr()->literal($projectId)),
        //        )
        //    );
        //    //->addSelect('type', 'object_values', 'object_values_type', 'object_resource', 'object_page', 'object_user', 'object_group');
        //
        ////->setMaxResults(100);
        //
        //return $this->acl->acceptPermissionForQuery('show', $objects, $this->security->getUser());
    }


    protected function getStages($projectId = null)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select('o');

        $qb
            ->leftJoin('o.type', 'type')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('type.id', $qb->expr()->literal($this->stageTypeId))
                )
            );

        if ($projectId) {
            $qb
                ->leftJoin('o.values', 'values')
                ->leftJoin('values.object_type_field', 'otf')
                ->leftJoin(ObjectLinkValue::class, 'project', Join::WITH, 'values.id = project.id')
                ->andWhere(
                    $qb->expr()->eq('otf.id', $qb->expr()->literal($this->projectTaskFieldId)),
                    $qb->expr()->eq('project.value', $qb->expr()->literal($projectId))
                );
        }

        $qb
            //->join('o.resource', 'object_resource')
            //->join('o.values', 'object_values')
            //->join('object_values.object_type_field', 'object_value_type')
            //->leftJoin('o.user', 'o_user')
            //->leftJoin('o.group', 'o_group')
            //->leftJoin('o.page', 'o_page')
            //->leftJoin('o_page.resource', 'page_resource')
            //->leftJoin('o_page.group', 'page_group')
            //->addSelect('object_resource', 'object_values', 'object_value_type', 'type', 'o_user', 'o_group', 'o_page', 'page_resource', 'page_group')
            ->orderBy('o.title', 'ASC');

        return $qb;
    }


    protected function getTrackers($projectId = null)
    {
        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select('o');

        $qb
            ->leftJoin('o.type', 'type')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('type.id', $qb->expr()->literal($this->trackerTypeId))
                )
            )
            ->setMaxResults(100);

        if ($projectId) {
            $qb
                ->leftJoin('o.values', 'values')
                ->leftJoin('values.object_type_field', 'otf')
                ->leftJoin(ObjectLinkValue::class, 'project', Join::WITH, 'values.id = project.id')
                ->andWhere(
                    $qb->expr()->eq('otf.id', $qb->expr()->literal($this->projectTaskFieldId)),
                    $qb->expr()->eq('project.value', $qb->expr()->literal($projectId))
                )
                ->addSelect('values', 'otf', 'project');
        }

        return $qb;
    }


    protected function getProjects()
    {
        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select('o');

        $qb
            ->leftJoin('o.type', 'type')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('type.id', $qb->expr()->literal($this->projectTypeId))
                )
            )
            ->orderBy('o.title', 'ASC');

        return $qb;
    }


    public function getAllowedProjectNames()
    {
        $projectsQb = $this->acl->acceptPermissionForQuery('show', $this->getProjects(), $this->security->getUser());

        $projectsNames = [];

        /** @var DataObject $project */
        foreach ($projectsQb->getQuery()->useQueryCache(true)->getResult() as $project) {
            $projectsNames[] = $project->getTitle();
        }

        return $projectsNames;
    }


    protected function getChildrenTasksQueryExists($alias)
    {
        $parentTaskFieldId = $this->parentTaskFieldId;

        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o1')
                                  ->select('COUNT(olv1.id)');

        $objects = $qb
            ->leftJoin('o1.type', 'type1')
            ->leftJoin('o1.values', 'vals1')
            ->leftJoin('vals1.object_type_field', 'field1')
            ->leftJoin(ObjectLinkValue::class, 'olv1', Join::WITH, 'vals1.id = olv1.id')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('field1.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('olv1.value', $alias),
                )
            );

        return $objects;
    }


    protected function getChildrenTasksQuery($taskId)
    {
        /** @var DataObject $task */
        $task = $this->entityManager->getRepository(DataObject::class)->find($taskId);

        if ($task->getType()->getId() === $this->stageTypeId) {
            $parentTaskFieldId = $this->stageTaskFieldId;
        } else {
            $parentTaskFieldId = $this->parentTaskFieldId;
        }

        $statusFieldId = $this->statusTaskFieldId;

        $subQuery = $this->getChildrenTasksQueryExists('o');

        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select(sprintf('o, (%s), status_olv.value', $subQuery->getDQL()));

        $objects = $qb
            ->leftJoin('o.type', 'type')
            ->leftJoin('o.values', 'vals')
            ->leftJoin('vals.object_type_field', 'field')
            ->leftJoin(ObjectLinkValue::class, 'olv', Join::WITH, 'vals.id = olv.id')
            ->leftJoin('o.values', 'status_vals')
            ->leftJoin('status_vals.object_type_field', 'status_field')
            ->leftJoin(ObjectLiteralValue::class, 'status_olv', Join::WITH, 'status_vals.id = status_olv.id')
            ->leftJoin(DataObject::class, 'olv_obj', Join::WITH, 'olv.value = olv_obj.id')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('status_field.id', $qb->expr()->literal($statusFieldId)),
                    //$qb->expr()->eq('c_field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('olv_obj.id', $qb->expr()->literal($taskId)),
                )
            )
            ->groupBy('o.id', 'status_olv.value');

        return $this->acl->acceptPermissionForQuery('show', $objects, $this->security->getUser());
    }


    protected function getTasksQuery($taskId = null, $fields = [])
    {
        $parentTaskFieldId = $this->parentTaskFieldId;
        $statusFieldId     = $this->statusTaskFieldId;

        $subQuery = $this->getChildrenTasksQueryExists('o');

        /** @var QueryBuilder $qb */
        $qb = $this->entityManager->getRepository(DataObject::class)->createQueryBuilder('o')
                                  ->select(sprintf('o, (%s), status_olv.value', $subQuery->getDQL()));

        $objects = $qb
            ->leftJoin('o.type', 'type')
            ->leftJoin('o.values', 'vals')
            ->leftJoin('vals.object_type_field', 'field')
            ->leftJoin('o.values', 'p_vals')
            ->leftJoin('p_vals.object_type_field', 'p_field')
            ->leftJoin(ObjectLinkValue::class, 'p_olv', Join::WITH, 'p_vals.id = p_olv.id')
            ->leftJoin('o.values', 'status_vals')
            ->leftJoin('status_vals.object_type_field', 'status_field')
            ->leftJoin(ObjectLiteralValue::class, 'status_olv', Join::WITH, 'status_vals.id = status_olv.id')
            ->leftJoin(ObjectLinkValue::class, 'olv', Join::WITH, 'vals.id = olv.id')
            //->leftJoin(ObjectLinkValue::class, 'c_olv', Join::WITH, 'c_olv.value = o.id')
            //->leftJoin(ObjectValue::class, 'c_vals', Join::WITH, 'c_vals.id = c_vals.id')
            //->leftJoin('c_vals.object_type_field', 'c_field')
            ->where(
                $qb->expr()->andX(
                    $qb->expr()->eq('field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('status_field.id', $qb->expr()->literal($statusFieldId)),
                    //$qb->expr()->eq('c_field.id', $qb->expr()->literal($parentTaskFieldId)),
                    $qb->expr()->eq('p_field.id', $qb->expr()->literal($parentTaskFieldId)),
                )
            )
            ->groupBy('o.id', 'status_olv.value');

        if ($taskId) {
            $objects->andWhere(
                $objects->expr()->eq('o.id', $objects->expr()->literal($taskId))
            );
        } else {
            $objects->andWhere(
                $qb->expr()->isNull('p_olv.value'),
            );
        }

        return $this->acl->acceptPermissionForQuery('show', $objects, $this->security->getUser());
    }


    protected function parseCoreTasks($taskId, $tasks = []): array
    {
        /** @var DataObject $task */
        $task = $this->entityManager->find(DataObject::class, $taskId);

        if ( ! $task) {
            return $tasks;
        }

        $parentTask = $this->getParentTaskFromTask($task);

        if ( ! $this->taskInTasksArray($task->getId(), $tasks)) {
            $tempTask = $this->getTasksQuery($task->getId())->getQuery()->getOneOrNullResult();

            if ($tempTask) {
                $tasks = array_merge($tasks, $this->prepareData([ $tempTask ], $task->getId()));
            }
        }

        if ($parentTask === null) {
            return $tasks;
        }

        do {
            $task       = $parentTask;
            $parentTask = $this->getParentTaskFromTask($task);

            if ( ! $this->taskInTasksArray($task->getId(), $tasks)) {
                $nullOrTask = $this->getTasksQuery($task->getId())->getQuery()->getOneOrNullResult();

                if ($nullOrTask) {
                    $tasks = array_merge($tasks, $this->prepareData([ $nullOrTask ]));
                }
            }
        } while ($parentTask !== null);

        return $tasks;
    }


    protected function taskInTasksArray($taskId, $taskArray)
    {
        $existedTask = null;

        foreach ($taskArray as $task) {
            if ($task['id'] === $taskId) {
                $existedTask = $task;
            }
        }

        return $existedTask;
    }


    /**
     * @param DataObject[] $tasks
     * @param null         $currentTaskId
     * @param bool         $withKeys
     *
     * @param null         $parentFieldId
     *
     * @return array
     */
    protected function prepareData($tasks, $currentTaskId = null, $withKeys = false, $parentFieldId = null)
    {
        $tasksFormatted = [];

        if (null === $parentFieldId) {
            $parentFieldId = $this->parentTaskFieldId;
        }

        foreach ($tasks as $taskArray) {
            //$status = $taskArray['value'];
            $count = $taskArray[1];
            $task  = $taskArray[0];

            $data = array_merge([
                'id'          => $task->getId(),
                'title'       => $task->getTitle(),
                'parent'      => $this->getValueIdFromObject($task, $parentFieldId),
                'link'        => $task->getLink(),
                'createdAt'   => $task->getCreatedAt(),
                'creator'     => $task->getCreator() ? ($task->getCreator()->getLastname() . ' ' . $task->getCreator()->getFirstname()) : null,
                'hasChildren' => $count > 0,
                'children'    => [],
                'current'     => $currentTaskId === $task->getId(),
                'statuses'    => [],
                'labels'      => $task->getLabelsArray()
            ], $this->prepareField($task));

            if ($withKeys) {
                $tasksFormatted[$task->getId()] = $data;
            } else {
                $tasksFormatted[] = $data;
            }
        }

        return $tasksFormatted;
    }


    /**
     * @param DataObject[] $tasks
     *
     * @param null         $currentTaskId
     *
     * @return array
     */
    protected function prepareDataStage($tasks, $currentTaskId = null)
    {
        $tasksFormatted = [];

        foreach ($tasks as $taskArray) {
            $count = $taskArray[1];
            $task  = $taskArray[0];

            $tasksFormatted[] = array_merge([
                'id'          => $task->getId(),
                'title'       => $task->getTitle(),
                'parent'      => $this->getStageTaskValueFromTask($task),
                'link'        => $task->getLink(),
                'createdAt'   => $task->getCreatedAt(),
                'creator'     => $task->getCreator() ? ($task->getCreator()->getLastname() . ' ' . $task->getCreator()->getFirstname()) : null,
                'hasChildren' => $count > 0,
                'children'    => [],
                'current'     => $currentTaskId === $task->getId(),
                'statuses'    => [],
                'labels'      => $task->getLabelsArray()
            ], $this->prepareField($task));
        }

        return $tasksFormatted;
    }


    /**
     * @param DataObject[] $tasks
     *
     * @param null         $currentTaskId
     *
     * @return array
     */
    protected function prepareDataTracker($tasks, $currentTaskId = null)
    {
        $tasksFormatted = [];

        foreach ($tasks as $taskArray) {
            $count = $taskArray[1];
            $task  = $taskArray[0];

            $tasksFormatted[] = array_merge([
                'id'          => $task->getId(),
                'title'       => $task->getTitle(),
                'parent'      => $this->getTrackerTaskValueFromTask($task),
                'link'        => $task->getLink(),
                'createdAt'   => $task->getCreatedAt(),
                'creator'     => $task->getCreator() ? ($task->getCreator()->getLastname() . ' ' . $task->getCreator()->getFirstname()) : null,
                'hasChildren' => $count > 0,
                'children'    => [],
                'current'     => $currentTaskId === $task->getId(),
                'statuses'    => [],
                'labels'      => $task->getLabelsArray()
            ], $this->prepareField($task));
        }

        return $tasksFormatted;
    }


    /**
     * @param DataObject[] $stages
     *
     * @return array
     */
    protected function prepareStageData($stages)
    {
        $stagesFormatted = [];

        foreach ($stages as $stage) {
            $stagesFormatted[] = array_merge([
                'id'          => $stage->getId(),
                'title'       => $stage->getTitle(),
                'parent'      => $this->getParentTaskValueFromTask($stage),
                'link'        => $stage->getLink(),
                'createdAt'   => $stage->getCreatedAt(),
                'creator'     => $stage->getCreator() ? ($stage->getCreator()->getLastname() . ' ' . $stage->getCreator()->getFirstname()) : null,
                'hasChildren' => true,
                'children'    => [],
                'current'     => false,
                'statuses'    => [],
                'labels'      => $stage->getLabelsArray()
            ], $this->prepareField($stage));
        }

        return $stagesFormatted;
    }


    protected function prepareField(DataObject $dataObject)
    {
        $fields = [];

        foreach ($dataObject->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || ! $objectValue->getObjectTypeField()->getDescription()) {
                continue;
            }

            $otf = $objectValue->getObjectTypeField();

            if ($objectValue instanceof ObjectLinkValue) {
                $val   = $objectValue->getValue() ? $objectValue->getValue()->getTitle() : null;
                $valId = $objectValue->getValue() ? $objectValue->getValue()->getId() : null;

                $fields[$otf->getDescription()]         = $val;
                $fields[$otf->getDescription() . '_id'] = $valId;
            } else {
                $val = $objectValue->getValue();

                $fields[$otf->getDescription()] = $val;
            }
        }

        return $fields;
    }


    protected function loadChildren(&$tasksDataArray)
    {
        foreach ($tasksDataArray as $key => $taskData) {
            if ( ! $taskData['hasChildren']) {
                continue;
            }

            $tasksDataArray[$key]['children'] = $this->getChildrenTasks($taskData['id']);

            $this->loadChildren($tasksDataArray[$key]['children']);
        }
    }


    protected function checkColors(&$projects)
    {
        foreach ($projects as &$project) {
            foreach ($project['children'] as &$stage) {
                $this->checkWhite($stage['children'], $stage);
                $this->checkGreen($stage['children'], $stage);
                $this->checkRed($stage['children'], $stage);
                $this->checkOrange($stage['children'], $stage);

                $color = 'white';

                if (
                    $stage['white'] === true &&
                    $stage['green'] === false &&
                    $stage['red'] === false &&
                    $stage['orange'] === false
                ) {
                    $color = 'white';
                }

                if (
                    $stage['white'] === false &&
                    $stage['green'] === true &&
                    $stage['red'] === false &&
                    $stage['orange'] === false
                ) {
                    $color = 'green';
                }

                if (
                    $stage['white'] === false &&
                    $stage['green'] === false &&
                    $stage['red'] === false &&
                    $stage['orange'] === true
                ) {
                    $color = 'orange';
                }

                if (
                    $stage['red'] === true
                ) {
                    $color = 'red';
                }

                $stage['color']    = $color;
                $stage['children'] = [];
            }
        }
    }


    protected function checkOrange(&$tasksDataArray, &$parentItem)
    {
        $parentItem['orange'] = false;

        if (
            $parentItem['white'] === false
            && $parentItem['red'] === false
            && $parentItem['green'] === false
        ) {
            $parentItem['orange'] = true;
        }
    }


    protected function checkGreen(&$tasksDataArray, &$parentItem)
    {
        $parentItem['green'] = false;

        $closed  = false;
        $expired = false;

        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            if ($tasksDataItem['status'] === $this->doneStatusName) {
                $closed = true;
            }
        }

        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            if ($tasksDataItem['status'] !== $this->doneStatusName) {
                try {
                    $date = new \DateTime($tasksDataItem['date_start']);
                } catch (\Throwable $exception) {
                    $date = null;
                }
                $currentDate = new \DateTime();

                if ($date !== null && $currentDate > $date) {
                    $expired = true;
                }
            }
        }

        if ($closed && ! $expired) {
            $parentItem['green'] = true;
        }
    }


    protected function checkWhite(&$tasksDataArray, &$parentItem)
    {
        $parentItem['white'] = true;

        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            if ($tasksDataItem['status'] === 'В работе' || $tasksDataItem['status'] === $this->doneStatusName) {
                $parentItem['white'] = false;
            }
        }
    }


    protected function checkRed(&$tasksDataArray, &$parentItem)
    {
        $parentItem['red'] = false;

        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            $date        = \DateTime::createFromFormat('d.m.Y', $tasksDataItem['date_end']);
            $currentDate = new \DateTime();

            if (($tasksDataItem['status'] === 'Новая' || $tasksDataItem['status'] === 'Возвращена на доработку') && $currentDate >= $date) {
                $parentItem['red'] = true;
            }
        }
    }


    protected function countOfDone(&$tasksDataArray)
    {
        foreach ($tasksDataArray as $taskDataKey => &$parentDateItem) {
            $projectTotalCount = 0;
            $projectDoneCount  = 0;

            foreach ($parentDateItem['children'] as &$tasksDataItem) {
                $tasksDataItem['done_tasks'] = isset($tasksDataItem['statuses'][$this->doneStatusName]) ? $tasksDataItem['statuses'][$this->doneStatusName] : 0;

                $totalCount = 0;

                foreach ($tasksDataItem['statuses'] as $status) {
                    $totalCount += $status;
                }

                $tasksDataItem['total_tasks'] = $totalCount;

                $projectTotalCount += $tasksDataItem['total_tasks'];
                $projectDoneCount  += $tasksDataItem['done_tasks'];
            }

            $parentDateItem['total_tasks'] = $projectTotalCount;
            $parentDateItem['done_tasks']  = $projectDoneCount;
        }
    }


    protected function loadProjects(&$tasksDataArray)
    {
        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            array_walk_recursive($tasksDataItem['children'], function ($val, $key) use (&$tasksDataItem, $taskDataKey, $tasksDataArray) {
                if ($key === 'region') {

                    $tasksDataArray[$taskDataKey]['projects'][$val] = 1;
                }
            });

            $this->loadProjects($tasksDataItem['children']);
        }
    }


    protected function loadStatuses(&$tasksDataArray)
    {
        foreach ($tasksDataArray as $taskDataKey => &$tasksDataItem) {
            //if ( ! $tasksDataItem['hasChildren']) {
            //    continue;
            //}

            array_walk_recursive($tasksDataItem['children'], function ($val, $key) use (&$tasksDataItem, $taskDataKey, $tasksDataArray) {
                if ($key === 'status') {

                    if (array_key_exists($val, $tasksDataItem['statuses'])) {
                        $tasksDataArray[$taskDataKey]['statuses'][$val] += 1;
                    } else {
                        $tasksDataArray[$taskDataKey]['statuses'][$val] = 1;
                    }
                }
            });

            $this->loadStatuses($tasksDataItem['children']);
        }
    }


    protected function getParentTaskValueFromTask(DataObject $task): ?string
    {
        $val = null;

        foreach ($task->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || $objectValue->getObjectTypeField()->getId() !== $this->parentTaskFieldId) {
                continue;
            }

            $val = $objectValue->getValue() ? $objectValue->getValue()->getId() : null;
        }

        return $val;
    }


    protected function getValueIdFromObject(DataObject $object, $fieldId)
    {
        $val = null;

        foreach ($object->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || $objectValue->getObjectTypeField()->getId() !== $fieldId) {
                continue;
            }

            $val = $objectValue->getValue() ? $objectValue->getValue()->getId() : null;
        }

        return $val;
    }


    protected function getStageTaskValueFromTask(DataObject $task): ?string
    {
        $val = null;

        foreach ($task->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || $objectValue->getObjectTypeField()->getId() !== $this->stageTaskFieldId) {
                continue;
            }

            $val = $objectValue->getValue() ? $objectValue->getValue()->getId() : null;
        }

        return $val;
    }


    protected function getTrackerTaskValueFromTask(DataObject $task): ?string
    {
        $val = null;

        foreach ($task->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || $objectValue->getObjectTypeField()->getId() !== $this->trackerTaskFieldId) {
                continue;
            }

            $val = $objectValue->getValue() ? $objectValue->getValue()->getId() : null;
        }

        return $val;
    }


    protected function buildTree(array &$elements, $parentId = null, $parentField = 'parent')
    {
        $branch = [];

        foreach ($elements as $element) {
            if ($element[$parentField] == $parentId) {
                $children = $this->buildTree($elements, $element['id'], $parentField);
                if ($children) {
                    $element['children'] = $children;
                }
                $branch[$element['id']] = $element;
                unset($elements[$element['id']]);
            }
        }

        return $branch;
    }


    protected function cleanKeys($arr)
    {
        foreach ($arr as $key => $value) {
            if (is_array($value)) {
                $arr[$key] = $this->cleanKeys($value);
            }
        }

        if (isset($arr['children'])) {
            $arr['children'] = array_values($arr['children']);
        }

        return $arr;
    }


    protected function getParentTaskFromTask(DataObject $task): ?DataObject
    {
        $val = null;

        foreach ($task->getValues() as $objectValue) {
            if ( ! $objectValue->getObjectTypeField() || $objectValue->getObjectTypeField()->getId() !== $this->parentTaskFieldId) {
                continue;
            }

            $val = $objectValue->getValue();
        }

        return $val;
    }


    protected function parseProjectsFromTasks($tasksArray, $projects = [])
    {
        foreach ($tasksArray as $task) {
            if (isset($task['region'])) {
                if ( ! isset($projects[$task['region']])) {
                    $projects[$task['region']] = [
                        'title'    => $task['region'],
                        'parent'   => null,
                        'children' => []
                    ];
                }
            }
        }

        return $projects;
    }


    protected function cleanChildren(&$tasksArray)
    {
        foreach ($tasksArray as $key => $task) {
            $task['children'] = [];
            $tasksArray[$key] = $task;
        }
    }
}