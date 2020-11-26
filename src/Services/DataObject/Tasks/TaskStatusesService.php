<?php


namespace ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks;


use ANOITCOM\Wiki\Entity\Objects\ObjectType;
use ANOITCOM\Wiki\Entity\Objects\ObjectTypeField;
use Doctrine\ORM\EntityManagerInterface;

class TaskStatusesService
{
    private $entityManager;
    private $taskTypeId;
    private $taskStatusField;
    private $closedStatus;
    private static $statuses;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
        $this->taskTypeId = isset($_ENV['TASK_TYPE']) ? $_ENV['TASK_TYPE'] : null;
        $this->taskStatusField = isset($_ENV['TASK_STATUS_FIELD']) ? $_ENV['TASK_STATUS_FIELD'] : null;
        $this->closedStatus = isset($_ENV['TASK_STATUS_CLOSED']) ? $_ENV['TASK_STATUS_CLOSED'] : null;
        self::$statuses = null;
    }

    public function getStatuses($withoutClosed = false)
    {
        if (self::$statuses === null) {
            if ($this->taskTypeId)  {
                $objectType = $this->entityManager->getRepository(ObjectType::class)->find($this->taskTypeId);
                if ($objectType) {
                    $form = $objectType->getDefaultForm();

                    $objectTypeField = $this->entityManager->getRepository(ObjectTypeField::class)->find($this->taskStatusField);

                    self::$statuses = [];

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
                                if (!$withoutClosed || ($withoutClosed && $settingValue['value'] !== $this->closedStatus)) {
                                    self::$statuses[] = $settingValue['value'];
                                }
                            }
                        }
                    }
                } else {
                    self::$statuses = [];
                }
            } else {
                self::$statuses = [];
            }
        }
        return self::$statuses;
    }
}