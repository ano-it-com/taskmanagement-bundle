<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks;

use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\ObjectValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLinkValue;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLiteralValue;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\Security;
use WikiAclBundle\Services\Acl;

class TasksTableService
{

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var Acl
     */
    private $acl;

    /**
     * @var Security
     */
    private $security;

    private $parentTaskFieldId;


    public function __construct(EntityManagerInterface $entityManager, Acl $acl, Security $security)
    {
        $this->entityManager     = $entityManager;
        $this->acl               = $acl;
        $this->security          = $security;
        $this->parentTaskFieldId = isset($_ENV['TASK_PARENT_FIELD']) ? $_ENV['TASK_PARENT_FIELD'] : null;
        $this->statusTaskFieldId = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
    }


    public function getTable()
    {
        //$coreTasks = $this->getTasksQuery();
        //
        //return $coreTasks;
    }




}