<?php

namespace ANOITCOM\TaskmanagementBundle\Controller\API\DataObjects;

use ANOITCOM\Wiki\Helpers\Response\Json\FailureJsonResponse;
use ANOITCOM\Wiki\Helpers\Response\Json\SuccessJsonResponse;
use ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks\TasksKanbanService;
use ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks\TasksTreeService;
use ANOITCOM\Wiki\Services\PageQuery\PageQueryService;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class TaskController
 * @package ANOITCOM\TaskmanagementBundle\Controller\API\DataObjects
 *
 * @IsGranted({"ROLE_USER"})
 */
class TaskController extends AbstractController
{

    /**
     * @var PageQueryService
     */
    private $pageQueryService;


    public function __construct(PageQueryService $pageQueryService)
    {
        $this->pageQueryService = $pageQueryService;
    }


    /**
     * @param Request            $request
     * @param TasksKanbanService $tasksKanbanService
     *
     * @return JsonResponse
     *
     * @Route(path="/api/wiki/tasks/kanban/status", methods={"GET"}, name="api_wiki_get_kanban_tasks_by_status")
     */
    public function getTasksForStatus(Request $request, TasksKanbanService $tasksKanbanService)
    {
        $status = $request->query->get('status', null);
        $page   = $request->query->getInt('page', 1);
        $limit  = $request->query->getInt('limit', 25);

        if ( ! $status) {
            return FailureJsonResponse::make([], 'Status is not defined');
        }

        $tasks = $tasksKanbanService->getTasksByStatus($status, $limit, $page);

        return SuccessJsonResponse::make($tasks);
    }


    /**
     * @param Request            $request
     * @param TasksKanbanService $tasksKanbanService
     *
     *
     * @Route(path="/api/wiki/tasks/kanban/status", methods={"POST"}, name="api_wiki_set_task_status")
     *
     * @return JsonResponse
     */
    public function setTaskStatus(Request $request, TasksKanbanService $tasksKanbanService)
    {
        $requestData = json_decode($request->getContent(), JSON_OBJECT_AS_ARRAY);

        $taskId  = $requestData['taskId'] ?? null;
        $status  = $requestData['status'] ?? null;
        $comment = $requestData['comment'] ?? null;

        if ( ! $taskId || ! $status) {
            return FailureJsonResponse::make([], 'Invalid arguments', 500);
        }

        try {
            $tasksKanbanService->setStatusForTaskId($taskId, $status, $comment);
        } catch (\Throwable $exception) {
            return FailureJsonResponse::make([], 'Something went wrong', 500);
        }

        return SuccessJsonResponse::make([], 'OK');
    }


    /**
     * @param Request          $request
     *
     * @param                  $id
     * @param TasksTreeService $treeService
     *
     * @return JsonResponse
     *
     * @Route(path="/api/wiki/tasks/project/{id}", methods={"GET"}, name="api_wiki_get_project_tasks")
     *
     */
    public function getTasksTable(Request $request, $id, TasksTreeService $treeService)
    {
        $tasks = $treeService->getTasksOfProject($id);

        return SuccessJsonResponse::make($tasks);
    }


    /**
     * @param Request          $request
     *
     * @param TasksTreeService $treeService
     * @Route(path="/api/wiki/tasks", methods={"GET"}, name="api_wiki_core_tasks")
     *
     * @return JsonResponse
     */
    public function getCoreTasks(Request $request, TasksTreeService $treeService)
    {
        $createLink = null;
        $createText = null;
        $categories = $request->query->get('categories', []);
        $fields     = $request->query->get('fields', []);
        $type       = $request->query->get('type', null);
        $search     = $request->query->get('search', null);
        $page       = $request->query->getInt('page', 1);
        $orderBy    = $request->query->get('order_by', null);
        $orderDir   = $request->query->get('order_dir', null);
        $limit      = $request->query->getInt('limit', 25);
        $format     = $request->query->get('view_type', PageQueryService::FORMAT_TABLE);

        if ( ! $categories && ! $fields && ! $type) {
            throw $this->createNotFoundException();
        }

        /**
         * @var PaginationInterface $result
         */
        [ 'result' => $result ] = $this->pageQueryService->createQuery($categories, $fields, $type, $search, $page, $limit, $orderBy, $orderDir, false, true, [
            PageQueryService::FORMAT     => $format,
            PageQueryService::TREE_FIELD => 'd033aff9-d95f-4218-a89e-57b7e4b5e20f'
        ]);

        return SuccessJsonResponse::make($result);
    }


    /**
     * @param Request          $request
     *
     * @param                  $id
     * @param TasksTreeService $treeService
     *
     * @return JsonResponse
     *
     * @Route(path="/api/wiki/tasks/{id}", methods={"GET"}, name="api_wiki_get_task")
     *
     */
    public function getTask(Request $request, $id, TasksTreeService $treeService)
    {
        $tasks = $treeService->getTask($id);
        $task  = isset($tasks[0]) ? $tasks[0] : null;

        return SuccessJsonResponse::make($task);
    }


    /**
     * @param Request          $request
     *
     * @param                  $id
     * @param TasksTreeService $treeService
     *
     * @return JsonResponse
     * @Route(path="/api/wiki/tasks/{id}/core", methods={"GET"}, name="api_wiki_core_task")
     *
     */
    public function getCoreTask(Request $request, $id, TasksTreeService $treeService)
    {
        $tree = $treeService->getCoreTask($id);

        return SuccessJsonResponse::make($tree);
    }


    /**
     * @param Request          $request
     *
     * @param                  $id
     * @param TasksTreeService $treeService
     *
     * @return JsonResponse
     *
     * @Route(path="/api/wiki/tasks/{id}/children", methods={"GET"}, name="api_wiki_get_children_tasks")
     *
     */
    public function getChildrenTasks(Request $request, $id, TasksTreeService $treeService)
    {
        $tasks = $treeService->getChildrenTasks($id);

        return SuccessJsonResponse::make($tasks);
    }

}