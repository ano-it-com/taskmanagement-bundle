<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject\Tasks;

use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\ObjectTypeField;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLiteralValue;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

class TasksService
{

    /** @var EntityManagerInterface */
    protected $em;

    /** @var string|null */
    protected $tasksTypeId;

    /** @var string|null */
    protected $taskDateStartFieldId;


    public function __construct(EntityManagerInterface $em)
    {
        $this->em                   = $em;
        $this->tasksTypeId          = isset($_ENV['TASK_TYPE']) ? $_ENV['TASK_TYPE'] : null;
        $this->taskDateStartFieldId = isset($_ENV['TASK_DATE_START_FIELD']) ? $_ENV['TASK_DATE_START_FIELD'] : null;
    }


    /**
     * @param DataObject $object
     *
     * @return bool value updated
     */
    public function fillDateStartField(DataObject $object): bool
    {
        if ($object->getType()->getId() === $this->tasksTypeId) {
            $createDateValue = false;
            $exist           = false;

            foreach ($object->getValues() as $objectValue) {
                if ($objectValue->getObjectTypeField()->getId() === $this->taskDateStartFieldId) {
                    $exist = true;

                    if ($objectValue->getValue() === null || trim($objectValue->getValue()) === '') {
                        $createDateValue = true;

                        $object->removeValue($objectValue);
                    }
                }
            }

            if ($exist === false) {
                $createDateValue = true;
            }

            $datetime = $object->getRawCreatedAt();

            if ($createDateValue) {
                $dateStartField = $this->em->getRepository(ObjectTypeField::class)->find($this->taskDateStartFieldId);

                $dateStartValue = new ObjectLiteralValue();
                $dateStartValue->setObjectTypeField($dateStartField);
                $dateStartValue->setValue(date_format($datetime, 'd.m.Y H:i'));
                //$dateStartValue->setValue($datetime->format('d.m.Y'));

                $object->addValue($dateStartValue);
                $dateStartValue->setObject($object);
            }

            return $createDateValue;
        }

        return false;
    }
}
