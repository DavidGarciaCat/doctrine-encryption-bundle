<?php
/**
 * Created by PhpStorm.
 * User: matthewthomas
 * Date: 12/11/2017
 * Time: 19:57
 */

namespace Matt9mg\Encryption\Subscriber;

use Doctrine\Common\EventSubscriber;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Embedded;
use Matt9mg\Encryption\Annotation\Encrypted;
use Matt9mg\Encryption\DependencyInjection\Configuration;
use Matt9mg\Encryption\Encryptor\EncryptorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DoctrineEncryptionSubscriber
 * @package Matt9mg\Encryption\Subscriber
 */
class DoctrineEncryptionSubscriber implements EventSubscriber
{
    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var Reader
     */
    private $annReader;

    /**
     * DoctrineEncryptionSubscriber constructor.
     * @param ContainerInterface $container
     */
    public function __construct(ContainerInterface $container)
    {
        $this->annReader = $container->get('annotation_reader');
        $this->encryptor = $container->get($container->getParameter(Configuration::ROOT . '.' . Configuration::ENCRYPTOR_CLASS));
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function prePersist(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $this->processFields($entity);

    }

    /**
     * @param PreUpdateEventArgs $args
     */
    public function preUpdate(PreUpdateEventArgs $args)
    {
        $entity = $args->getEntity();
        $this->processFields($entity);
    }

    /**
     * @return array
     */
    public function getSubscribedEvents(): array
    {
        return array(
            Events::prePersist,
            Events::preUpdate
        );
    }

    /**
     * Encrypt entity fields
     *
     * @param Object $entity doctrine entity
     *
     * @throws \RuntimeException
     *
     * @return object|null
     */
    public function processFields($entity)
    {
        if (strstr(get_class($entity), "Proxies")) {
            $realClass = ClassUtils::getClass($entity);
        } else {
            $realClass = get_class($entity);
        }

        //Get ReflectionClass of our entity
        $reflectionClass = new \ReflectionClass($realClass);
        $properties = $this->getClassProperties($realClass);

        //Foreach property in the reflection class
        foreach ($properties as $refProperty) {
            if ($this->annReader->getPropertyAnnotation($refProperty, Embedded::class)) {
                $this->handleEmbeddedAnnotation($entity, $refProperty);
                continue;
            }

            $methodName = ucfirst($refProperty->getName());

            /**
             * If contains the Encrypt tag, lets encrypt that property
             */
            if ($this->annReader->getPropertyAnnotation($refProperty, Encrypted::class)) {
                /**
                 * If it is public lets not use the getter/setter
                 */
                if ($refProperty->isPublic()) {
                    $propName = $refProperty->getName();
                    $entity->$propName = $this->encryptor->encrypt($refProperty->getValue());
                } else {
                    //If private or protected check if there is an getter/setter for the property, based on the $methodName
                    if ($reflectionClass->hasMethod($getter = 'get' . $methodName) && $reflectionClass->hasMethod($setter = 'set' . $methodName)) {
                        //Get the information (value) of the property
                        try {
                            $getInformation = $entity->$getter();
                        } catch (\Exception $e) {
                            $getInformation = null;
                        }

                        if (!is_null($getInformation) and !empty($getInformation)) {
                            $start = strlen($this->encryptor->getSuffix()) * -1;
                            if (substr($entity->$getter(), $start) !== $this->encryptor->getSuffix()) {
                                $currentPropValue = $this->encryptor->encrypt($entity->$getter());
                                $entity->$setter($currentPropValue);
                            }
                        }

                    }
                }
            }
        }

        return $entity;
    }

    /**
     * @param $entity
     * @param $embeddedProperty
     */
    private function handleEmbeddedAnnotation($entity, $embeddedProperty)
    {
        $reflectionClass = new \ReflectionClass($entity);
        $propName = $embeddedProperty->getName();
        $methodName = ucfirst($propName);

        $embeddedEntity = null;

        if ($embeddedProperty->isPublic()) {
            $embeddedEntity = $embeddedProperty->getValue();
        } else {
            if ($reflectionClass->hasMethod($getter = 'get' . $methodName) && $reflectionClass->hasMethod($setter = 'set' . $methodName)) {

                //Get the information (value) of the property
                try {
                    $embeddedEntity = $entity->$getter();
                } catch (\Exception $e) {
                    $embeddedEntity = null;
                }
            }
        }
        if ($embeddedEntity) {
            $this->processFields($embeddedEntity);
        }
    }

    /**
     * Recursive function to get an associative array of class properties
     * including inherited ones from extended classes
     *
     * @param string $className Class name
     *
     * @return array
     */
    function getClassProperties(string $className): array
    {

        $reflectionClass = new \ReflectionClass($className);
        $properties = $reflectionClass->getProperties();
        $propertiesArray = array();

        foreach ($properties as $property) {
            $propertyName = $property->getName();
            $propertiesArray[$propertyName] = $property;
        }

        if ($parentClass = $reflectionClass->getParentClass()) {
            $parentPropertiesArray = $this->getClassProperties($parentClass->getName());
            if (count($parentPropertiesArray) > 0)
                $propertiesArray = array_merge($parentPropertiesArray, $propertiesArray);
        }

        return $propertiesArray;
    }
}