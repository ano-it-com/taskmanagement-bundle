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
use PHPUnit\Util\Exception;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;
use function Doctrine\ORM\QueryBuilder;

class ProcessCardCountOfProjects implements HandlerInterface
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

    /**
     * @var string
     */
    private $projectCardTypeId;


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
//        $this->projectCardTypeId = isset($_ENV['PROJECT_CARD_TYPE']) ? $_ENV['PROJECT_CARD_TYPE'] : null;
        $this->projectCardTypeId = isset($_ENV['PROCESS_CARD_TYPE']) ? $_ENV['PROCESS_CARD_TYPE'] : null; ;//'62f2690e-fb42-459e-816e-7c7d97d97f15';
//        $this->taskRegionFieldId = isset($_ENV['TASK_REGION_FIELD']) ? $_ENV['TASK_REGION_FIELD'] : null;
//        $this->taskPersonFieldId = isset($_ENV['TASK_EMPLOYER_FIELD']) ? $_ENV['TASK_EMPLOYER_FIELD'] : null;
//        $this->taskStatusFieldId = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->taskParentFieldId = isset($_ENV['PROCESS_CARD_TASK_PARENT_FIELD']) ? $_ENV['PROCESS_CARD_TASK_PARENT_FIELD'] : null;
//        $this->taskStageFieldId  = isset($_ENV['TASK_TRACKER_FIELD']) ? $_ENV['TASK_TRACKER_FIELD'] : null;




        $this->headerTypeId = 'b9d9e291-82f7-4ef8-8880-4f91bd880773';
        $this->projectFieldId = '0a5ac457-20ab-44f5-874e-e963cc170545';
        $this->trackerFieldId = '35031dda-b829-4d70-a5eb-19667856777a';
        $this->descriptionFieldId = '9331b438-6324-4bfd-9c4c-056c09661863';
        $this->statusFieldId = '6e41cbc2-784c-4eec-979e-97b9c3862a40';
        $this->priorityTypeId = '1a0d5001-cd2d-4d4f-a5c5-aadd1b373887';





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

        $pregMatch = '/\%projectProcessCard%([\s\S]+?)\%%/';

        $matches      = [];
        $matchesCount = preg_match_all($pregMatch, $html, $matches);

        if (empty($matches[0])) {
            return $block;
        }

        foreach ($matches[1] as $key => $match) {
            $viewType = $match;

            $projectId = null;
            $personId = null;

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

            if (!$projectId && !$personId) {
                continue;
            }

            switch ($match) {
                case PageQuery::VIEW_TYPE_TABLE:
                default:
                    $arrayOfTasks = [];
                    if ($projectId) {
                        try {
                            $arrayOfTasks = $this->treeService->getTasksOfProjectByStage($projectId);
                        } catch (\Exception $exception) {
                            throw new Exception('$arrayOfTasks = $this->treeService->getTasksOfProjectByStage($projectId);');
                        }
                    }
                    if ($personId) {
                        try {
                            $arrayOfTasks = $this->treeService->getTasksOfPerson($personId);
                        } catch (\Exception $exception) {
                            throw new \Exception('$arrayOfTasks = $this->treeService->getTasksOfPerson($personId);');
                        }
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
                    $type       = $this->projectCardTypeId;
                    $search     = null;
                    $page       = 1;
                    $orderBy    = null;
                    $orderDir   = null;
                    $limit      = 500;
                    $format     = PageQueryService::FORMAT_TREE;

                    /**
                     * @var PaginationInterface $result
                     */
                    try {
                        [ 'result' => $result ] = $this->pageQueryService->createQuery($categories, $fields, $type, $search, $page, $limit, $orderBy, $orderDir, false, true, [
                            PageQueryService::FORMAT             => $format,
                            PageQueryService::TREE_FIELD         => $this->taskParentFieldId,
                            PageQueryService::TREE_GROUP_OBJECTS => false,
                        ]);
                    } catch (\Exception $exception) {
                        throw new \Exception('Tree generation failed');
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
        $form = $this->em->getRepository(ObjectType::class)->find($this->projectCardTypeId)->getDefaultForm();
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
                'Добавить карточку процесса',
                $url,
                $form->getId(),
                $formValues,
                'Добавить карточку процесса'
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
            $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskStatusFieldId, $status, $this->taskStageFieldId, $stageId, $this->projectCardTypeId);
        } else {
            $query = '/wiki/page/queries?fields[%s][]=%s&fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
            $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->taskStatusFieldId, $status, $this->projectCardTypeId);
        }

        return $query;
    }


    protected function makeLinkForProjectTasks($projectId)
    {
        $query = '/wiki/page/queries?fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table';
        $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->projectCardTypeId);

        return $query;
    }


    protected function makeLinkForStage($projectId, $stageId)
    {
        $query = '/wiki/page/queries?fields[%s][]=%s&type=%s&page=1&order_by=&order_dir=&limit=25&title=Список задач&view_type=table&fields[%s][]=%s';
        $query = sprintf($query, $this->taskRegionFieldId, $projectId, $this->projectCardTypeId, $this->taskStageFieldId, $stageId);

        return $query;
    }


    public function support(WikiPageBlock $block): bool
    {
        return $block instanceof WikiPageHtmlBlock;
    }


    public function getSort(): int
    {
//        return 31;
        return 34;
    }
}
