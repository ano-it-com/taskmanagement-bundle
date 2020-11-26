<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks;

use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\ObjectTypeField;
use ANOITCOM\Wiki\Entity\Objects\ObjectValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLinkValue;
use ANOITCOM\Wiki\Entity\PageQuery\PageQuery;
use ANOITCOM\Wiki\Services\DataObject\FieldManager\ObjectFieldManager;
use ANOITCOM\Wiki\Services\PageQuery\TreeService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Security\Core\Security;
use Symfony\Component\VarDumper\VarDumper;
use WikiAclBundle\Services\Acl;

class TasksKanbanService extends TreeService
{

    public $taskType;

    /**
     * @var mixed|null
     */
    private $statusTaskFieldId;

    /**
     * @var mixed|null
     */
    private $commentTaskFieldId;

    /**
     * @var mixed|null
     */
    private $parentTaskFieldId;

    /**
     * @var PaginatorInterface
     */
    private $paginator;


    public function __construct(EntityManagerInterface $entityManager, Acl $acl, Security $security, PaginatorInterface $paginator, ObjectFieldManager $objectFieldManager)
    {
        parent::__construct($entityManager, $acl, $security, $objectFieldManager);

        $this->taskType           = isset($_ENV['TASK_TYPE']) ? $_ENV['TASK_TYPE'] : null;
        $this->statusTaskFieldId  = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->commentTaskFieldId = isset($_ENV['TASK_COMMENT_FIELD']) ? $_ENV['TASK_COMMENT_FIELD'] : null;
        $this->parentTaskFieldId  = isset($_ENV['TASK_PARENT_FIELD']) ? $_ENV['TASK_PARENT_FIELD'] : null;
        $this->paginator          = $paginator;
    }


    public function getTasksByStatus($queryBuilder, $limit = 25, $page = 1, $alias = 'object', $filters = [])
    {
        $result = $queryBuilder->select(sprintf('%s, count(parent_olv)', $alias))
                               ->leftJoin(ObjectLinkValue::class, 'parent_olv', Join::WITH, sprintf('parent_olv.value = %s.id', $alias))
                               ->leftJoin(ObjectValue::class, 'parent_ov', Join::WITH, 'parent_olv.id = parent_ov.id')
                               ->groupBy(sprintf('%s.id', $alias))
                               ->orderBy(sprintf('%s.title', $alias), 'ASC')
                               ->getQuery()
                               ->getResult();

        return $this->prepareData($result, $this->parentTaskFieldId, null, false);
    }


    public function setStatusForTaskId($taskId, $status, $comment)
    {
        /** @var DataObject $task */
        $task = $this->entityManager->getRepository(DataObject::class)->find($taskId);

        foreach ($task->getValues() as $value) {
            if ($value->getObjectTypeField()->getId() === $this->statusTaskFieldId) {
                $value->setValue($status);
            }
            if ($value->getObjectTypeField()->getId() === $this->commentTaskFieldId) {
                $value->setValue($comment);
            }
        }

        $this->entityManager->flush();
    }


    public function getTasksByStatuses(QueryBuilder $queryBuilder, $limit = 100, $page = 1, $alias = 'object')
    {
        $data     = [];
        $statuses = $this->getStatuses();

        $result =
            $queryBuilder
                ->select(sprintf('%s, count(parent_olv)', $alias))
                ->leftJoin(
                    ObjectLinkValue::class,
                    'parent_olv',
                    Join::WITH,
                    \sprintf('parent_olv.value = %s.id', $alias)
                )
                ->leftJoin(
                    ObjectValue::class,
                    'parent_ov',
                    Join::WITH,
                    'parent_olv.id = parent_ov.id'
                )
                ->groupBy(
                    \sprintf('%s.id', $alias)
                )
                ->orderBy(
                    \sprintf('%s.title', $alias),
                    'ASC'
                )
                ->setMaxResults(500);

        $r = $result->getQuery()->getResult();

        $items = $this->prepareData($r, $this->parentTaskFieldId, null, false);

        $statusClosed              = isset($_ENV['TASK_STATUS_CLOSED']) ? $_ENV['TASK_STATUS_CLOSED'] : null;
        $statusReturnedForRevision = isset($_ENV['TASK_RETURNED_FOR_REVISION']) ? $_ENV['TASK_RETURNED_FOR_REVISION'] : null;
        $user                      = $this->security->getUser();
        $cyrGroup                  = isset($_ENV['CYR_ROLE']) ? $_ENV['CYR_ROLE'] : null;
        $userIsCyr                 = $cyrGroup && in_array($cyrGroup, $user->getGroupsIds());

        foreach ($statuses as $status) {
            $canUpdateToStatus = true;
            if (in_array($status['id'], [ $statusClosed, $statusReturnedForRevision ])) {
                $canUpdateToStatus = $userIsCyr;
            }

            $data[$status['title']] = [
                'id'                => $status['id'],
                'title'             => $status['title'],
                'canUpdateToStatus' => $canUpdateToStatus,
                'items'             => [],
            ];
        }

        foreach ($items as $item) {
            if (isset($data[$item['status']])) {
                $data[$item['status']]['items'][] = $item;
            }
        }

        return array_values($data);
    }


    public function getStatuses()
    {
        return $this->prepareStatuses();
    }


    protected function prepareStatuses()
    {
        $values = [];

        $objectTypeField = $this->entityManager->getRepository(ObjectTypeField::class)->find($this->statusTaskFieldId);

        if ( ! $objectTypeField->getObjectType()) {
            return $values;
        }

        $objectType = $objectTypeField->getObjectType();

        if ( ! $objectType->getDefaultForm()) {
            return $values;
        }

        $form = $objectType->getDefaultForm();

        foreach ($form->getObjects() as $formObject) {
            if ( ! $formObject->getObjectType()) {
                continue;
            }
            $formObjectType = $formObject->getObjectType();

            if ($formObjectType->getId() !== $objectType->getId()) {
                continue;
            }

            foreach ($formObject->getFields() as $formField) {
                if ( ! $formField->getObjectTypeField()) {
                    continue;
                }

                $formFieldType = $formField->getObjectTypeField();

                if ($formFieldType->getId() !== $objectTypeField->getId()) {
                    continue;
                }

                $settings = $formField->getSettings();

                if ( ! isset($settings['values']) || ! is_array($settings['values'])) {
                    continue;
                }

                foreach ($settings['values'] as $settingValue) {
                    $values[] = [
                        'id'    => $settingValue['value'],
                        'title' => $settingValue['title'],
                    ];
                }
            }
        }

        return $values;
    }
}