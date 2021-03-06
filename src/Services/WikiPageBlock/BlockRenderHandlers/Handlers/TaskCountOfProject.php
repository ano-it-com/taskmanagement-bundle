<?php

namespace ANOITCOM\TaskmanagementBundle\Services\WikiPageBlock\BlockRenderHandlers\Handlers;

use ANOITCOM\Wiki\DTO\Assemblers\FormDataAssembler;
use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\Form\Form;
use ANOITCOM\Wiki\Entity\Objects\ObjectType;
use ANOITCOM\Wiki\Entity\Objects\ObjectTypeField;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLinkValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLiteralValue;
use ANOITCOM\Wiki\Entity\PageQuery\PageQuery;
use ANOITCOM\Wiki\Entity\WikiPage\Category;
use ANOITCOM\Wiki\Entity\WikiPage\WikiPage;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageHtmlBlock;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageObjectBlock;
use ANOITCOM\Wiki\Helpers\Response\Json\SuccessJsonResponse;
use ANOITCOM\Wiki\Repository\WikiPage\WikiPageRepository;
use ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks\TasksTreeService;
use ANOITCOM\Wiki\Services\PageQuery\PageQueryService;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\Pagination\PaginationInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;
use function Doctrine\ORM\QueryBuilder;

class TaskCountOfProject implements HandlerInterface
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
    private $projectTypeId;

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
    private $taskRegionFieldId;

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

    protected $taskStageFieldId;

    /**
     * @var PageQueryService
     */
    private $pageQueryService;


    public function __construct(
        EntityManagerInterface $entityManager,
        Security $security,
        TasksTreeService $treeService,
        UrlGeneratorInterface $urlGenerator,
        FormDataAssembler $formDataAssembler,
        PageQueryService $pageQueryService
    ) {
        $this->em                = $entityManager;
        $this->pageRepo          = $entityManager->getRepository(WikiPage::class);
        $this->security          = $security;
        $this->projectTypeId     = isset($_ENV['REGION_TYPE']) ? $_ENV['REGION_TYPE'] : null;
        $this->personTypeId      = isset($_ENV['PERSON_TYPE']) ? $_ENV['PERSON_TYPE'] : null;
        $this->taskTypeId        = isset($_ENV['TASK_TYPE']) ? $_ENV['TASK_TYPE'] : null;
        $this->taskRegionFieldId = isset($_ENV['TASK_REGION_FIELD']) ? $_ENV['TASK_REGION_FIELD'] : null;
        $this->taskPersonFieldId = isset($_ENV['TASK_EMPLOYER_FIELD']) ? $_ENV['TASK_EMPLOYER_FIELD'] : null;
        $this->taskStatusFieldId = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->taskParentFieldId = isset($_ENV['TASK_PARENT_FIELD']) ? $_ENV['TASK_PARENT_FIELD'] : null;
        $this->taskStageFieldId  = isset($_ENV['TASK_TRACKER_FIELD']) ? $_ENV['TASK_TRACKER_FIELD'] : null;
        $this->treeService       = $treeService;
        $this->urlGenerator      = $urlGenerator;
        $this->formDataAssembler = $formDataAssembler;
        $this->pageQueryService  = $pageQueryService;
    }


    /**
     *
     * @param WikiPageBlock $block
     *
     * @return WikiPageBlock|null
     */
    public function handle(WikiPageBlock $block): ?WikiPageBlock
    {
        $categoryRepo = $this->em->getRepository(Category::class);
        $html         = $block->getValue()->getValue();

        $pregMatch = '/\%projectTasks%([\s\S]+?)\%%/';

        $matches      = [];
        $matchesCount = preg_match_all($pregMatch, $html, $matches);

        if (empty($matches[0])) {
            return $block;
        }

        foreach ($matches[1] as $key => $match) {
            $viewType = $match;

            $projectId = null;
            $personId  = null;

            foreach ($block->getPage()->getBlocks() as $pageBlock) {
                if ( ! $pageBlock instanceof WikiPageObjectBlock) {
                    continue;
                }

                foreach ($pageBlock->getObjects() as $blockObject) {
                    if ($blockObject->getType()->getId() === $this->projectTypeId) {
                        $projectId = $blockObject->getId();
                    } elseif ($blockObject->getType()->getId() === $this->personTypeId) {
                        $personId = $blockObject->getId();
                    }
                }
            }

            if ( ! $projectId && ! $personId) {
                continue;
            }

            switch ($match) {
                case PageQuery::VIEW_TYPE_TABLE:
                default:
                    $arrayOfTasks = [];
                    if ($projectId) {
                        $arrayOfTasks = $this->treeService->getTasksOfProjectByStage($projectId);
                    }
                    if ($personId) {
                        $arrayOfTasks = $this->treeService->getTasksOfPerson($personId);
                    }

                    if (empty($arrayOfTasks) || $this->isChildrenEmpty($arrayOfTasks)) {
                        /** @var WikiPage $blockPage */
                        $blockPage = $block->getPage();

                        $blockPage->removeBlock($block);

                        return null;
                    }

                    $newHtml = $this->makeTable($arrayOfTasks, $projectId);
                    break;

                case PageQuery::VIEW_TYPE_TREE:
                    $createLink = null;
                    $createText = null;
                    $fields     = [];
                    if ($projectId) {
                        $fields = [
                            $this->taskRegionFieldId => [
                                $projectId
                            ]
                        ];
                    }
                    if ($personId) {
                        $fields = [
                            $this->taskPersonFieldId => [
                                $personId
                            ]
                        ];
                    }
                    $categories = [];
                    $type       = $this->taskTypeId;
                    $search     = null;
                    $page       = 1;
                    $orderBy    = null;
                    $orderDir   = null;
                    $limit      = 500;
                    $format     = PageQueryService::FORMAT_TREE;

                    /**
                     * @var PaginationInterface $result
                     */
                    [ 'result' => $result ] = $this->pageQueryService->createQuery($categories, $fields, $type, $search, $page, $limit, $orderBy, $orderDir, false, true, [
                        PageQueryService::FORMAT             => $format,
                        PageQueryService::TREE_FIELD         => $this->taskParentFieldId,
                        PageQueryService::TREE_GROUP_OBJECTS => false,
                    ]);

                    if (empty($result)) {
                        /** @var WikiPage $blockPage */
                        $blockPage = $block->getPage();

                        $blockPage->removeBlock($block);

                        return null;
                    }

                    //return SuccessJsonResponse::make($result);
                    //$arrayOfTasks = $this->treeService->getTasksOfProject($projectId);

                    $newHtml = $this->makeTree($result, $projectId, $personId);
                    break;
            }

            $html = str_replace($matches[0][$key], $newHtml, $html);
        }

        $block->getValue()->setValue($html);

        return $block;
    }


    private function makeTree($data, $projectId = null, $personId = null)
    {
        $html = '';

        $html .= $this->renderTree($data, $projectId, $personId);

        return $html;
    }


    protected function renderTree($data, $projectId = null, $personId = null)
    {
        $form = $this->em->getRepository(ObjectType::class)->find($this->taskTypeId)->getDefaultForm();
        $url  = $this->urlGenerator->generate('api_form_send_data', [ 'id' => $form->getId() ]);

        $tempDataObject = new DataObject();

        $dto = $this->formDataAssembler->writeDTO($form);

        if ($projectId) {
            try {
                $objectTypeField = $this->em->getRepository(ObjectTypeField::class)->find($this->taskRegionFieldId);

                $objectValue = new ObjectLinkValue();
                $objectValue->setObjectTypeField($objectTypeField);
                $objectValueObject = $this->em->getRepository(DataObject::class)->find($projectId);
                $objectValue->setValue($objectValueObject);

                $tempDataObject->addValue($objectValue);

                $dto->setValues([]);
                $this->formDataAssembler->loadDataObject($dto, $tempDataObject);
            } catch (\Throwable $exception) {
            }
        }

        if ($personId) {
            try {
                $objectTypeField = $this->em->getRepository(ObjectTypeField::class)->find($this->taskPersonFieldId);

                $objectValue = new ObjectLinkValue();
                $objectValue->setObjectTypeField($objectTypeField);
                $objectValueObject = $this->em->getRepository(DataObject::class)->find($personId);
                $objectValue->setValue($objectValueObject);

                $tempDataObject->addValue($objectValue);

                $dto->setValues([]);
                $this->formDataAssembler->loadDataObject($dto, $tempDataObject);
            } catch (\Throwable $exception) {
            }
        }

        $formFieldValues = $dto->getValues();
        $formValues      = htmlspecialchars(json_encode($formFieldValues), ENT_QUOTES, 'UTF-8');

        if ( ! $this->security->getUser()->isAllowPageActions()) {
            $buttonTag = '';
        } else {
            $buttonTag = sprintf(
                '<p><a class="btn-primary btn-page-query-modal" data-title="%s" data-url="%s" data-method="post" data-form-id="%s" data-values="%s" target="_blank">%s</a></p>',
                'Добавить задачу',
                $url,
                $form->getId(),
                $formValues,
                'Добавить задачу'
            );
        }

        return sprintf(
            '%s<div class="taskTreeElement" data-items="%s"></div>',
            $buttonTag,
            htmlspecialchars(json_encode($data), ENT_QUOTES, 'UTF-8')
        );
    }


    private function makeTable(array $data, $projectId)
    {
        $html = '';

        $statuses = [];

        foreach ($data as $item) {
            foreach ($item['statuses'] as $status => $statusCount) {
                if ( ! array_key_exists($status, $statuses)) {
                    $statuses[$status] = $statusCount;
                } else {
                    $statuses[$status] += $statusCount;
                }
            }
        }

        $html .= $this->renderHead($data, $statuses);
        $html .= $this->renderBody($data, $statuses, $projectId);
        $html .= $this->renderFooter($projectId);

        return $html;
    }


    private function renderHead(array $data, $statuses)
    {
        $head = '';

        $head .= '
            <table class="table-hover">
                <thead>
                    <tr>';

        $head .= '<th>Название</th>';

        foreach ($statuses as $status => $count) {
            $head .= '<th>' . $status . '</th>';
        }

        $head .= '<th>Всего</th>';

        $head .= '
                    </tr>
                </thead>
                <tbody>
        ';

        return $head;
    }


    private function renderFooter($projectId)
    {

        return '
                </tbody>
                </table>
            <br>
            <a href="' . $this->makeLinkForProjectTasks($projectId) . '">Посмотреть все результаты</a>
        ';
    }


    private function renderBody($items, $statuses, $projectId)
    {
        $html = '';

        foreach ($items as $item) {
            $rawHtml = '<tr>';
            $rawHtml .= '<td><a href="' . $this->makeLinkForStage($projectId, $item['id']) . '">' . $item['title'] . '</a></td>';

            $countOfTasks = 0;

            foreach ($item['statuses'] as $itemStatus => $statusCount) {
                $countOfTasks += $statusCount;
            }

            foreach ($statuses as $status => $count) {
                $founded = false;

                foreach ($item['statuses'] as $itemStatus => $statusCount) {
                    if ($itemStatus === $status) {
                        if ($statusCount == 0) {
                            $rawHtml .= '<td><span style="color: lightgrey">0</span></td>';
                        } else {
                            $rawHtml .= '<td><a href="' . $this->makeLinkForStatusCount($itemStatus, $projectId, $item['id']) . '">' . $statusCount . '</a></td>';
                        }

                        $founded = true;
                        break;
                    }
                }

                if ( ! $founded) {
                    $rawHtml .= '<td><span style="color: lightgrey">0</span></td>';
                }
            }

            if ($countOfTasks == 0) {
                $rawHtml .= '<td><span style="color: lightgrey">' . $countOfTasks . '</span></td>';
            } else {
                $rawHtml .= '<td><a href="' . $this->makeLinkForStage($projectId, $item['id']) . '">' . $countOfTasks . '</a></td>';
            }

            $rawHtml .= "</tr>";

            if ($countOfTasks != 0) {
                $html .= $rawHtml;
            }
        }

        $html         .= "<tr class=\"nosort\">";
        $html         .= '<td>Итого</td>';
        $countOfTasks = 0;
        foreach ($statuses as $status => $count) {
            $countOfTasks += $count;
            $html         .= '<td><a href="' . $this->makeLinkForStatusCount($status, $projectId) . '">' . $count . '</a></td>';
        }
        $html .= '<td>' . $countOfTasks . '</td>';
        $html .= "</tr>";

        return $html;
    }


    protected function makeLinkForStatusCount($status, $projectId, $stageId = null)
    {
        if ($stageId) {
            $query = '/wiki/page/queries?fields[%s][]=%s&fields[%s][]=%s&fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
            $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskStatusFieldId, $status, $this->taskStageFieldId, $stageId, $this->taskTypeId);
        } else {
            $query = '/wiki/page/queries?fields[%s][]=%s&fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
            $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskStatusFieldId, $status, $this->taskTypeId);
        }

        return $query;
    }


    protected function makeLinkForProjectTasks($projectId)
    {
        $query = '/wiki/page/queries?fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
        $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskTypeId);

        return $query;
    }


    protected function makeLinkForStage($projectId, $stageId)
    {
        $query = '/wiki/page/queries?fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table&fields[%s][]=%s';
        $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskTypeId, $this->taskStageFieldId, $stageId);

        return $query;
    }


    public function support(WikiPageBlock $block): bool
    {
        return $block instanceof WikiPageHtmlBlock;
    }


    protected function isChildrenEmpty($arrayOfTasks)
    {
        foreach ($arrayOfTasks as $task) {
            if ( ! empty($task['children'])) {
                return false;
            }
        }

        return true;
    }


    public function getSort(): int
    {
        return 31;
    }
}