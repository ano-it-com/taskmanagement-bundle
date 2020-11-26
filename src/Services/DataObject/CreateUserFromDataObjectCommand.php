<?php

namespace ANOITCOM\TaskmanagementBundle\Services\DataObject;

use ANOITCOM\Wiki\Entity\Groups\UserGroup;
use ANOITCOM\Wiki\Entity\Objects\DataObject;
use ANOITCOM\Wiki\Entity\User;
use ANOITCOM\Wiki\Exception\WikiValidationException;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Twig\Environment;
use ANOITCOM\WikiTGNotificationBundle\Services\Client\TGNotificationClient;

class CreateUserFromDataObjectCommand
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
     * @var \Swift_Mailer
     */
    private $mailer;

    /**
     * @var Environment
     */
    private $environment;

    /**
     * @var UrlGeneratorInterface
     */
    private $urlGenerator;

    private $email;

    private $sendEmail;

    private $defaultPassword;

    /**
     * @var UserPasswordEncoderInterface
     */
    private $passwordEncoder;

    /**
     * @var mixed|null
     */
    private $cyrRole;

    /**
     * @var mixed|null
     */
    private $regionRole;

    /**
     * @var TGNotificationClient
     */
    private $tgclient;


    public function __construct(
        EntityManagerInterface $entityManager,
        \Swift_Mailer $mailer,
        Environment $environment,
        UrlGeneratorInterface $urlGenerator,
        UserPasswordEncoderInterface $passwordEncoder,
        TGNotificationClient $tgclient
    ) {
        $this->personTypeId       = isset($_ENV['PERSON_TYPE']) ? $_ENV['PERSON_TYPE'] : null;
        $this->usernameFieldId    = isset($_ENV['PERSON_NAME_FIELD']) ? $_ENV['PERSON_NAME_FIELD'] : null;
        $this->phoneFieldId       = isset($_ENV['PERSON_PHONE_FIELD']) ? $_ENV['PERSON_PHONE_FIELD'] : null;
        $this->emailFieldId       = isset($_ENV['PERSON_EMAIL_FIELD']) ? $_ENV['PERSON_EMAIL_FIELD'] : null;
        $this->fioFieldId         = isset($_ENV['PERSON_FIO_FIELD']) ? $_ENV['PERSON_FIO_FIELD'] : null;
        $this->regionFieldId      = isset($_ENV['PERSON_REGION_FIELD']) ? $_ENV['PERSON_REGION_FIELD'] : null;
        $this->telegramBotIdField = isset($_ENV['PERSON_TELEGRAM_BOT_ID_FIELD']) ? $_ENV['PERSON_TELEGRAM_BOT_ID_FIELD'] : null;
        $this->email              = isset($_ENV['EMAIL']) ? $_ENV['EMAIL'] : null;
        $this->sendEmail          = isset($_ENV['SEND_EMAIL']) ? $_ENV['SEND_EMAIL'] : true;
        $this->defaultPassword    = isset($_ENV['DEFAULT_PASSWORD']) ? $_ENV['DEFAULT_PASSWORD'] : false;
        $this->email              = isset($_ENV['EMAIL']) ? $_ENV['EMAIL'] : null;

        $this->cyrRole    = isset($_ENV['CYR_ROLE']) ? (int)$_ENV['CYR_ROLE'] : null;
        $this->regionRole = isset($_ENV['REGION_ROLE']) ? (int)$_ENV['REGION_ROLE'] : null;

        $this->entityManager   = $entityManager;
        $this->mailer          = $mailer;
        $this->environment     = $environment;
        $this->urlGenerator    = $urlGenerator;
        $this->passwordEncoder = $passwordEncoder;
        $this->tgclient        = $tgclient;
    }


    public function getPersonTypeID()
    {
        return $this->personTypeId;
    }


    private function createUser(DataObject $dataObject)
    {
        $user   = null;
        $update = false;

        foreach ($dataObject->getValues() as $objectValue) {
            if ($objectValue->getObjectTypeField()->getId() === $this->usernameFieldId) {
                $username = trim($objectValue->getValue());

                $user = $dataObject->getUser();

                $existedObjects = $this
                    ->entityManager
                    ->getRepository(DataObject::class)
                    ->findByObjectTypeFieldValue($objectValue->getObjectTypeField(), $username)
                    ->getQuery()
                    ->getResult();

                if ( ! empty($existedObjects)) {
                    if ($existedObjects[0]->getId() !== $dataObject->getId()) {
                        throw new WikiValidationException(sprintf('Пользователь с ником %s уже существует', $username));
                    }
                }
            }
        }

        if ( ! $user) {
            $user = new User();
            $user->setObject($dataObject);
            $this->entityManager->persist($user);
        } else {
            $update = true;
        }

        if ( ! $update) {
            if ($this->sendEmail == true) {
                $user->setResetPasswordLink(Uuid::uuid4());
            }
        }

        foreach ($user->getGroups() as $exgroup) {
            if ($this->cyrRole) {
                if ($exgroup->getObject() || $exgroup->getCategory()) {
                    //if ($exgroup->getId() !== $this->cyrRole) {
                    $user->removeGroup($exgroup);
                    //}
                }
            } else {
                if ($exgroup->getObject() || $exgroup->getCategory()) {
                    $user->removeGroup($exgroup);
                }
            }
        }

        foreach ($dataObject->getValues() as $objectValue) {
            $value = $objectValue->getValue();

            switch ($objectValue->getObjectTypeField()->getId()) {
                case $this->usernameFieldId:
                    $user->setUsername($value);
                    break;

                case $this->phoneFieldId:
                    $user->setPhone($value);
                    break;

                case $this->emailFieldId:
                    $user->setEmail($value);
                    break;

                case $this->fioFieldId:
                    $fioParts = $this->parseNamesFromString($value);

                    $user->setFirstname($fioParts[0]);
                    $user->setLastname($fioParts[1]);
                    break;

                case $this->telegramBotIdField:
                    $user->setTelegramBotId($value);
                    break;

                case $this->regionFieldId:
                    if ( ! $value) {
                        break;
                    }

                    /** @var DataObject $dataObjectValue */
                    $dataObjectValue = $value;

                    $userGroup = $dataObjectValue->getGroup();

                    $user->addGroup($userGroup);

                    $category = $userGroup->getCategory();

                    $dataObject->setParent($category);

                    //foreach ($dataObject->getPublishedBlocks() as $publishedBlock) {
                    //    if ($publishedBlock->getPage()) {
                    //        $objectCategory = $publishedBlock->getPage();
                    //        $objectCategory->setParent($category);
                    //    }
                    //}
                    break;
                default:
                    break;
            }
        }

        if ( ! $update) {
            if ($this->sendEmail == true) {
                $this->sendCreateMail($user);
            }

            if ($this->defaultPassword !== null) {
                $password = $this->passwordEncoder->encodePassword($user, $this->defaultPassword);
                $user->setPassword($password);
                $user->setPasswordNeedsToBeReset(true);
                $user->setResetPasswordLink(Uuid::uuid4());
            }
        }

        if ($this->cyrRole && $this->regionRole) {
            if ( ! in_array($this->cyrRole, $user->getGroupsIds())) {
                $groupWithCategoryExists = false;

                foreach ($user->getGroups() as $userGroup) {
                    if ($userGroup->getObject()) {
                        $groupWithCategoryExists = true;
                    }
                }

                $regionGroup = $this->entityManager->getRepository(UserGroup::class)->find($this->regionRole);

                if ($groupWithCategoryExists === true) {
                    if (null !== $regionGroup) {
                        $user->addGroup($regionGroup);
                    }
                } else {
                    foreach ($user->getGroups() as $userGroup) {
                        if ($userGroup->getId() === $this->regionRole) {
                            $user->removeGroup($userGroup);
                        }
                    }
                }
            } else {
                foreach ($user->getGroups() as $userGroup) {
                    if ($userGroup->getId() === $this->regionRole) {
                        $user->removeGroup($userGroup);
                    }
                }
            }
        }

        return $user;
    }


    /**
     * @param DataObject $dataObject
     *
     * @return User|null
     */
    public function __invoke(DataObject $dataObject): ?User
    {
        if (
            ! $this->personTypeId ||
            ! $this->usernameFieldId ||
            ! $this->phoneFieldId ||
            ! $this->emailFieldId ||
            ! $this->fioFieldId ||
            ! $this->regionFieldId
        ) {
            throw new \LogicException('Отсутствует маппинг полей сотрудника и персоны');
        }

        return $this->createUser($dataObject);
    }


    private function parseNamesFromString($fio)
    {
        $parts      = explode(' ', $fio);
        $partsCount = count($parts);

        if ($partsCount === 1) {
            return [
                $parts[0],
                ''
            ];
        } elseif ($partsCount > 1) {
            return [
                $parts[1],
                $parts[0]
            ];
        } else {
            return [
                '',
                ''
            ];
        }
    }


    public function sendCreateMail(User $user)
    {
        $email    = $user->getEmail();
        $username = $user->getUsername();
        $link     = $this->urlGenerator->generate(
            'app_user_reset_password',
            [ 'resetPasswordLink' => $user->getResetPasswordLink() ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $message = new \Swift_Message('Регистрация в базе знаний');
        $message
            ->setBody(
                $this->environment->render(
                    'components/email/user-create.html.twig',
                    [ 'username' => $username, 'link' => $link ]
                ),
                'text/html'
            )
            ->setFrom($this->email)
            ->setTo($email);

        $this->mailer->send($message);
    }


    public function sendResetMail(User $user)
    {
        $link = $this->urlGenerator->generate(
            'app_user_reset_password',
            [ 'resetPasswordLink' => $user->getResetPasswordLink() ],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        if ( ! $user->getTelegramBotId()) {
            return;
        }

        $this->tgclient->sendTgBotMessage([
            'meta' => [
                'Тема' => 'Восстановление пароля'
            ],
            'main' => [
                'Ссылка' => $link
            ]
        ], $user->getTelegramBotId());
        //$message = new \Swift_Message('Сброс пароля');
        //$message
        //    ->setBody(
        //        $this->environment->render(
        //            'components/email/password-reset.html.twig',
        //            [ 'username' => $username, 'link' => $link ]
        //        ),
        //        'text/html'
        //    )
        //    ->setFrom($this->email)
        //    ->setTo($email);
        //
        //$this->mailer->send($message);
    }
}