<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject;

use ANOITCOM\Wiki\Entity\Groups\UserGroup;
use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\Objects\Values\ObjectLiteralValue;
use ANOITCOM\Wiki\Entity\User;
use ANOITCOM\Wiki\Entity\WikiPage\Category;
use ANOITCOM\Wiki\Entity\WikiPageBlocks\WikiPageBlocks\WikiPageObjectBlock;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;
use Twig\Environment;

class CreateUserGroupFromDataObjectCommand
{

    private $personTypeId;

    private $phoneFieldId;

    private $usernameFieldId;

    private $emailFieldId;

    private $fioFieldId;

    private $regionFieldId;

    private $telegramBotIdField;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    /**
     * @var Security
     */
    private $security;

    /**
     * @var mixed|null
     */
    private $regionType;


    public function __construct(EntityManagerInterface $entityManager, Environment $environment, UrlGeneratorInterface $urlGenerator, Security $security)
    {
        $this->regionType         = isset($_ENV['REGION_TYPE']) ? $_ENV['REGION_TYPE'] : null;
        $this->personTypeId       = isset($_ENV['PERSON_TYPE']) ? $_ENV['PERSON_TYPE'] : null;
        $this->usernameFieldId    = isset($_ENV['PERSON_NAME_FIELD']) ? $_ENV['PERSON_NAME_FIELD'] : null;
        $this->phoneFieldId       = isset($_ENV['PERSON_PHONE_FIELD']) ? $_ENV['PERSON_PHONE_FIELD'] : null;
        $this->emailFieldId       = isset($_ENV['PERSON_EMAIL_FIELD']) ? $_ENV['PERSON_EMAIL_FIELD'] : null;
        $this->fioFieldId         = isset($_ENV['PERSON_FIO_FIELD']) ? $_ENV['PERSON_FIO_FIELD'] : null;
        $this->regionFieldId      = isset($_ENV['PERSON_REGION_FIELD']) ? $_ENV['PERSON_REGION_FIELD'] : null;
        $this->telegramBotIdField = isset($_ENV['PERSON_TELEGRAM_BOT_ID_FIELD']) ? $_ENV['PERSON_TELEGRAM_BOT_ID_FIELD'] : null;
        $this->entityManager      = $entityManager;
        $this->environment        = $environment;
        $this->urlGenerator       = $urlGenerator;
        $this->security           = $security;
    }


    private function createGroup(DataObject $dataObject): ?UserGroup
    {
        $update          = true;
        $group           = null;
        $category        = $dataObject->getPage() ? $dataObject->getPage()->getParent() : null;
        $defaultCategory = $dataObject->getType()->getDefaultCategory();

        //if ( ! $regionsCategory) {
        //    throw new \LogicException(sprintf('Отсутствует категория регионов'));
        //}

        $groupName = $dataObject->getTitle();
        $group     = $dataObject->getGroup();

        $existedObject = $this
            ->entityManager
            ->getRepository(DataObject::class)
            ->findOneBy([
                'title' => $groupName,
                'type'  => $this->regionType
            ]);

        if ($existedObject) {
            if ($existedObject->getId() !== $dataObject->getId()) {
                throw new \LogicException(sprintf('Группа с названием %s уже существует', $groupName));
            }
        }

        //foreach ($dataObject->getValues() as $objectValue) {
        //    if ($objectValue->getObjectTypeField()->getId() === 'c3ce333a-3446-4dd0-87fd-17dd852ea22e') {
        //        $groupName = $objectValue->getValue();
        //
        //        $group = $dataObject->getGroup();
        //
        //        if ($group) {
        //            $category = $group->getCategory();
        //        }
        //
        //        $existedObjects = $this
        //            ->entityManager
        //            ->getRepository(DataObject::class)
        //            ->findByObjectTypeFieldValue($objectValue->getObjectTypeField(), $groupName)
        //            ->getQuery()
        //            ->getResult();
        //
        //        if ( ! empty($existedObjects)) {
        //            if ($existedObjects[0]->getId() !== $dataObject->getId()) {
        //                throw new \LogicException(sprintf('Регион %s уже существует', $groupName));
        //            }
        //        }
        //    }
        //}

        if ( ! $group) {
            $update = false;
            $group  = new UserGroup();
            $group->setObject($dataObject);
            $this->entityManager->persist($group);
        }

        //if ( ! $category) {
        $objectCategory = $group->getCategory();

        if ( ! $objectCategory) {
            $objectCategory = $dataObject->getPage();
        }

        //if (isset($objectCategory) && $objectCategory) {
        //    $category = $objectCategory;
        $group->setCategory($objectCategory);

        if ( ! $update) {
            $group->setWorkingDirectory($objectCategory);
        }
        //} else {
        //    throw new \LogicException(sprintf('Отсутствует категория у объекта'));
        //}

        if ( ! $category) {
            $objectCategory->setParent($defaultCategory);
            $dataObject->setParent($defaultCategory);
        } else {
            $objectCategory->setParent($category);
        }
        //}

        $group->setTitle($dataObject);

        return $group;
    }


    /**
     * @param DataObject $dataObject
     *
     * @return UserGroup|null
     */
    public function __invoke(DataObject $dataObject): ?UserGroup
    {
        if (
            ! $this->personTypeId ||
            ! $this->usernameFieldId ||
            ! $this->phoneFieldId ||
            ! $this->emailFieldId ||
            ! $this->fioFieldId ||
            ! $this->regionFieldId
        ) {
            throw new \LogicException('Отсутствует маппинг полей');
        }

        return $this->createGroup($dataObject);
    }

}